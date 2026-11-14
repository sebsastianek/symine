<?php

declare(strict_types=1);

namespace App\Tests\MailHandler;

use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\Message;
use App\Entity\User;
use App\MailHandler\HtmlToText;
use App\MailHandler\MailHandler;
use App\MailHandler\MimePart;
use App\Migration\RedmineMigrator;
use App\Service\CustomFieldService;
use App\Service\Settings;
use App\Tests\Integration\SymineTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Parity fixes for incoming e-mail (expected results from Redmine's test/unit/mail_handler_test.rb, with its
 * fixtures of test/fixtures/mail_handler adapted to the seeded users and projects) and for the plain text settings
 * the mail handler relies on.
 */
final class MailHandlerParityFixesTest extends SymineTestCase
{
    private function handler(): MailHandler
    {
        return $this->service(MailHandler::class);
    }

    private function settings(): Settings
    {
        return $this->service(Settings::class);
    }

    /** Sets the settings and reloads them from the database, like the next request would */
    private function saveSettings(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->settings()->set($name, $value);
        }
        $this->em->flush();
        $this->reloadSettings();
    }

    private function reloadSettings(): void
    {
        $this->em->clear();
        $this->settings()->clearCache();
    }

    /** A Redmine fixture, with the addresses of the seeded users (jsmith@example.net...) */
    private static function fixture(string $name): string
    {
        $raw = file_get_contents(\dirname(__DIR__, 2).'/reference/redmine/test/fixtures/mail_handler/'.$name);
        self::assertNotFalse($raw);

        return str_ireplace('somenet.foo', 'example.net', $raw);
    }

    private static function mail(string $from, string $subject, string $body, array $headers = []): string
    {
        $h = "From: $from\r\nTo: redmine@example.net\r\nSubject: $subject\r\nMessage-ID: <".uniqid('', true)."@example.net>\r\nDate: Sat, 07 Nov 2026 10:00:00 +0100\r\nMIME-Version: 1.0\r\n";
        foreach ($headers as $k => $v) {
            $h .= "$k: $v\r\n";
        }
        if (!isset($headers['Content-Type'])) {
            $h .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
        }

        return $h."\r\n".str_replace("\n", "\r\n", $body);
    }

    // ------------------------------------------------------------------------------------------
    // Settings stored as plain text (Setting#value only YAML-decodes settings declared "serialized")
    // ------------------------------------------------------------------------------------------

    public static function plainValues(): iterable
    {
        yield 'three dashes' => ['---'];
        yield 'reply above marker' => ['--- Reply above. Do not remove this line. ---'];
        yield 'dashes then text' => ["---\nSent by the tracker"];
        yield 'looks like a YAML list' => ["---\n- one\n- two"];
        yield 'looks like a YAML hash' => ["---\nsignature: The team"];
        yield 'looks like an empty YAML list' => ['--- []'];
        yield 'several delimiters' => ["---\r\nBREAK\r\n-- "];
        yield 'looks like a JSON list' => ['["a", "b"]'];
        yield 'looks like a JSON object' => ['{"a": 1}'];
        yield 'leading blank line' => ["\n--- x"];
    }

    #[DataProvider('plainValues')]
    public function testPlainSettingsSurviveSaveAndReload(string $value): void
    {
        foreach (['mail_handler_body_delimiters', 'emails_footer', 'emails_header', 'welcome_text', 'app_title'] as $name) {
            $this->saveSettings([$name => $value]);
            self::assertSame($value, $this->settings()->get($name), $name);
            self::assertSame($value, $this->settings()->string($name), $name);
            self::assertSame($value, $this->em->getConnection()->fetchOne('SELECT value FROM settings WHERE name = ?', [$name]), 'stored unchanged');
        }
        // saving the reloaded values again (the settings form posts every field back) changes nothing
        $this->saveSettings(['emails_footer' => $this->settings()->string('emails_footer'), 'welcome_text' => $this->settings()->string('welcome_text')]);
        self::assertSame($value, $this->settings()->string('emails_footer'));
        self::assertSame($value, $this->settings()->string('welcome_text'));
    }

    /** Every setting Redmine declares "serialized: true" still decodes, from Rails YAML and from JSON */
    public function testSerializedSettingsStillDecode(): void
    {
        $definitions = $this->settings()->definitions();
        $serialized = array_keys(array_filter($definitions, static fn (array $d) => $d['serialized']));
        // config/settings.yml of the reference Redmine
        $yaml = (string) file_get_contents(\dirname(__DIR__, 2).'/reference/redmine/config/settings.yml');
        preg_match_all('/^(\w+):\n(?:  .*\n)*?  serialized: true/m', $yaml, $m);
        $expected = $m[1];
        self::assertGreaterThan(10, \count($expected));
        sort($expected);
        $actual = array_values(array_filter($serialized, static fn (string $n) => !str_starts_with($n, 'plugin_')));
        sort($actual);
        self::assertSame($expected, $actual, 'same serialized settings as Redmine');

        $conn = $this->em->getConnection();
        $raw = [
            'notified_events' => ["---\n- issue_added\n- issue_updated\n", ['issue_added', 'issue_updated']],
            'default_projects_modules' => ['["issue_tracking","wiki"]', ['issue_tracking', 'wiki']],
            'enabled_scm' => ["---\n- Git\n- Subversion\n", ['Git', 'Subversion']],
            'non_working_week_days' => ["---\n- '6'\n- '7'\n", ['6', '7']],
            'default_projects_tracker_ids' => ["--- []\n", []],
            'issue_list_default_columns' => ["---\n- tracker\n- :status\n", ['tracker', 'status']],
            'commit_update_keywords' => [
                "---\n- !ruby/hash:ActiveSupport::HashWithIndifferentAccess\n  keywords: fixes,closes\n  status_id: '5'\n  done_ratio: '100'\n",
                [['keywords' => 'fixes,closes', 'status_id' => '5', 'done_ratio' => '100']],
            ],
            'time_entry_list_defaults' => [
                "---\n:column_names:\n- spent_on\n- hours\n:totalable_names:\n- hours\n",
                ['column_names' => ['spent_on', 'hours'], 'totalable_names' => ['hours']],
            ],
            'timelog_required_fields' => ['["comments"]', ['comments']],
            'password_required_char_classes' => ["---\n- uppercase\n", ['uppercase']],
        ];
        foreach ($raw as $name => [$value]) {
            self::assertContains($name, $serialized);
            $conn->executeStatement('DELETE FROM settings WHERE name = ?', [$name]);
            $conn->executeStatement('INSERT INTO settings (name, value, updated_on) VALUES (?, ?, CURRENT_TIMESTAMP)', [$name, $value]);
        }
        $this->reloadSettings();
        foreach ($raw as $name => [, $decoded]) {
            self::assertSame($decoded, $this->settings()->get($name), $name);
        }
        // a serialized setting saved by the application is JSON and reads back as the same array
        $this->saveSettings(['notified_events' => ['issue_added', 'news_added']]);
        self::assertSame(['issue_added', 'news_added'], $this->settings()->array('notified_events'));
    }

    /** Values migrated from a Redmine database: YAML only for the serialized settings, plain strings are kept as they are */
    public function testSettingsMigratedFromRedmine(): void
    {
        $dir = \dirname(__DIR__, 2);
        $sourcePath = $dir.'/var/test'.($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '').'-parity-src.db';
        copy($dir.'/tests/fixtures/redmine-seeded.db', $sourcePath);
        $source = RedmineMigrator::connect('sqlite:///'.$sourcePath);
        try {
            $rows = [
                'mail_handler_body_delimiters' => "---\r\n--- Reply above. Do not remove this line. ---",
                'emails_footer' => "---\nsignature: The team",
                'emails_header' => "---\n- not a list",
                'welcome_text' => '--- hello ---',
                'notified_events' => "---\n- issue_added\n- issue_updated\n",
                'commit_update_keywords' => "---\n- keywords: fixes\n  status_id: '5'\n",
            ];
            foreach ($rows as $name => $value) {
                $source->executeStatement('DELETE FROM settings WHERE name = ?', [$name]);
                $source->executeStatement('INSERT INTO settings (name, value, updated_on) VALUES (?, ?, CURRENT_TIMESTAMP)', [$name, $value]);
            }
            $this->service(RedmineMigrator::class)->migrate($source, true, 500, ['settings']);
        } finally {
            $source->close();
            @unlink($sourcePath);
        }
        $this->reloadSettings();
        foreach (['mail_handler_body_delimiters', 'emails_footer', 'emails_header', 'welcome_text'] as $name) {
            self::assertSame($rows[$name], $this->em->getConnection()->fetchOne('SELECT value FROM settings WHERE name = ?', [$name]), "$name is copied as it is");
            self::assertSame($rows[$name], $this->settings()->string($name), $name);
        }
        self::assertSame(['issue_added', 'issue_updated'], $this->settings()->get('notified_events'));
        self::assertSame([['keywords' => 'fixes', 'status_id' => '5']], $this->settings()->get('commit_update_keywords'));
    }

    // ------------------------------------------------------------------------------------------
    // Body delimiters (mail_handler_test.rb "truncate emails ...")
    // ------------------------------------------------------------------------------------------

    public function testTruncateEmailsWithASingleString(): void
    {
        $this->saveSettings(['mail_handler_body_delimiters' => '---']);
        $issue = $this->handler()->receive(self::fixture('ticket_on_given_project.eml'), ['issue' => ['project' => 'ecommerce']]);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        $description = (string) $issue->description;
        self::assertStringContainsString('This paragraph is before delimiters', $description);
        self::assertStringContainsString('--- This line starts with a delimiter', $description);
        self::assertDoesNotMatchRegularExpression('/^---\x{00A0}$/mu', $description);
        self::assertStringNotContainsString('This paragraph is after the delimiter', $description);
    }

    public function testTruncateEmailsWithMultipleStrings(): void
    {
        $this->saveSettings(['mail_handler_body_delimiters' => "---\nBREAK"]);
        $issue = $this->handler()->receive(self::fixture('ticket_on_given_project.eml'), ['issue' => ['project' => 'ecommerce']]);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        $description = (string) $issue->description;
        self::assertStringContainsString('This paragraph is before delimiters', $description);
        self::assertStringNotContainsString('BREAK', $description);
        self::assertStringNotContainsString('This paragraph is between delimiters', $description);
        self::assertDoesNotMatchRegularExpression('/^---$/m', $description);
        self::assertStringNotContainsString('This paragraph is after the delimiter', $description);
    }

    public function testTruncateEmailsWithQuotedReplyAbove(): void
    {
        $delimiter = '--- Reply above. Do not remove this line. ---';
        $this->saveSettings(['mail_handler_body_delimiters' => $delimiter]);
        $raw = self::mail('jsmith@example.net', 'Re: [eCommerce - Feature #15] Wishlist sharing', "An update to the issue by the sender.\n\n> $delimiter\n> \n> Looks like the JSON api for projects was missed.\n> > $delimiter\n> > older reply\n");
        $journal = $this->handler()->receive($raw);
        self::assertInstanceOf(Journal::class, $journal, (string) $this->handler()->lastError());
        self::assertNotNull($journal->id);
        self::assertStringContainsString('An update to the issue by the sender.', (string) $journal->notes);
        self::assertStringNotContainsString($delimiter, (string) $journal->notes);
        self::assertStringNotContainsString('Looks like the JSON api for projects was missed.', (string) $journal->notes);
    }

    // ------------------------------------------------------------------------------------------
    // Issue creation
    // ------------------------------------------------------------------------------------------

    /** test_add_issue_should_set_default_start_date / test_add_issue_should_set_default_due_date */
    public function testDefaultStartAndDueDates(): void
    {
        $this->saveSettings(['default_issue_start_date_to_creation_date' => '1', 'default_issue_due_date_offset' => '5']);
        $issue = $this->handler()->receive(self::fixture('ticket_with_cc.eml'), ['issue' => ['project' => 'ecommerce']]);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        $today = new \DateTimeImmutable('today');
        self::assertSame($today->format('Y-m-d'), $issue->startDate?->format('Y-m-d'));
        self::assertSame($today->modify('+5 days')->format('Y-m-d'), $issue->dueDate?->format('Y-m-d'));

        // no offset: no default due date; a due date keyword wins over the offset
        $this->saveSettings(['default_issue_due_date_offset' => '']);
        $issue = $this->handler()->receive(self::fixture('ticket_with_cc.eml'), ['issue' => ['project' => 'ecommerce']]);
        self::assertInstanceOf(Issue::class, $issue);
        self::assertNull($issue->dueDate);
        $this->saveSettings(['default_issue_due_date_offset' => '5']);
        $issue = $this->handler()->receive(self::mail('jsmith@example.net', 'With a due date', "Body\n\nDue date: 2030-01-31\n"), ['issue' => ['project' => 'ecommerce'], 'allow_override' => 'due_date']);
        self::assertInstanceOf(Issue::class, $issue);
        self::assertSame('2030-01-31', $issue->dueDate?->format('Y-m-d'));
    }

    /** test_invalid_utf8: invalid bytes are replaced with "?", the text is kept */
    public function testInvalidUtf8(): void
    {
        $issue = $this->handler()->receive(self::fixture('invalid_utf8.eml'), ['issue' => ['project' => 'ecommerce']]);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        self::assertSame('Здравствуйте?', $issue->description);
        self::assertSame('Grüße ?', MimePart::toUtf8("Gr\xC3\xBC\xC3\x9Fe \xFF", 'UTF-8'));
        // other charsets are converted as before
        self::assertSame('André', MimePart::toUtf8("Andr\xE9", 'ISO-8859-1'));
    }

    /** Issue#estimated_hours= accepts the String#to_hours formats ("estimated hours: 2h30") */
    public function testEstimatedHoursKeywordFormats(): void
    {
        foreach (['2h30' => 2.5, '1:45' => 1.75, '90m' => 1.5, '2.5' => 2.5, '3h' => 3.0] as $text => $expected) {
            $issue = $this->handler()->receive(self::mail('jsmith@example.net', 'Estimate '.$text, "Body\n\nEstimated hours: $text\n"), ['issue' => ['project' => 'ecommerce'], 'allow_override' => 'estimated_hours']);
            self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
            $this->em->clear();
            self::assertEqualsWithDelta($expected, (float) $this->em->find(Issue::class, $issue->id)->estimatedHours, 0.001, $text);
        }
    }

    /** FieldFormat#parse_keyword: values of multi-valued lists may contain commas */
    public function testMultiValuedKeywordWithCommasInValues(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE custom_fields SET possible_values = ? WHERE id = 2', ['["Linux","macOS","Windows","Solaris, SPARC"]']);
        $this->em->clear();
        $issue = $this->handler()->receive(self::mail('jsmith@example.net', 'Platforms', "Body\n\nPlatforms: linux, Solaris, SPARC, unknown, Windows\n"), ['issue' => ['project' => 'ecommerce', 'tracker' => 'Bug'], 'allow_override' => 'all']);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        self::assertSame(['Linux', 'Solaris, SPARC', 'Windows'], $this->service(CustomFieldService::class)->values('Issue', $issue->id)[2]);
    }

    // ------------------------------------------------------------------------------------------
    // Unknown users
    // ------------------------------------------------------------------------------------------

    /** create_user_from_email: the account is only created when the user is valid (denied / not allowed e-mail domains) */
    public function testUnknownUserIsNotCreatedForDeniedDomains(): void
    {
        $count = fn (string $table) => (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM $table");
        $users = $count('users');
        $issues = $count('issues');
        $options = ['issue' => ['project' => 'ecommerce'], 'unknown_user' => 'create', 'no_permission_check' => '1', 'no_account_notice' => '1'];

        $this->saveSettings(['email_domains_denied' => 'blocked.example, .evil.example']);
        self::assertFalse($this->handler()->receive(self::mail('Bad Guy <guy@blocked.example>', 'From a denied domain', 'Hello'), $options));
        self::assertStringContainsString('could not create account', (string) $this->handler()->lastError());
        self::assertFalse($this->handler()->receive(self::mail('guy@mail.evil.example', 'From a denied subdomain', 'Hello'), $options));
        self::assertSame($users, $count('users'));
        self::assertSame($issues, $count('issues'));

        $this->saveSettings(['email_domains_denied' => '', 'email_domains_allowed' => 'example.net']);
        self::assertFalse($this->handler()->receive(self::mail('guy@elsewhere.example', 'Not an allowed domain', 'Hello'), $options));
        self::assertSame($users, $count('users'));

        // test_add_issue_by_created_user: valid senders still get an active account
        $issue = $this->handler()->receive(str_ireplace('john.doe@example.net', 'john.doe@example.net', self::fixture('ticket_by_unknown_user.eml')), $options);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        self::assertSame($users + 1, $count('users'));
        self::assertTrue($issue->author->isActive());
        self::assertSame('john.doe@example.net', $issue->author->getMail());
        self::assertSame('John', $issue->author->firstname);
        self::assertSame('Doe', $issue->author->lastname);
    }

    /** add_user_to_group -> Group.named: case-insensitive */
    public function testDefaultGroupIsMatchedCaseInsensitively(): void
    {
        $issue = $this->handler()->receive(self::mail('New User <new.user@unknown.example>', 'Group', 'Hello'), [
            'issue' => ['project' => 'ecommerce'], 'unknown_user' => 'create', 'no_permission_check' => '1', 'no_account_notice' => '1', 'default_group' => 'developers, nope',
        ]);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['login' => 'new.user@unknown.example']);
        self::assertNotNull($user);
        self::assertContains(10, $user->getGroupIds());
    }

    // ------------------------------------------------------------------------------------------
    // Replies
    // ------------------------------------------------------------------------------------------

    /** receive_issue_reply returns the journal even when nothing was saved: the e-mail is processed, not rejected */
    public function testEmptyReplyIsProcessed(): void
    {
        $journals = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM journals');
        $result = $this->handler()->receive(self::mail('jsmith@example.net', 'Re: [eCommerce - Feature #15] Wishlist sharing', ''));
        self::assertInstanceOf(Journal::class, $result, (string) $this->handler()->lastError());
        self::assertNull($result->id, 'nothing to save');
        self::assertSame(15, $result->issue->id);
        self::assertSame($journals, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM journals'));

        // "message.children << reply": a reply without content is not saved but the e-mail is processed
        $messages = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messages');
        $result = $this->handler()->receive(self::mail('jsmith@example.net', 'Re: [eCommerce - General - msg1] Hello', ''));
        self::assertInstanceOf(Message::class, $result, (string) $this->handler()->lastError());
        self::assertNull($result->id);
        self::assertSame($messages, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messages'));
    }

    /** rake redmine:email:read always succeeds: an ignored e-mail must not be deferred or bounced by the MTA */
    public function testReceiveEmailCommandExitCodeForIgnoredMail(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'eml');
        $tester = new CommandTester((new Application(self::$kernel))->find('symine:receive-email'));
        try {
            file_put_contents($file, self::mail('nobody@unknown.example', 'From an unknown user', 'Hello'));
            $tester->execute(['--file' => $file, '--project' => 'ecommerce']);
            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('rejected or ignored', $tester->getDisplay());

            file_put_contents($file, self::mail('jsmith@example.net', 'Auto reply', 'Out of office', ['Auto-Submitted' => 'auto-replied']));
            $tester->execute(['--file' => $file, '--project' => 'ecommerce']);
            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

            file_put_contents($file, self::mail('jsmith@example.net', 'Processed', 'Hello'));
            $tester->execute(['--file' => $file, '--project' => 'ecommerce']);
            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringContainsString('Processed: Issue #', $tester->getDisplay());
        } finally {
            @unlink($file);
        }
    }

    // ------------------------------------------------------------------------------------------
    // HTML parts and the HTTP endpoint
    // ------------------------------------------------------------------------------------------

    /** A leading byte order mark of an HTML part is not content */
    public function testHtmlPartStartingWithAByteOrderMark(): void
    {
        self::assertSame('Hello world', HtmlToText::convert("\xEF\xBB\xBF<!DOCTYPE html><html><body><p>Hello world</p></body></html>"));
        $boundary = 'b1';
        $raw = self::mail('jsmith@example.net', 'HTML only', "--$boundary\nContent-Type: text/html; charset=UTF-8\nContent-Transfer-Encoding: quoted-printable\n\n=EF=BB=BF<html><body><p>First line</p><p>Second line</p></body></html>\n--$boundary--\n", ['Content-Type' => "multipart/alternative; boundary=\"$boundary\""]);
        $issue = $this->handler()->receive($raw, ['issue' => ['project' => 'ecommerce']]);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        self::assertStringStartsWith('First line', (string) $issue->description);
    }

    /** MailHandlerController: Rails reads the permitted parameters from a JSON body as well */
    public function testEndpointAcceptsAJsonBody(): void
    {
        $this->saveSettings(['mail_handler_api_enabled' => '1', 'mail_handler_api_key' => 'secret-key']);
        $client = self::getContainer()->get('test.client');
        $post = static function (array $payload) use ($client): int {
            $client->request('POST', '/mail_handler', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($payload));

            return $client->getResponse()->getStatusCode();
        };
        $raw = self::mail('jsmith@example.net', 'Sent as JSON', "Body\n\nTracker: Support\n");
        self::assertSame(403, $post(['key' => 'wrong', 'email' => $raw, 'issue' => ['project' => 'ecommerce']]));
        self::assertSame(422, $post(['key' => 'secret-key', 'email' => $raw]));
        self::assertSame(201, $post(['key' => 'secret-key', 'email' => $raw, 'issue' => ['project' => 'ecommerce', 'is_private' => '1'], 'allow_override' => 'tracker', 'no_permission_check' => 1]));
        $issue = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->getRepository(Issue::class)->findOneBy(['subject' => 'Sent as JSON']);
        self::assertNotNull($issue);
        self::assertSame('Support', $issue->tracker->name);
        self::assertTrue($issue->isPrivate);
        // form posts (rdm-mailhandler.rb) are unchanged
        $client->request('POST', '/mail_handler', ['key' => 'secret-key', 'email' => self::mail('jsmith@example.net', 'Sent as a form', 'Body'), 'issue' => ['project' => 'ecommerce']]);
        self::assertSame(201, $client->getResponse()->getStatusCode());
    }

    // ------------------------------------------------------------------------------------------
    // Attachments (second round): Mail::Part#attachment? and Mail::AttachmentsList of the Mail gem 2.9.0
    // ------------------------------------------------------------------------------------------

    /**
     * Each case was run through the Mail gem (mail 2.9.0): [raw message, m.attachments.map(&:filename),
     * text/plain parts that are not attachments, m.attachment?].
     */
    public static function mailGemCases(): iterable
    {
        yield 'disp filename' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=\"a.bin\"\r\n\r\nDATA\r\n--BOUND--\r\n", ["a.bin"], ["Body text"], false];
        yield 'ctype name only' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: image/png; name=\"n.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\nQUJD\r\n--BOUND--\r\n", ["n.png"], ["Body text"], false];
        yield 'disp attachment no filename' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment\r\n\r\nDATA\r\n--BOUND--\r\n", [], ["Body text"], false];
        yield 'inline image cid no filename' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/related; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: image/png\r\nContent-ID: <img1@x>\r\nContent-Disposition: inline\r\nContent-Transfer-Encoding: base64\r\n\r\nQUJD\r\n--BOUND--\r\n", [], ["Body text"], false];
        yield 'inline image cid with filename' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/related; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: image/png\r\nContent-ID: <img1@x>\r\nContent-Disposition: inline; filename=\"i.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\nQUJD\r\n--BOUND--\r\n", ["i.png"], ["Body text"], false];
        yield 'non-text no name' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: application/octet-stream\r\n\r\nDATA\r\n--BOUND--\r\n", [], ["Body text"], false];
        yield 'content-location' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: image/gif\r\nContent-Location: image001.gif\r\n\r\nGIF\r\n--BOUND--\r\n", ["image001.gif"], ["Body text"], false];
        yield 'content-location url' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: image/gif\r\nContent-Location: http://example.com/img/a.gif\r\n\r\nGIF\r\n--BOUND--\r\n", [], ["Body text"], false];
        yield 'content-location quoted' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: image/gif\r\nContent-Location: \"my image.gif\"\r\n\r\nGIF\r\n--BOUND--\r\n", ["my image.gif"], ["Body text"], false];
        yield 'text attachment' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8; name=\"notes.txt\"\r\nContent-Disposition: attachment; filename=\"notes.txt\"\r\n\r\nNotes\r\n--BOUND--\r\n", ["notes.txt"], ["Body text"], false];
        yield 'text inline with filename' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Disposition: inline; filename=\"inline.txt\"\r\n\r\nInline text\r\n--BOUND--\r\n", ["inline.txt"], ["Body text"], false];
        yield 'disp name param' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; name=\"d.pdf\"\r\n\r\nDATA\r\n--BOUND--\r\n", ["d.pdf"], ["Body text"], false];
        yield 'ctype filename param' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: application/pdf; filename=\"cf.pdf\"\r\n\r\nDATA\r\n--BOUND--\r\n", ["cf.pdf"], ["Body text"], false];
        yield 'disp without filename, ctype name' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: application/pdf; name=\"ct.pdf\"\r\nContent-Disposition: attachment\r\n\r\nDATA\r\n--BOUND--\r\n", ["ct.pdf"], ["Body text"], false];
        yield 'empty filename' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=\"\"\r\n\r\nDATA\r\n--BOUND--\r\n", [""], ["Body text"], false];
        yield 'rfc822 no name' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: message/rfc822\r\n\r\nFrom: i@b.c\r\nSubject: inner\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"IN\"\r\n\r\n--IN\r\nContent-Type: text/plain\r\n\r\nInner body\r\n--IN\r\nContent-Type: application/pdf; name=\"inner.pdf\"\r\n\r\nPDF\r\n--IN--\r\n\r\n--BOUND--\r\n", ["inner.pdf"], ["Body text"], false];
        yield 'rfc822 with name' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: message/rfc822; name=\"fwd.eml\"\r\nContent-Disposition: attachment; filename=\"fwd.eml\"\r\n\r\nFrom: i@b.c\r\nSubject: inner\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"IN\"\r\n\r\n--IN\r\nContent-Type: text/plain\r\n\r\nInner body\r\n--IN\r\nContent-Type: application/pdf; name=\"inner.pdf\"\r\n\r\nPDF\r\n--IN--\r\n\r\n--BOUND--\r\n", ["fwd.eml", "inner.pdf"], ["Body text"], false];
        yield 'rfc822 base64 with name' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: message/rfc822; name=\"fwd.eml\"\r\nContent-Transfer-Encoding: base64\r\n\r\nRnJvbTogaUBiLmMNClN1YmplY3Q6IGlubmVyDQpNSU1FLVZlcnNpb246IDEu\nMA0KQ29udGVudC1UeXBlOiBtdWx0aXBhcnQvbWl4ZWQ7IGJvdW5kYXJ5PSJJ\nTiINCg0KLS1JTg0KQ29udGVudC1UeXBlOiB0ZXh0L3BsYWluDQoNCklubmVy\nIGJvZHkNCi0tSU4NCkNvbnRlbnQtVHlwZTogYXBwbGljYXRpb24vcGRmOyBu\nYW1lPSJpbm5lci5wZGYiDQoNClBERg0KLS1JTi0tDQo=\n\r\n--BOUND--\r\n", ["fwd.eml"], ["Body text"], false];
        yield 'nested multipart' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: multipart/alternative; boundary=\"ALT\"\r\n\r\n--ALT\r\nContent-Type: text/plain\r\n\r\nAlt text\r\n--ALT\r\nContent-Type: text/html\r\n\r\n<p>Alt</p>\r\n--ALT--\r\n\r\n--BOUND\r\nContent-Type: multipart/mixed; boundary=\"M2\"\r\n\r\n--M2\r\nContent-Type: application/zip; name=\"deep.zip\"\r\n\r\nZIP\r\n--M2--\r\n\r\n--BOUND--\r\n", ["deep.zip"], ["Alt text"], false];
        yield 'single part attachment' => ["From: a@b.c\r\nSubject: x\r\nContent-Type: text/plain; name=\"top.txt\"\r\nContent-Disposition: attachment; filename=\"top.txt\"\r\n\r\nTop body", [], [], true];
        yield 'rfc2231' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename*=UTF-8''%E3%83%86%E3%82%B9%E3%83%88.pdf\r\n\r\nDATA\r\n--BOUND--\r\n", ["テスト.pdf"], ["Body text"], false];
        yield 'rfc2047 name' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: application/pdf; name=\"=?UTF-8?B?44OG44K544OILnBkZg==?=\"\r\n\r\nDATA\r\n--BOUND--\r\n", ["テスト.pdf"], ["Body text"], false];
        yield 'delivery-status' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/report; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: message/delivery-status\r\n\r\nAction: failed\r\n--BOUND--\r\n", [], ["Body text"], false];
        yield 'empty body with name' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: application/pdf; name=\"empty.pdf\"\r\n\r\n\r\n--BOUND--\r\n", ["empty.pdf"], ["Body text"], false];
        yield 'calendar no name' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: text/calendar; method=REQUEST\r\n\r\nBEGIN:VCALENDAR\r\n--BOUND--\r\n", [], ["Body text"], false];
        yield 'smime' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/signed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: application/pkcs7-signature; name=\"smime.p7s\"\r\nContent-Disposition: attachment; filename=\"smime.p7s\"\r\nContent-Transfer-Encoding: base64\r\n\r\nQUJD\r\n--BOUND--\r\n", ["smime.p7s"], ["Body text"], false];
        yield 'location a b.gif' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: image/gif\r\nContent-Location: a b.gif\r\n\r\nGIF\r\n--BOUND--\r\n", [], ["Body text"], false];
        yield 'location http://x/a.gif' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: image/gif\r\nContent-Location: http://x/a.gif\r\n\r\nGIF\r\n--BOUND--\r\n", [], ["Body text"], false];
        yield 'location a/b.gif' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: image/gif\r\nContent-Location: a/b.gif\r\n\r\nGIF\r\n--BOUND--\r\n", [], ["Body text"], false];
        yield 'location foo=bar.gif' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: image/gif\r\nContent-Location: foo=bar.gif\r\n\r\nGIF\r\n--BOUND--\r\n", ["foo=bar.gif"], ["Body text"], false];
        yield 'location x@y.gif' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: image/gif\r\nContent-Location: x@y.gif\r\n\r\nGIF\r\n--BOUND--\r\n", [], ["Body text"], false];
        yield 'location a.gif (comment)' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: image/gif\r\nContent-Location: a.gif (comment)\r\n\r\nGIF\r\n--BOUND--\r\n", ["a.gif"], ["Body text"], false];
        yield 'location file,1.gif' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: image/gif\r\nContent-Location: file,1.gif\r\n\r\nGIF\r\n--BOUND--\r\n", ["file,1.gif"], ["Body text"], false];
        yield 'location a:b' => ["From: a@b.c\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUND\"\r\n\r\n--BOUND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody text\r\n--BOUND\r\nContent-Type: image/gif\r\nContent-Location: a:b\r\n\r\nGIF\r\n--BOUND--\r\n", [], ["Body text"], false];
    }

    /**
     * @param list<string> $attachments
     * @param list<string> $texts
     */
    #[DataProvider('mailGemCases')]
    public function testPartsAreAttachmentsLikeInTheMailGem(string $raw, array $attachments, array $texts, bool $top): void
    {
        $mail = MimePart::parse($raw);
        self::assertSame($attachments, array_map(static fn (MimePart $p) => (string) $p->filename(), $mail->attachments()));
        $plain = array_values(array_filter($mail->allParts(), static fn (MimePart $p) => 'text/plain' === $p->mimeType() && !$p->isAttachment()));
        self::assertSame($texts, array_map(static fn (MimePart $p) => trim($p->text()), $plain));
        self::assertSame($top, $mail->isAttachment());
    }

    /** @param list<string> $parts raw MIME parts (headers, blank line, body) */
    private static function multipart(string $from, string $subject, array $parts, string $type = 'mixed', array $headers = []): string
    {
        $body = '';
        foreach ($parts as $part) {
            $body .= "--BOUND\n".$part."\n";
        }

        return self::mail($from, $subject, $body."--BOUND--\n", $headers + ['Content-Type' => "multipart/$type; boundary=\"BOUND\""]);
    }

    /** @return list<string> file names attached to the issue, in order */
    private function attachmentNames(int $issueId): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn("SELECT filename FROM attachments WHERE container_type = 'Issue' AND container_id = ? ORDER BY id", [$issueId]));
    }

    /** add_attachments / accept_attachment?: only named parts, minus mail_handler_excluded_filenames, minus empty files */
    public function testOnlyNamedPartsBecomeAttachments(): void
    {
        $this->saveSettings(['mail_handler_body_delimiters' => '---', 'mail_handler_excluded_filenames' => '*.vcf, companylogo.gif', 'mail_handler_enable_regex_excluded_filenames' => '0']);
        $inner = "From: i@example.net\nSubject: inner\nMIME-Version: 1.0\nContent-Type: multipart/mixed; boundary=\"IN\"\n\n--IN\nContent-Type: text/plain\n\nInner body\n--IN\nContent-Type: application/pdf; name=\"inner.pdf\"\n\nPDF\n--IN--\n";
        $parts = [
            "Content-Type: text/plain; charset=UTF-8\n\nKept text\n\n---\nTruncated text",
            "Content-Type: application/pdf; name=\"report.pdf\"\nContent-Disposition: attachment; filename=\"report.pdf\"\nContent-Transfer-Encoding: base64\n\nUERGIGRhdGE=",
            "Content-Type: image/png\nContent-ID: <logo@example.net>\nContent-Disposition: inline\nContent-Transfer-Encoding: base64\n\nUE5H",
            "Content-Type: image/png\nContent-ID: <shot@example.net>\nContent-Disposition: inline; filename=\"screenshot.png\"\nContent-Transfer-Encoding: base64\n\nUE5H",
            "Content-Type: application/octet-stream\nContent-Disposition: attachment\n\nno name",
            "Content-Type: image/gif\nContent-Location: image001.gif\n\nGIF89a",
            "Content-Type: image/gif\nContent-Location: http://example.net/remote.gif\n\nGIF89a",
            "Content-Type: text/x-vcard; name=\"contact.vcf\"\n\nBEGIN:VCARD",
            "Content-Type: image/gif; name=\"CompanyLogo.GIF\"\n\nGIF89a",
            "Content-Type: application/pdf; name=\"empty.pdf\"\n\n",
            "Content-Type: text/plain; charset=UTF-8; name=\"notes.txt\"\nContent-Disposition: attachment; filename=\"notes.txt\"\n\nNot part of the description",
            "Content-Type: message/rfc822\n\n".$inner,
        ];
        $issue = $this->handler()->receive(self::multipart('jsmith@example.net', 'With parts', $parts), ['issue' => ['project' => 'ecommerce']]);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        self::assertSame(['report.pdf', 'screenshot.png', 'image001.gif', 'notes.txt', 'inner.pdf'], $this->attachmentNames((int) $issue->id));
        self::assertSame('Kept text', trim((string) $issue->description), 'the body is truncated at the delimiter; named text parts are not part of it');
        self::assertSame('PDF data', file_get_contents($this->service(\App\Service\AttachmentService::class)->fullPath(
            $this->em->getRepository(\App\Entity\Attachment::class)->findOneBy(['containerType' => 'Issue', 'containerId' => $issue->id, 'filename' => 'report.pdf'])
        )));

        // regular expressions instead of wildcards
        $this->saveSettings(['mail_handler_excluded_filenames' => 'report\.(pdf|doc), image\d+\.gif', 'mail_handler_enable_regex_excluded_filenames' => '1']);
        $issue = $this->handler()->receive(self::multipart('jsmith@example.net', 'With parts again', $parts), ['issue' => ['project' => 'ecommerce']]);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        self::assertSame(['screenshot.png', 'contact.vcf', 'CompanyLogo.GIF', 'notes.txt', 'inner.pdf'], $this->attachmentNames((int) $issue->id));

        // attachment_max_size and the allowed/denied extensions still apply
        $this->saveSettings(['mail_handler_excluded_filenames' => '', 'attachment_max_size' => '1', 'attachment_extensions_denied' => 'gif']);
        $big = "Content-Type: application/octet-stream; name=\"big.bin\"\nContent-Transfer-Encoding: base64\n\n".chunk_split(base64_encode(str_repeat('x', 2048)), 76, "\n");
        $issue = $this->handler()->receive(self::multipart('jsmith@example.net', 'Limits', [$parts[0], $parts[1], $parts[5], $big]), ['issue' => ['project' => 'ecommerce']]);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        self::assertSame(['report.pdf'], $this->attachmentNames((int) $issue->id));
    }

    /** A message that is itself a named file has no body (email_parts_to_text rejects attachment parts) */
    public function testSinglePartMessageWithAFilenameHasNoBody(): void
    {
        $raw = self::mail('jsmith@example.net', 'Single part', 'Top body', ['Content-Type' => 'text/plain; charset=UTF-8; name="top.txt"', 'Content-Disposition' => 'attachment; filename="top.txt"']);
        $issue = $this->handler()->receive($raw, ['issue' => ['project' => 'ecommerce']]);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        self::assertSame('', trim((string) $issue->description));
        self::assertSame([], $this->attachmentNames((int) $issue->id));
    }

    // ------------------------------------------------------------------------------------------
    // Notifications triggered by incoming e-mails (second round)
    // ------------------------------------------------------------------------------------------

    /** @return list<string> "recipient: subject" of the captured e-mails, sorted */
    private function sent(): array
    {
        $out = [];
        foreach (self::getMailerMessages() as $m) {
            if ($m instanceof \Symfony\Component\Mime\Email) {
                $out[] = $m->getTo()[0]->getAddress().': '.$m->getSubject();
            }
        }
        sort($out);

        return $out;
    }

    private function clearSent(): void
    {
        self::getContainer()->get('mailer.message_logger_listener')->reset();
    }

    /** test_add_issue_should_send_notification / test_update_issue_should_send_email_notification */
    public function testIssuesCreatedAndUpdatedByMailAreNotified(): void
    {
        // To/Cc addresses of users become watchers before the notification is sent
        $raw = self::mail('jsmith@example.net', 'From mail', "Body\n\nAssigned to: dlopper\n", ['Cc' => 'rhill@example.net, nobody@unknown.example']);
        $issue = $this->handler()->receive($raw, ['issue' => ['project' => 'ecommerce'], 'allow_override' => 'assigned_to']);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        $subject = '[eCommerce - Bug #'.$issue->id.'] From mail';
        // jsmith (author) does not want to be notified of his own changes
        $added = '[eCommerce - Bug #'.$issue->id.'] (New) From mail';
        self::assertSame(['dlopper@example.net: '.$added, 'rhill@example.net: '.$added], $this->sent());
        $mail = self::getMailerMessages()[0];
        self::assertSame('jsmith', $mail->getHeaders()->get('X-Redmine-Sender')?->getBodyAsString());
        self::assertStringContainsString('has been reported by John Smith', (string) $mail->getTextBody());

        $this->clearSent();
        $journal = $this->handler()->receive(self::mail('rhill@example.net', 'Re: '.$subject, 'A reply by mail'));
        self::assertInstanceOf(Journal::class, $journal, (string) $this->handler()->lastError());
        // dlopper (assignee), jsmith (author, watching his issue); rhill wrote the note
        self::assertSame(['dlopper@example.net: '.$subject, 'jsmith@example.net: '.$subject], $this->sent());
        self::assertStringContainsString('A reply by mail', (string) self::getMailerMessages()[0]->getTextBody());

        // nothing is sent when the event is not notified
        $this->clearSent();
        $this->saveSettings(['notified_events' => ['issue_added']]);
        self::assertInstanceOf(Journal::class, $this->handler()->receive(self::mail('rhill@example.net', 'Re: '.$subject, 'Another reply')));
        self::assertSame([], $this->sent());
        $this->saveSettings(['notified_events' => []]);
        self::assertInstanceOf(Issue::class, $this->handler()->receive($raw, ['issue' => ['project' => 'ecommerce'], 'allow_override' => 'assigned_to']));
        self::assertSame([], $this->sent());
    }

    /**
     * receive_issue_reply saves the issue (journal + notification) before add_attachments: the files of a reply with
     * a note are added to its journal afterwards, they do not trigger "issue_attachment_added"; a reply made of
     * attachments only creates the journal with them and is notified as an attachment.
     */
    public function testAttachmentsOfARepliesAndNotifiedEvents(): void
    {
        $file = "Content-Type: application/pdf; name=\"reply.pdf\"\nContent-Transfer-Encoding: base64\n\nUERGIGRhdGE=";
        $subject = 'Re: [eCommerce - Feature #15] Wishlist sharing';
        $details = fn (Journal $j) => $this->em->getConnection()->fetchAllAssociative('SELECT property, value FROM journal_details WHERE journal_id = ? ORDER BY id', [$j->id]);

        $this->saveSettings(['notified_events' => ['issue_attachment_added']]);
        $journal = $this->handler()->receive(self::multipart('rhill@example.net', $subject, ["Content-Type: text/plain; charset=UTF-8\n\nNote with a file", $file]));
        self::assertInstanceOf(Journal::class, $journal, (string) $this->handler()->lastError());
        self::assertSame('Note with a file', trim((string) $journal->notes));
        self::assertSame([['property' => 'attachment', 'value' => 'reply.pdf']], $details($journal), 'one journal: the note and its file');
        self::assertSame([], $this->sent(), 'the journal was created (and notified) before the file was attached');

        $journal = $this->handler()->receive(self::multipart('rhill@example.net', $subject, ["Content-Type: text/plain; charset=UTF-8\n\n", $file]));
        self::assertInstanceOf(Journal::class, $journal, (string) $this->handler()->lastError());
        self::assertNotNull($journal->id);
        self::assertSame([['property' => 'attachment', 'value' => 'reply.pdf']], $details($journal));
        self::assertSame(['jsmith@example.net: [eCommerce - Feature #15] Wishlist sharing'], $this->sent(), 'jsmith watches #15');

        // with the note event, the reply with a note is notified
        $this->clearSent();
        $this->saveSettings(['notified_events' => ['issue_note_added']]);
        self::assertInstanceOf(Journal::class, $this->handler()->receive(self::multipart('rhill@example.net', $subject, ["Content-Type: text/plain; charset=UTF-8\n\nSecond note", $file])));
        self::assertSame(['jsmith@example.net: [eCommerce - Feature #15] Wishlist sharing'], $this->sent());
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM attachments WHERE container_type = 'Issue' AND container_id = 15 AND filename = 'reply.pdf'"));
    }

    /** Replies to forum messages and news are notified like the ones posted in the application */
    public function testForumAndNewsRepliesByMailAreNotified(): void
    {
        $this->saveSettings(['notified_events' => ['message_posted', 'news_comment_added']]);
        // jsmith and rhill watch the topic "Hello" (message 1); jsmith watches the news 1
        $reply = $this->handler()->receive(self::mail('dlopper@example.net', 'Re: [eCommerce - General - msg1] Hello', 'Forum reply by mail'));
        self::assertInstanceOf(Message::class, $reply, (string) $this->handler()->lastError());
        self::assertSame('Hello', $reply->subject);
        self::assertSame(['jsmith@example.net: [eCommerce - General - msg1] Hello', 'rhill@example.net: [eCommerce - General - msg1] Hello'], $this->sent());

        $this->clearSent();
        $newsMessageId = '<redmine.news-1.20261107100000@example.net>';
        $comment = $this->handler()->receive(self::mail('dlopper@example.net', 'Re: [eCommerce] News: Release 0.9', 'News comment by mail', ['In-Reply-To' => $newsMessageId, 'References' => $newsMessageId]));
        self::assertInstanceOf(\App\Entity\Comment::class, $comment, (string) $this->handler()->lastError());
        // News#notified_users: every project member allowed to view the news, unless his option is "none" (the commenter excepted)
        self::assertSame(['jsmith@example.net: Re: [eCommerce] News: Release 0.9', 'rhill@example.net: Re: [eCommerce] News: Release 0.9'], $this->sent());

        $this->clearSent();
        $this->saveSettings(['notified_events' => ['issue_added']]);
        self::assertInstanceOf(Message::class, $this->handler()->receive(self::mail('dlopper@example.net', 'Re: [eCommerce - General - msg1] Hello', 'Second forum reply')));
        self::assertSame([], $this->sent());
    }
}

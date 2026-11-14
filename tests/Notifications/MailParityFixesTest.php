<?php

declare(strict_types=1);

namespace App\Tests\Notifications;

use App\Entity\Token;
use App\Entity\ProjectWebhook;
use App\Entity\Version;
use App\Entity\Webhook;
use App\Mailer\AccountMailer;
use App\Mailer\NotificationMailer;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Parity fixes for outgoing e-mail and webhooks (expected results from Redmine's test/unit/mailer_test.rb,
 * wiki_content.rb / mentionable.rb and webhookable.rb).
 */
final class MailParityFixesTest extends NotificationTestCase
{
    public const string JSMITH_KEY = '3de6a93a7c5f8921fa98e0fecd9ae25695aa7536';

    /** @var list<string> webhook events delivered */
    private array $hooked = [];

    private function saveSettings(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->settings()->set($name, $value);
        }
        $this->em->flush();
    }

    private function client(): KernelBrowser
    {
        $client = self::getContainer()->get('test.client');
        $client->disableReboot();

        return $client;
    }

    private function login(KernelBrowser $client, string $login): string
    {
        $crawler = $client->request('GET', '/login');
        $client->request('POST', '/login', ['username' => $login, 'password' => 'password123', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
        $crawler = $client->request('GET', '/my/page');

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }

    private function hookAllEvents(): void
    {
        $this->hooked = [];
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient(function (string $method, string $url, array $options) {
            $this->hooked[] = (string) (json_decode((string) $options['body'], true)['type'] ?? '?');

            return new MockResponse('ok', ['http_code' => 200]);
        }));
        $hook = new Webhook();
        $hook->url = 'http://93.184.216.34/hook';
        $hook->secret = 's3cret';
        $hook->events = ['wiki_page.created', 'wiki_page.updated', 'wiki_page.deleted'];
        $hook->user = $this->user('jsmith');
        $hook->active = true;
        $link = new ProjectWebhook();
        $link->project = $this->project('ecommerce');
        $link->webhook = $hook;
        $hook->projectLinks->add($link);
        $this->em->persist($hook);
        $this->saveSettings(['webhooks_enabled' => '1']);
    }

    /** @return list<string> sorted recipient addresses of the captured e-mails */
    private function recipients(): array
    {
        return array_keys($this->emailsByRecipient());
    }

    /** mailer_test.rb test_from_header_with_phrase, for the account mails (lost password, register, password changed) */
    public function testAccountMailsAcceptADisplayNameInTheEmissionAddress(): void
    {
        $this->saveSettings(['mail_from' => 'Redmine app <redmine@example.net>']);
        $jsmith = $this->user('jsmith');
        $token = new Token($jsmith, 'recovery');
        $token->value = 'abc123';
        $mailer = $this->service(AccountMailer::class);
        $mailer->lostPassword($jsmith, $token);
        $mailer->register($jsmith, $token);
        $mailer->passwordUpdated($jsmith, $jsmith, '10.0.0.1');
        $mailer->accountActivated($jsmith);
        $emails = $this->emails();
        self::assertCount(4, $emails, 'no account mail is dropped');
        foreach ($emails as $e) {
            self::assertSame('redmine@example.net', $e->getFrom()[0]->getAddress());
            self::assertSame('Redmine app', $e->getFrom()[0]->getName());
            self::assertSame('Redmine app <redmine@example.net>', $this->header($e, 'From'));
            self::assertSame('<redmine.example.net>', $this->header($e, 'List-Id'));
            self::assertSame('Redmine Reference', $this->header($e, 'X-Redmine-Site'));
            self::assertSame(['jsmith@example.net'], array_map(static fn ($a) => $a->getAddress(), $e->getTo()));
        }
        self::assertSame('Your Redmine Reference password', $emails[0]->getSubject());

        // without a display name the application title is used (Mailer#mail)
        $this->clearEmails();
        $this->saveSettings(['mail_from' => 'redmine@example.net']);
        $mailer->lostPassword($jsmith, $token);
        self::assertSame('Redmine Reference <redmine@example.net>', $this->header($this->emails()[0], 'From'));

        // test_from_header_with_rfc_non_compliant_phrase: sent anyway
        $this->clearEmails();
        $this->saveSettings(['mail_from' => '[Redmine app] <redmine@example.net>']);
        $mailer->lostPassword($jsmith, $token);
        self::assertCount(1, $this->emails());
    }

    /** Mailer.deliver_test_email goes through the regular mailer: From with phrase, headers, configured host */
    public function testAdminTestEmailUsesTheMailerLayout(): void
    {
        $this->saveSettings(['mail_from' => 'Redmine app <redmine@example.net>', 'emails_footer' => "---\nSent by the tracker"]);
        $this->service(NotificationMailer::class)->deliverTestEmail($this->user('jsmith'));
        $e = $this->emails()[0];
        self::assertSame('Redmine test', $e->getSubject());
        self::assertSame('Redmine app <redmine@example.net>', $this->header($e, 'From'));
        self::assertSame('<redmine.example.net>', $this->header($e, 'List-Id'));
        self::assertSame('auto-generated', $this->header($e, 'Auto-Submitted'));
        self::assertStringContainsString('http://localhost:3000/', (string) $e->getTextBody());
        // the footer keeps its leading "---" line (plain text settings are not YAML)
        self::assertStringContainsString("---\nSent by the tracker", str_replace("\r\n", "\n", (string) $e->getTextBody()));

        // POST /admin/test_email sends the same e-mail to the administrator
        $this->clearEmails();
        $client = $this->client();
        $crawler = $client->request('GET', '/login');
        $client->request('POST', '/login', ['username' => 'admin', 'password' => 'admin', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
        $token = (string) $client->request('GET', '/my/page')->filter('meta[name="csrf-token"]')->attr('content');
        $client->request('POST', '/admin/test_email', ['_token' => $token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $e = $this->emails()[0];
        self::assertSame('Redmine test', $e->getSubject());
        self::assertSame('<redmine.example.net>', $this->header($e, 'List-Id'));
    }

    /**
     * WikiContent#send_notification_update (saved_change_to_text?) and Mentionable#get_mentioned_users (new mentions only);
     * acts_as_webhookable fires wiki_page.updated for every saved page, including rename and protect.
     */
    public function testWikiUpdateNotificationsAndWebhooks(): void
    {
        $this->saveSettings(['notified_events' => ['wiki_content_added', 'wiki_content_updated']]);
        $this->hookAllEvents();
        $dlopper = $this->user('dlopper');
        $dlopper->mailNotification = 'all';
        $this->em->flush();
        $client = $this->client();
        $put = function (array $page) use ($client): int {
            $client->request('PUT', '/projects/ecommerce/wiki/NtfPage.json', [], [], ['HTTP_X_REDMINE_API_KEY' => self::JSMITH_KEY, 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['wiki_page' => $page]));

            return $client->getResponse()->getStatusCode();
        };

        // created: project members with notifications and the mentioned user
        self::assertSame(201, $put(['text' => 'Hello @rhill.']));
        self::assertSame(['dlopper@example.net', 'rhill@example.net'], $this->recipients());
        self::assertStringContainsString('has been added', $this->emails()[0]->getSubject());
        self::assertSame(['wiki_page.created'], $this->hooked);

        // text changed, same mention: rhill is not notified again
        $this->clearEmails();
        self::assertSame(204, $put(['text' => 'Hello @rhill, and more.', 'comments' => 'more']));
        self::assertSame(['dlopper@example.net'], $this->recipients());
        self::assertStringContainsString('has been updated', $this->emails()[0]->getSubject());

        // a new mention in an update is notified
        $this->clearEmails();
        self::assertSame(204, $put(['text' => 'Hello @rhill and @anna, and more.']));
        self::assertSame(['anna@example.net', 'dlopper@example.net'], $this->recipients());

        // same text, only the comment differs: nothing is saved, nobody is notified
        $this->clearEmails();
        $version = (int) $this->em->getConnection()->fetchOne("SELECT c.version FROM wiki_contents c JOIN wiki_pages p ON p.id = c.page_id WHERE p.title = 'NtfPage'");
        self::assertSame(204, $put(['text' => 'Hello @rhill and @anna, and more.', 'comments' => 'only a comment']));
        self::assertSame([], $this->emails());
        self::assertSame($version, (int) $this->em->getConnection()->fetchOne("SELECT c.version FROM wiki_contents c JOIN wiki_pages p ON p.id = c.page_id WHERE p.title = 'NtfPage'"));
        self::assertSame(['wiki_page.created', 'wiki_page.updated', 'wiki_page.updated', 'wiki_page.updated'], $this->hooked);

        // protect and rename save the page: wiki_page.updated webhook, no e-mail
        $this->hooked = [];
        $token = $this->login($client, 'jsmith');
        $client->request('POST', '/projects/ecommerce/wiki/NtfPage/protect', ['_token' => $token, 'protected' => '1']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame(['wiki_page.updated'], $this->hooked);
        $client->request('POST', '/projects/ecommerce/wiki/NtfPage/rename', ['_token' => $token, 'wiki_page' => ['title' => 'NtfRenamed', 'redirect_existing_links' => '1']]);
        self::assertSame(302, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame(['wiki_page.updated', 'wiki_page.updated'], $this->hooked);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM wiki_pages WHERE title = 'NtfRenamed' AND protected = 1"));
        self::assertSame([], $this->emails());
    }

    /** Mentionable: "User.visible.active.where(login: ...)" - only users the author can see are notified of a mention */
    public function testMentionsAreLimitedToUsersVisibleToTheAuthor(): void
    {
        $this->saveSettings(['notified_events' => ['issue_added', 'issue_updated']]);
        // anna is not a member of any project: dlopper (Developer, users visibility "members of visible projects") cannot see her
        $this->createIssue($this->user('dlopper'), 'ecommerce', ['subject' => 'Mention by dlopper', 'description' => 'Hello @anna and @rhill.']);
        self::assertNotContains('anna@example.net', $this->recipients());
        self::assertContains('rhill@example.net', $this->recipients());

        // jsmith is a Manager (users visibility "all"), admin sees everybody
        $this->clearEmails();
        $this->createIssue($this->user('jsmith'), 'ecommerce', ['subject' => 'Mention by jsmith', 'description' => 'Hello @anna.']);
        self::assertContains('anna@example.net', $this->recipients());
        $this->clearEmails();
        $this->createIssue($this->user('admin'), 'ecommerce', ['subject' => 'Mention by admin', 'description' => 'Hello @anna.']);
        self::assertContains('anna@example.net', $this->recipients());

        // once anna is a member of a project dlopper can see, he can mention her
        $this->clearEmails();
        $conn = $this->em->getConnection();
        $conn->executeStatement("INSERT INTO members (user_id, project_id, created_on, mail_notification) VALUES (9, 1, CURRENT_TIMESTAMP, 0)");
        $conn->executeStatement('INSERT INTO member_roles (member_id, role_id) VALUES (?, 5)', [(int) $conn->lastInsertId()]);
        $this->em->clear();
        $this->createIssue($this->user('dlopper'), 'ecommerce', ['subject' => 'Mention by dlopper again', 'description' => 'Hello @anna.']);
        self::assertContains('anna@example.net', $this->recipients());
    }

    /** Mailer.reminders: Version.named matches the version name case-insensitively and ignores surrounding spaces */
    public function testReminderVersionFilterIsCaseInsensitive(): void
    {
        $conn = $this->em->getConnection();
        $version = $this->em->getRepository(Version::class)->findOneBy(['name' => '1.0']);
        self::assertNotNull($version);
        $conn->executeStatement("UPDATE versions SET name = 'Beta' WHERE id = ?", [$version->id]);
        $conn->executeStatement('UPDATE issues SET due_date = NULL');
        $conn->executeStatement('UPDATE issues SET due_date = ?, fixed_version_id = ? WHERE id = 13', [(new \DateTimeImmutable('+2 days'))->format('Y-m-d'), $version->id]);
        $this->em->clear();

        $tester = new CommandTester((new Application(self::$kernel))->find('symine:send-reminders'));
        foreach (['Beta', 'beta', ' BETA '] as $name) {
            $this->clearEmails();
            $tester->execute(['--days' => '7', '--target-version' => $name]);
            self::assertSame(0, $tester->getStatusCode(), $name.': '.$tester->getDisplay());
            self::assertSame(['dlopper@example.net'], $this->recipients(), $name);
        }
        $tester->execute(['--days' => '7', '--target-version' => 'gamma']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString("Couldn't find Version named gamma", $tester->getDisplay());
    }

    // ------------------------------------------------------------------------------------------
    // Second round: per-user notification options (User#notify_about?, Issue#notified_users, Journal#notified_users)
    // ------------------------------------------------------------------------------------------

    /**
     * Is rhill notified, per mail_notification option, when he is:
     * [a plain member, a member with the project selected, the assignee, the previous assignee, the author, a watcher, mentioned].
     */
    public static function mailNotificationOptions(): iterable
    {
        yield 'all' => ['all', ['member' => true, 'selected' => true, 'assignee' => true, 'previous' => true, 'author' => true, 'watcher' => true, 'mentioned' => true]];
        yield 'selected' => ['selected', ['member' => false, 'selected' => true, 'assignee' => true, 'previous' => true, 'author' => true, 'watcher' => true, 'mentioned' => true]];
        yield 'only_my_events' => ['only_my_events', ['member' => false, 'selected' => null, 'assignee' => true, 'previous' => true, 'author' => true, 'watcher' => true, 'mentioned' => true]];
        yield 'only_assigned' => ['only_assigned', ['member' => false, 'selected' => null, 'assignee' => true, 'previous' => true, 'author' => false, 'watcher' => true, 'mentioned' => true]];
        yield 'only_owner' => ['only_owner', ['member' => false, 'selected' => null, 'assignee' => false, 'previous' => false, 'author' => true, 'watcher' => true, 'mentioned' => true]];
        yield 'only_my_watches' => ['only_my_watches', ['member' => false, 'selected' => null, 'assignee' => false, 'previous' => false, 'author' => false, 'watcher' => true, 'mentioned' => true]];
        yield 'none' => ['none', ['member' => false, 'selected' => null, 'assignee' => false, 'previous' => false, 'author' => false, 'watcher' => false, 'mentioned' => false]];
    }

    /** rhill with the given option, who is notified of his own changes and does not watch what he creates */
    private function rhillWith(string $option): \App\Entity\User
    {
        $rhill = $this->user('rhill');
        $rhill->mailNotification = $option;
        $rhill->getPref()->set('no_self_notified', '0');
        $rhill->getPref()->set('auto_watch_on', []);
        $this->em->flush();

        return $rhill;
    }

    private function selectProjectFor(\App\Entity\User $user, bool $selected): void
    {
        $this->em->getConnection()->executeStatement('UPDATE members SET mail_notification = ? WHERE user_id = ? AND project_id = 1', [$selected ? 1 : 0, $user->id]);
        $this->service(\App\Mailer\NotificationRecipients::class)->reset();
    }

    private function rhillNotified(): bool
    {
        $notified = \in_array('rhill@example.net', $this->recipients(), true);
        $this->clearEmails();

        return $notified;
    }

    /** @param array<string, ?bool> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailNotificationOptions')]
    public function testIssueAddRecipientsPerMailNotificationOption(string $option, array $expected): void
    {
        $this->saveSettings(['notified_events' => ['issue_added']]);
        $rhill = $this->rhillWith($option);
        $jsmith = $this->user('jsmith');
        $dlopper = $this->user('dlopper');

        $this->createIssue($jsmith, 'ecommerce', ['assigned_to_id' => $dlopper->id]);
        self::assertSame($expected['member'], $this->rhillNotified(), 'plain member');
        if (null !== $expected['selected']) {
            $this->selectProjectFor($rhill, true);
            $this->createIssue($jsmith, 'ecommerce', ['assigned_to_id' => $dlopper->id]);
            self::assertSame($expected['selected'], $this->rhillNotified(), 'member with the project selected');
            $this->selectProjectFor($rhill, false);
        }
        $this->createIssue($jsmith, 'ecommerce', ['assigned_to_id' => $rhill->id]);
        self::assertSame($expected['assignee'], $this->rhillNotified(), 'assignee');
        $this->createIssue($rhill, 'ecommerce', ['assigned_to_id' => $dlopper->id]);
        self::assertSame($expected['author'], $this->rhillNotified(), 'author (notified of his own changes)');
        $this->createIssue($jsmith, 'ecommerce', ['assigned_to_id' => $dlopper->id, 'watcher_user_ids' => [$rhill->id]]);
        self::assertSame($expected['watcher'], $this->rhillNotified(), 'watcher');
        $this->createIssue($jsmith, 'ecommerce', ['assigned_to_id' => $dlopper->id, 'description' => 'Please look at this @rhill.']);
        self::assertSame($expected['mentioned'], $this->rhillNotified(), 'mentioned in the description');

        // "I don't want to be notified of changes that I make myself"
        $rhill->getPref()->set('no_self_notified', '1');
        $this->em->flush();
        $this->createIssue($rhill, 'ecommerce', ['assigned_to_id' => $rhill->id, 'description' => '@rhill']);
        self::assertFalse($this->rhillNotified(), 'author with no_self_notified');
    }

    /** @param array<string, ?bool> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailNotificationOptions')]
    public function testIssueEditRecipientsPerMailNotificationOption(string $option, array $expected): void
    {
        $this->saveSettings(['notified_events' => ['issue_updated']]);
        $rhill = $this->rhillWith($option);
        $jsmith = $this->user('jsmith');
        $dlopper = $this->user('dlopper');
        $issue = fn (\App\Entity\User $author, array $attrs) => $this->createIssue($author, 'ecommerce', $attrs + ['assigned_to_id' => $dlopper->id]);

        $plain = $issue($jsmith, []);
        $this->updateIssue($plain, $jsmith, ['notes' => 'A note']);
        self::assertSame($expected['member'], $this->rhillNotified(), 'plain member');
        if (null !== $expected['selected']) {
            $this->selectProjectFor($rhill, true);
            $this->updateIssue($plain, $jsmith, ['notes' => 'Another note']);
            self::assertSame($expected['selected'], $this->rhillNotified(), 'member with the project selected');
            $this->selectProjectFor($rhill, false);
        }
        $assigned = $issue($jsmith, ['assigned_to_id' => $rhill->id]);
        $this->updateIssue($assigned, $jsmith, ['notes' => 'A note']);
        self::assertSame($expected['assignee'], $this->rhillNotified(), 'assignee');
        // reassigned to somebody else: the previous assignee is still notified of this change, not of the next one
        $this->updateIssue($assigned, $jsmith, ['assigned_to_id' => $dlopper->id]);
        self::assertSame($expected['previous'], $this->rhillNotified(), 'previous assignee');
        $this->updateIssue($assigned, $jsmith, ['notes' => 'After the reassignment']);
        self::assertSame($expected['member'], $this->rhillNotified(), 'not involved any more');

        $authored = $issue($rhill, []);
        $this->updateIssue($authored, $jsmith, ['notes' => 'A note']);
        self::assertSame($expected['author'], $this->rhillNotified(), 'author');
        $watched = $issue($jsmith, ['watcher_user_ids' => [$rhill->id]]);
        $this->updateIssue($watched, $jsmith, ['notes' => 'A note']);
        self::assertSame($expected['watcher'], $this->rhillNotified(), 'watcher');
        $this->updateIssue($plain, $jsmith, ['notes' => 'What do you think @rhill?']);
        self::assertSame($expected['mentioned'], $this->rhillNotified(), 'mentioned in the note');
        $this->updateIssue($plain, $jsmith, ['description' => 'Now mentioning @rhill in the description.']);
        self::assertSame($expected['mentioned'], $this->rhillNotified(), 'mentioned in the changed description');
        $this->updateIssue($plain, $jsmith, ['notes' => 'The description still mentions him']);
        self::assertSame($expected['member'], $this->rhillNotified(), 'old mentions are not notified again');

        // his own note: notified like the others unless he does not want to
        $this->updateIssue($assigned, $jsmith, ['assigned_to_id' => $rhill->id]);
        $this->clearEmails();
        $this->updateIssue($assigned, $rhill, ['notes' => 'My own note']);
        self::assertSame($expected['assignee'], $this->rhillNotified(), 'own note, no_self_notified off');
        $rhill->getPref()->set('no_self_notified', '1');
        $this->em->flush();
        $this->updateIssue($assigned, $rhill, ['notes' => 'My own note again']);
        self::assertFalse($this->rhillNotified(), 'own note, no_self_notified on');
    }

    /** Group assignees (all the users of the group), private issues and private notes (Issue#notified_users, Journal#notified_users) */
    public function testGroupsVisibilityAndHighPriorityRules(): void
    {
        $this->saveSettings(['notified_events' => ['issue_added', 'issue_updated']]);
        $jsmith = $this->user('jsmith');
        $rhill = $this->rhillWith('only_assigned');
        // a group as assignee: its users are notified according to their own option (dlopper: only_assigned)
        $issue = $this->createIssue($jsmith, 'ecommerce', ['assigned_to_id' => 10]);
        self::assertSame(['dlopper@example.net'], $this->recipients());
        // a group as watcher: its active users
        $this->clearEmails();
        $this->em->getConnection()->executeStatement('INSERT INTO groups_users (group_id, user_id) VALUES (10, ?)', [$rhill->id]);
        $this->em->clear();
        $watched = $this->createIssue($this->user('jsmith'), 'ecommerce', ['watcher_user_ids' => [10]]);
        self::assertSame(['dlopper@example.net', 'rhill@example.net'], $this->recipients());

        // private notes: only the users allowed to view them (Reporter is not)
        $this->clearEmails();
        $this->updateIssue($this->em->find(\App\Entity\Issue::class, $watched->id), $this->user('jsmith'), ['notes' => 'Private', 'private_notes' => '1']);
        self::assertSame(['dlopper@example.net'], $this->recipients());

        // high priority issues: members who opted in, whatever their option (even "none")
        $this->clearEmails();
        $rhill = $this->rhillWith('none');
        $this->em->getConnection()->executeStatement('DELETE FROM groups_users WHERE user_id = ?', [$rhill->id]);
        $rhill->getPref()->set('notify_about_high_priority_issues', '1');
        $this->em->flush();
        $this->em->clear();
        $this->createIssue($this->user('jsmith'), 'ecommerce', ['priority_id' => 2]);
        self::assertSame([], $this->recipients(), 'normal priority');
        $this->createIssue($this->user('jsmith'), 'ecommerce', ['priority_id' => 4]);
        self::assertSame(['rhill@example.net'], $this->recipients(), 'urgent');
        self::assertNotNull($issue->id);
    }

    /** Journal#send_notification: which changes are notified for each value of Setting.notified_events */
    public static function notifiedEvents(): iterable
    {
        $none = ['note' => false, 'status' => false, 'assignee' => false, 'priority' => false, 'version' => false, 'subject' => false];
        yield 'nothing' => [[], $none];
        yield 'issue_added only' => [['issue_added'], $none];
        yield 'issue_updated' => [['issue_updated'], array_map(static fn () => true, $none)];
        yield 'issue_note_added' => [['issue_note_added'], ['note' => true] + $none];
        yield 'issue_status_updated' => [['issue_status_updated'], ['status' => true] + $none];
        yield 'issue_assigned_to_updated' => [['issue_assigned_to_updated'], ['assignee' => true] + $none];
        yield 'issue_priority_updated' => [['issue_priority_updated'], ['priority' => true] + $none];
        yield 'issue_fixed_version_updated' => [['issue_fixed_version_updated'], ['version' => true] + $none];
        yield 'note or status' => [['issue_note_added', 'issue_status_updated'], ['note' => true, 'status' => true] + $none];
    }

    /**
     * @param list<string>        $events
     * @param array<string, bool> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('notifiedEvents')]
    public function testJournalNotificationsFollowNotifiedEvents(array $events, array $expected): void
    {
        // #13: assigned to the group Developers (dlopper), watched by jsmith and rhill
        $this->saveSettings(['notified_events' => $events]);
        $admin = $this->user('admin');
        $changes = [
            'note' => ['notes' => 'Only a note'],
            'status' => ['status_id' => 3],
            'assignee' => ['assigned_to_id' => $this->user('jsmith')->id],
            'priority' => ['priority_id' => 4],
            'version' => ['fixed_version_id' => ''],
            'subject' => ['subject' => 'Another subject'],
        ];
        foreach ($changes as $name => $attrs) {
            $this->clearEmails();
            $this->updateIssue($this->em->find(\App\Entity\Issue::class, 13), $admin, $attrs);
            self::assertSame($expected[$name], [] !== $this->emails(), $name);
        }
        // creations depend on issue_added only
        $this->clearEmails();
        $this->createIssue($this->user('jsmith'), 'ecommerce', ['assigned_to_id' => $this->user('dlopper')->id]);
        self::assertSame(\in_array('issue_added', $events, true), [] !== $this->emails());
    }

    /** The notification suppression (imports, project copy, Issue#notify = false) only lasts for the request */
    public function testNotificationSuppressionIsResetBetweenRequests(): void
    {
        $client = $this->client();
        $client->request('GET', '/login');
        $suppressor = self::getContainer()->get(\App\Import\ImportNotificationSuppressor::class);
        self::assertInstanceOf(\Symfony\Contracts\Service\ResetInterface::class, $suppressor);
        $suppressor->suppress(13);
        $suppressor->begin();
        self::assertTrue($suppressor->isSuppressed(13));
        self::assertTrue($suppressor->isActive());
        // the kernel resets the services before handling the next request (kernel.reset), as a long-running worker does
        $client->request('GET', '/login');
        self::assertSame($suppressor, self::getContainer()->get(\App\Import\ImportNotificationSuppressor::class));
        self::assertFalse($suppressor->isSuppressed(13));
        self::assertFalse($suppressor->isActive());

        // and nothing is shared between two kernels (two tests, two processes)
        $suppressor->suppress(14);
        self::ensureKernelShutdown();
        self::bootKernel();
        self::assertFalse(self::getContainer()->get(\App\Import\ImportNotificationSuppressor::class)->isSuppressed(14));
    }

    /** News, comments, forum messages, wiki pages, documents and files are only notified when their event is selected */
    public function testContentNotificationsFollowNotifiedEvents(): void
    {
        $dispatcher = $this->service(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $jsmith = $this->user('jsmith');
        $rhill = $this->user('rhill');
        $rhill->mailNotification = 'all';
        $comment = new \App\Entity\Comment();
        $comment->commentedType = 'News';
        $comment->commentedId = 1;
        $comment->author = $jsmith;
        $comment->content = 'A comment';
        $file = new \App\Entity\Attachment();
        $file->filename = 'release.zip';
        $file->diskFilename = 'x_release.zip';
        $file->author = $jsmith;
        $file->containerType = 'Project';
        $file->containerId = 1;
        $file->filesize = 10;
        $docFile = clone $file;
        $docFile->containerType = 'Document';
        $this->em->persist($comment);
        $this->em->persist($file);
        $this->em->persist($docFile);
        $this->em->flush();
        $page = $this->em->find(\App\Entity\WikiPage::class, 2);
        $document = $this->em->find(\App\Entity\Document::class, 1);
        $events = [
            'news_added' => new \App\Event\ContentEvent('news', 'created', $this->em->find(\App\Entity\News::class, 1), $jsmith),
            'news_comment_added' => new \App\Event\ContentEvent('news_comment', 'created', $comment, $jsmith),
            'message_posted' => new \App\Event\ContentEvent('message', 'created', $this->em->find(\App\Entity\Message::class, 1), $jsmith),
            'wiki_content_added' => new \App\Event\ContentEvent('wiki_page', 'created', $page, $jsmith),
            'wiki_content_updated' => new \App\Event\ContentEvent('wiki_page', 'updated', $page, $jsmith, ['content' => $page->content]),
            'document_added' => new \App\Event\ContentEvent('document', 'created', $document, $jsmith),
            'file_added' => new \App\Event\ContentEvent('attachment', 'created', $file, $jsmith, ['container' => $this->project('ecommerce'), 'attachments' => [$file]]),
        ];
        $sentFor = function () use ($events, $dispatcher): array {
            $out = [];
            foreach ($events as $name => $event) {
                $this->clearEmails();
                $dispatcher->dispatch($event);
                if (\in_array('rhill@example.net', $this->recipients(), true)) {
                    $out[] = $name;
                }
            }

            return $out;
        };
        foreach (array_keys($events) as $name) {
            $this->saveSettings(['notified_events' => [$name]]);
            self::assertSame([$name], $sentFor(), "only $name is notified");
        }
        // files added to a document depend on document_added (DocumentsController#add_attachment)
        $docFiles = new \App\Event\ContentEvent('attachment', 'created', $docFile, $jsmith, ['container' => $document, 'attachments' => [$docFile]]);
        foreach (['file_added' => false, 'document_added' => true] as $name => $expected) {
            $this->saveSettings(['notified_events' => [$name]]);
            $this->clearEmails();
            $dispatcher->dispatch($docFiles);
            self::assertSame($expected, \in_array('rhill@example.net', $this->recipients(), true), $name);
        }
        $this->saveSettings(['notified_events' => []]);
        self::assertSame([], $sentFor());
        $this->saveSettings(['notified_events' => array_keys($events)]);
        self::assertSame(array_keys($events), $sentFor());
    }
}

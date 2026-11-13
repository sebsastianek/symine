<?php

declare(strict_types=1);

namespace App\Tests\MailHandler;

use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Watcher;
use App\MailHandler\HtmlToText;
use App\MailHandler\MailHandler;
use App\MailHandler\MimePart;
use App\Service\CustomFieldService;
use App\Service\Settings;
use App\Tests\Integration\SymineTestCase;
use Symfony\Component\Mime\Email;

final class MailHandlerTest extends SymineTestCase
{
    private function handler(): MailHandler
    {
        return $this->service(MailHandler::class);
    }

    private static function mail(string $from, string $subject, string $body, array $headers = []): string
    {
        $h = "From: $from\r\nTo: redmine@somenet.foo\r\nSubject: $subject\r\nMessage-ID: <".uniqid('', true)."@example.net>\r\nDate: Sat, 07 Nov 2026 10:00:00 +0100\r\nMIME-Version: 1.0\r\n";
        foreach ($headers as $k => $v) {
            $h .= "$k: $v\r\n";
        }
        if (!isset($headers['Content-Type'])) {
            $h .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
        }

        return $h."\r\n".str_replace("\n", "\r\n", $body);
    }

    public function testCreatesIssueWithKeywordsAndCustomFields(): void
    {
        $raw = self::mail('John Smith <jsmith@example.net>', 'New ticket from mail', <<<TXT
            Lorem ipsum dolor sit amet, consectetuer adipiscing elit.

            Project: ecommerce
            Tracker: Bug
            Priority: Urgent
            Assigned to: dlopper
            Category: Backend
            Start date: 2026-11-10
            Due date: 2026-12-01
            % Done: 30
            Database: postgresql
            Platforms: Linux, Windows
            TXT);
        $issue = $this->handler()->receive($raw, ['allow_override' => 'all', 'issue' => ['tracker' => 'Support']]);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        $this->em->clear();
        $issue = $this->em->find(Issue::class, $issue->id);
        self::assertSame('New ticket from mail', $issue->subject);
        self::assertSame('ecommerce', $issue->project->identifier);
        self::assertSame('Bug', $issue->tracker->name);
        self::assertSame('Urgent', $issue->priority->name);
        self::assertSame('dlopper', $issue->assignedTo->login);
        self::assertSame('Backend', $issue->category->name);
        self::assertSame('jsmith', $issue->author->login);
        self::assertSame('2026-11-10', $issue->startDate->format('Y-m-d'));
        self::assertSame('2026-12-01', $issue->dueDate->format('Y-m-d'));
        self::assertSame(30, $issue->doneRatio);
        // keywords are removed from the description (their lines are left empty, like Redmine)
        self::assertSame('Lorem ipsum dolor sit amet, consectetuer adipiscing elit.', trim((string) $issue->description));
        $values = $this->service(CustomFieldService::class)->values('Issue', $issue->id);
        self::assertSame('PostgreSQL', $values[1]);
        self::assertSame(['Linux', 'Windows'], $values[2]);
        // notification sent to the assignee
        $to = array_map(static fn (Email $e) => $e->getTo()[0]->getAddress(), array_filter(self::getMailerMessages(), static fn ($m) => $m instanceof Email));
        self::assertContains('dlopper@example.net', $to);
    }

    public function testKeywordsIgnoredWithoutAllowOverride(): void
    {
        $raw = self::mail('jsmith@example.net', 'Defaults', "Body\n\nTracker: Feature\nPriority: Urgent\n");
        $issue = $this->handler()->receive($raw, ['issue' => ['project' => 'ecommerce', 'tracker' => 'Support']]);
        self::assertInstanceOf(Issue::class, $issue);
        self::assertSame('Support', $issue->tracker->name);
        self::assertSame('Normal', $issue->priority->name);
        self::assertStringContainsString('Tracker: Feature', (string) $issue->description);
    }

    public function testUnknownProjectIsRejected(): void
    {
        self::assertFalse($this->handler()->receive(self::mail('jsmith@example.net', 'No project', 'Hello')));
        self::assertStringContainsString('Unable to determine target project', (string) $this->handler()->lastError());
    }

    public function testUnknownUserIgnoredAcceptedOrCreated(): void
    {
        $raw = self::mail('John Doe <john.doe@unknown.example>', 'From unknown', 'Hello');
        self::assertFalse($this->handler()->receive($raw, ['issue' => ['project' => 'ecommerce']]));

        // accept: anonymous author, needs the permission (or no_permission_check)
        $issue = $this->handler()->receive($raw, ['issue' => ['project' => 'ecommerce'], 'unknown_user' => 'accept', 'no_permission_check' => '1']);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        self::assertFalse($issue->author->isLogged());

        // create: a new active user, added to the given group, receives his account information
        $issue = $this->handler()->receive($raw, ['issue' => ['project' => 'ecommerce'], 'unknown_user' => 'create', 'no_permission_check' => '1', 'default_group' => 'Developers']);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        $user = $this->em->getRepository(User::class)->findOneBy(['login' => 'john.doe@unknown.example']);
        self::assertNotNull($user);
        self::assertSame('John', $user->firstname);
        self::assertSame('Doe', $user->lastname);
        self::assertTrue($user->isActive());
        self::assertSame($user->id, $issue->author->id);
        self::assertContains(10, $user->getGroupIds());
        $subjects = array_map(static fn (Email $e) => $e->getSubject(), array_filter(self::getMailerMessages(), static fn ($m) => $m instanceof Email));
        self::assertContains('Your Redmine Reference account activation', $subjects);
    }

    public function testLockedUserAndPermissionChecks(): void
    {
        self::assertFalse($this->handler()->receive(self::mail('locked@example.net', 'Locked', 'x'), ['issue' => ['project' => 'ecommerce']]));
        // anna is not a member of the private project "internal"
        self::assertFalse($this->handler()->receive(self::mail('anna@example.net', 'No perm', 'x'), ['issue' => ['project' => 'internal']]));
        self::assertStringContainsString('not allowed to add issues', (string) $this->handler()->lastError());
    }

    public function testIgnoresAutoGeneratedAndEmissionAddress(): void
    {
        self::assertFalse($this->handler()->receive(self::mail('jsmith@example.net', 'Auto', 'x', ['Auto-Submitted' => 'auto-replied']), ['issue' => ['project' => 'ecommerce']]));
        self::assertFalse($this->handler()->receive(self::mail('jsmith@example.net', 'Auto', 'x', ['X-Autoreply' => 'yes']), ['issue' => ['project' => 'ecommerce']]));
        self::assertFalse($this->handler()->receive(self::mail('Redmine <redmine@example.net>', 'Loop', 'x'), ['issue' => ['project' => 'ecommerce']]));
    }

    public function testReplyBySubjectAddsJournalWithAttributeChanges(): void
    {
        $raw = self::mail('Dave Lopper <dlopper@example.net>', 'Re: [eCommerce - Bug #13] Cart total is wrong with discounts', "This is fixed.\n\nStatus: Resolved\n");
        $journal = $this->handler()->receive($raw, ['allow_override' => 'status']);
        self::assertInstanceOf(Journal::class, $journal, (string) $this->handler()->lastError());
        self::assertSame('This is fixed.', trim((string) $journal->notes));
        self::assertSame('dlopper', $journal->user->login);
        self::assertSame('Resolved', $journal->issue->status->name);
        self::assertFalse($journal->privateNotes);
    }

    public function testReplyByReferencesHeaderAndPrivateJournal(): void
    {
        // reply to a private note -> private note
        $this->actAs($this->user('jsmith'));
        $issue = $this->em->find(Issue::class, 13);
        $svc = $this->service(\App\Service\IssueService::class);
        $svc->setNotes($issue, 'private one', true);
        self::assertSame([], $svc->save($issue, $this->user('jsmith')));
        $private = $issue->journals->last();
        $this->actAs(null);

        $raw = self::mail('jsmith@example.net', 'Re: something unrelated', 'Answer to the private note', [
            'In-Reply-To' => '<redmine.journal-'.$private->id.'.20261107101010.6@example.net>',
        ]);
        $journal = $this->handler()->receive($raw);
        self::assertInstanceOf(Journal::class, $journal, (string) $this->handler()->lastError());
        self::assertTrue($journal->privateNotes);
        self::assertSame(13, $journal->issue->id);

        $raw = self::mail('jsmith@example.net', 'Re: x', 'Via issue reference', ['References' => '<redmine.issue-13.20261107101010@example.net>']);
        $journal = $this->handler()->receive($raw);
        self::assertInstanceOf(Journal::class, $journal);
        self::assertSame('Via issue reference', $journal->notes);
    }

    public function testReplyToNonexistentIssue(): void
    {
        self::assertFalse($this->handler()->receive(self::mail('jsmith@example.net', 'Re: [eCommerce - Bug #9999] x', 'hello')));
        self::assertStringContainsString('nonexistent issue', (string) $this->handler()->lastError());
    }

    public function testBodyDelimitersAndHtmlBody(): void
    {
        $settings = $this->service(Settings::class);
        $settings->set('mail_handler_body_delimiters', "--- Reply above. Do not remove this line. ---\nSent from my phone");
        $this->em->flush();
        $raw = self::mail('jsmith@example.net', 'Re: [eCommerce - Bug #13] x', "Thanks!\n\n> --- Reply above. Do not remove this line. ---\n> quoted stuff\nmore\n");
        $journal = $this->handler()->receive($raw);
        self::assertSame('Thanks!', $journal->notes);

        $boundary = 'b1';
        $raw = "From: jsmith@example.net\r\nTo: redmine@somenet.foo\r\nSubject: Html mail\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n"
            ."--$boundary\r\nContent-Type: text/html; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            ."<html><body><p>This is a <b>html</b> mail with an =E9 and a <a href=3D\"http://example.net\">link</a></p><p>Second</p></body></html>\r\n"
            ."--$boundary--\r\n";
        $issue = $this->handler()->receive($raw, ['issue' => ['project' => 'ecommerce']]);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        self::assertSame("This is a **html** mail with an é and a [link](http://example.net) \r\n\r\nSecond", $issue->description);
    }

    public function testAttachmentsAndExcludedFilenames(): void
    {
        $this->service(Settings::class)->set('mail_handler_excluded_filenames', '*.vcf, signature.*');
        $this->em->flush();
        $b = 'xyz';
        $raw = "From: jsmith@example.net\r\nTo: redmine@somenet.foo, rhill@example.net\r\nCc: dlopper@example.net\r\nSubject: =?UTF-8?B?w4l0w6kgdGlja2V0?=\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n"
            ."--$b\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nSee attached\r\n"
            ."--$b\r\nContent-Type: text/plain; name=\"notes.txt\"\r\nContent-Disposition: attachment; filename*=UTF-8''r%C3%A9sum%C3%A9.txt\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('hello world')."\r\n"
            ."--$b\r\nContent-Type: text/x-vcard; name=\"card.vcf\"\r\nContent-Disposition: attachment; filename=\"card.vcf\"\r\n\r\nBEGIN:VCARD\r\n"
            ."--$b--\r\n";
        $issue = $this->handler()->receive($raw, ['issue' => ['project' => 'ecommerce']]);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        self::assertSame('Été ticket', $issue->subject);
        $attachments = $this->em->getRepository(\App\Entity\Attachment::class)->findBy(['containerType' => 'Issue', 'containerId' => $issue->id]);
        self::assertCount(1, $attachments);
        self::assertSame('résumé.txt', $attachments[0]->filename);
        self::assertSame(11, $attachments[0]->getFilesize());
        // To/Cc users become watchers (jsmith is Manager: add_issue_watchers)
        $watchers = array_map(static fn (Watcher $w) => $w->user->login, $this->em->getRepository(Watcher::class)->findBy(['watchableType' => 'Issue', 'watchableId' => $issue->id]));
        self::assertContains('rhill', $watchers);
        self::assertContains('dlopper', $watchers);
    }

    public function testForumAndNewsReplies(): void
    {
        $raw = self::mail('rhill@example.net', 'Re: [eCommerce - General - msg1] Hello', 'A reply by mail');
        $reply = $this->handler()->receive($raw);
        self::assertInstanceOf(Message::class, $reply, (string) $this->handler()->lastError());
        self::assertSame('Hello', $reply->subject);
        self::assertSame(1, $reply->parent->id);

        $raw = self::mail('dlopper@example.net', 'Re: [eCommerce] News: Release 0.9', 'Nice news', ['In-Reply-To' => '<redmine.news-1.20261107101010.6@example.net>']);
        $comment = $this->handler()->receive($raw);
        self::assertInstanceOf(Comment::class, $comment, (string) $this->handler()->lastError());
        self::assertSame('Nice news', $comment->content);
        self::assertSame(1, $comment->commentedId);
    }

    public function testProjectFromSubaddress(): void
    {
        $raw = "From: jsmith@example.net\r\nTo: redmine+checkout@example.net\r\nSubject: Subaddress\r\n\r\nbody";
        $issue = $this->handler()->receive($raw, ['project_from_subaddress' => 'redmine@example.net']);
        self::assertInstanceOf(Issue::class, $issue, (string) $this->handler()->lastError());
        self::assertSame('checkout', $issue->project->identifier);
    }

    public function testMimeParsing(): void
    {
        $m = MimePart::parse("From: =?ISO-8859-1?Q?Andr=E9?= <andre@example.net>, \"Doe, John\" <john@x.org>\r\nSubject: =?UTF-8?Q?a_b?=\r\n =?UTF-8?Q?_c?=\r\n\r\nbody");
        self::assertSame([['address' => 'andre@example.net', 'name' => 'André'], ['address' => 'john@x.org', 'name' => 'Doe, John']], $m->addresses('from'));
        self::assertSame('a b c', $m->header('subject'));
        self::assertSame('body', $m->text());
        self::assertSame("# Title\n\nSome *text*", HtmlToText::convert('<h1>Title</h1><p>Some <em>text</em></p>', 'common_mark'));
        self::assertSame("h1. Title\n\nSome _text_", HtmlToText::convert('<h1>Title</h1><p>Some <em>text</em></p>', 'textile'));
    }
}

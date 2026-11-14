<?php

declare(strict_types=1);

namespace App\Tests\Notifications;

use App\Entity\Group;
use App\Entity\Issue;
use App\Entity\Member;
use App\Mailer\NotificationSubscriber;

final class IssueNotificationTest extends NotificationTestCase
{
    public function testIssueAddNotifiesAssigneeWithRedmineHeaders(): void
    {
        $jsmith = $this->user('jsmith');
        $dlopper = $this->user('dlopper');
        $issue = $this->createIssue($jsmith, 'ecommerce', ['assigned_to_id' => $dlopper->id, 'subject' => 'Checkout is slow', 'description' => 'It *is* slow', 'priority_id' => 3]);

        $emails = $this->emailsByRecipient();
        // jsmith is the author but has no_self_notified; dlopper is "only_assigned" and assigned
        self::assertSame(['dlopper@example.net'], array_keys($emails));
        $e = $emails['dlopper@example.net'];
        self::assertSame('[eCommerce - Bug #'.$issue->id.'] (New) Checkout is slow', $e->getSubject());
        self::assertSame('ecommerce', $this->header($e, 'X-Redmine-Project'));
        self::assertSame((string) $issue->id, $this->header($e, 'X-Redmine-Issue-Id'));
        self::assertSame('jsmith', $this->header($e, 'X-Redmine-Issue-Author'));
        self::assertSame('dlopper', $this->header($e, 'X-Redmine-Issue-Assignee'));
        self::assertSame('High', $this->header($e, 'X-Redmine-Issue-Priority'));
        self::assertSame('jsmith', $this->header($e, 'X-Redmine-Sender'));
        self::assertSame('Redmine', $this->header($e, 'X-Mailer'));
        self::assertSame('auto-generated', $this->header($e, 'Auto-Submitted'));
        self::assertSame('<ecommerce.redmine.example.net>', $this->header($e, 'List-Id'));
        self::assertMatchesRegularExpression('/^<redmine\.issue-'.$issue->id.'\.\d{14}\.'.$dlopper->id.'@example\.net>$/', $this->header($e, 'Message-ID'));
        self::assertMatchesRegularExpression('/^<redmine\.issue-'.$issue->id.'\.\d{14}\.'.$dlopper->id.'@example\.net>$/', $this->header($e, 'References'));
        self::assertSame('John Smith', $e->getFrom()[0]->getName());
        self::assertSame('redmine@example.net', $e->getFrom()[0]->getAddress());

        $text = (string) $e->getTextBody();
        self::assertStringContainsString('Issue #'.$issue->id.' has been reported by John Smith.', $text);
        self::assertStringContainsString('/issues/'.$issue->id, $text);
        self::assertStringContainsString('* Assignee: Dave Lopper', $text);
        self::assertStringContainsString('It *is* slow', $text);
        $html = (string) $e->getHtmlBody();
        self::assertStringContainsString('<a href="http://localhost:3000/issues/'.$issue->id.'">#'.$issue->id.'</a>', $html);
        self::assertStringContainsString('<strong>Status: </strong>New', $html);
        self::assertStringContainsString('badge-status-open', $html);
        self::assertStringContainsString('<em>is</em>', $html);
    }

    public function testRecipientLanguageAndPlainText(): void
    {
        $dlopper = $this->user('dlopper');
        $dlopper->language = 'fr';
        $this->em->flush();
        $this->settings()->set('plain_text_mail', '1');
        $this->em->flush();

        $this->createIssue($this->user('jsmith'), 'ecommerce', ['assigned_to_id' => $dlopper->id]);
        $e = $this->emailsByRecipient()['dlopper@example.net'];
        self::assertStringContainsString('a été soumise par John Smith', (string) $e->getTextBody());
        self::assertNull($e->getHtmlBody());
    }

    public function testNoSelfNotifiedDisabledNotifiesAuthor(): void
    {
        $jsmith = $this->user('jsmith');
        $jsmith->getPref()->set('no_self_notified', '0');
        $this->em->flush();
        $this->createIssue($jsmith, 'ecommerce', []);
        self::assertSame(['jsmith@example.net'], array_keys($this->emailsByRecipient()));
    }

    public function testNotifiedEventsDisabled(): void
    {
        $this->settings()->set('notified_events', []);
        $this->em->flush();
        $this->createIssue($this->user('jsmith'), 'ecommerce', ['assigned_to_id' => $this->user('dlopper')->id]);
        self::assertSame([], $this->emails());
    }

    public function testSuppressedIssueIsNotNotified(): void
    {
        $issue = $this->em->find(Issue::class, 13);
        $this->service(NotificationSubscriber::class)->suppress(13);
        $this->updateIssue($issue, $this->user('dlopper'), ['notes' => 'quiet']);
        self::assertSame([], $this->emails());
    }

    public function testGroupAssigneeMembersAreNotified(): void
    {
        $group = $this->em->find(Group::class, 10);
        $issue = $this->createIssue($this->user('jsmith'), 'ecommerce', ['assigned_to_id' => $group->id]);
        $emails = $this->emailsByRecipient();
        self::assertArrayHasKey('dlopper@example.net', $emails);
        self::assertSame('Group (Developers)', $this->header($emails['dlopper@example.net'], 'X-Redmine-Issue-Assignee'));
        self::assertSame($issue->id, (int) $this->header($emails['dlopper@example.net'], 'X-Redmine-Issue-Id'));
    }

    public function testIssueEditNotifiesWatchersAuthorWithJournalDetails(): void
    {
        $issue = $this->em->find(Issue::class, 13); // author jsmith, assigned to group Developers, watched by jsmith & rhill
        $this->updateIssue($issue, $this->user('dlopper'), ['notes' => 'Fixed in **trunk**', 'status_id' => 3, 'done_ratio' => 50]);

        $emails = $this->emailsByRecipient();
        // dlopper (author of the change, member of the assigned group) is not self-notified
        self::assertSame(['jsmith@example.net', 'rhill@example.net'], array_keys($emails));
        $e = $emails['rhill@example.net'];
        self::assertSame('[eCommerce - Bug #13] (Resolved) Cart total is wrong with discounts', $e->getSubject());
        $journalId = $issue->journals->last()->id;
        self::assertMatchesRegularExpression('/^<redmine\.journal-'.$journalId.'\.\d{14}\.7@example\.net>$/', $this->header($e, 'Message-ID'));
        self::assertMatchesRegularExpression('/^<redmine\.issue-13\.\d{14}\.7@example\.net>$/', $this->header($e, 'References'));
        $text = (string) $e->getTextBody();
        self::assertStringContainsString('Issue #13 has been updated by Dave Lopper.', $text);
        self::assertStringContainsString('Status changed from In Progress to Resolved', $text);
        self::assertStringContainsString('% Done changed from 30 to 50', $text);
        self::assertStringContainsString('Fixed in **trunk**', $text);
        $html = (string) $e->getHtmlBody();
        self::assertStringContainsString('<strong>Status</strong> changed from <i>In Progress</i> to <i>Resolved</i>', $html);
        self::assertStringContainsString('#change-'.$journalId, $html);
        self::assertStringContainsString('<strong>trunk</strong>', $html);
    }

    public function testStatusInSubjectSetting(): void
    {
        $this->settings()->set('show_status_changes_in_mail_subject', '0');
        $this->em->flush();
        $issue = $this->em->find(Issue::class, 13);
        $this->updateIssue($issue, $this->user('dlopper'), ['status_id' => 3]);
        self::assertSame('[eCommerce - Bug #13] Cart total is wrong with discounts', $this->emails()[0]->getSubject());
        $this->clearEmails();
        $this->settings()->set('show_status_changes_in_mail_subject', '1');
        $this->em->flush();
        $this->updateIssue($issue, $this->user('dlopper'), ['status_id' => 4]);
        self::assertSame('[eCommerce - Bug #13] (Feedback) Cart total is wrong with discounts', $this->emails()[0]->getSubject());
        // no status change: no status in the subject
        $this->clearEmails();
        $this->updateIssue($issue, $this->user('dlopper'), ['notes' => 'x']);
        self::assertSame('[eCommerce - Bug #13] Cart total is wrong with discounts', $this->emails()[0]->getSubject());
    }

    public function testPrivateNotesOnlyToUsersAllowedToViewThem(): void
    {
        $issue = $this->em->find(Issue::class, 13);
        // rhill (Reporter, watcher) cannot view private notes; jsmith (Manager) can
        $this->updateIssue($issue, $this->user('dlopper'), ['notes' => 'secret', 'private_notes' => '1']);
        self::assertSame(['jsmith@example.net'], array_keys($this->emailsByRecipient()));
        self::assertStringContainsString('(Private notes)', (string) $this->emails()[0]->getTextBody());
    }

    public function testNotifiedEventsFilterOnNotesOnly(): void
    {
        $this->settings()->set('notified_events', ['issue_note_added']);
        $this->em->flush();
        $issue = $this->em->find(Issue::class, 13);
        $this->updateIssue($issue, $this->user('dlopper'), ['done_ratio' => 30]);
        self::assertSame([], $this->emails());
        $this->updateIssue($issue, $this->user('dlopper'), ['notes' => 'A note']);
        self::assertCount(2, $this->emails());
    }

    public function testMemberMailNotificationAndAllOption(): void
    {
        // rhill: notified for every event of the project via members.mail_notification ("selected")
        $rhill = $this->user('rhill');
        $member = $this->em->getRepository(Member::class)->findOneBy(['principal' => $rhill, 'project' => $this->project('ecommerce')]);
        $member->mailNotification = true;
        $rhill->mailNotification = 'selected';
        $this->em->flush();
        $this->createIssue($this->user('jsmith'), 'ecommerce', []);
        self::assertSame(['rhill@example.net'], array_keys($this->emailsByRecipient()));

        // a locked user is never notified even with "all"
        $this->clearEmails();
        $rhill->status = 3;
        $this->em->flush();
        $this->createIssue($this->user('jsmith'), 'ecommerce', []);
        self::assertSame([], $this->emails());
    }

    public function testOnlyUsersAbleToViewThePrivateIssue(): void
    {
        $rhill = $this->user('rhill');
        $rhill->mailNotification = 'all';
        $dlopper = $this->user('dlopper');
        $dlopper->mailNotification = 'all';
        $this->em->flush();
        // private issue: Reporter (rhill, issues_visibility=default) cannot see it; Developer (dlopper) neither unless assigned/author
        $this->createIssue($this->user('jsmith'), 'ecommerce', ['is_private' => '1']);
        self::assertSame([], $this->emails());
    }

    public function testMentionedUserIsNotified(): void
    {
        $this->createIssue($this->user('jsmith'), 'ecommerce', ['description' => 'Please check @rhill.']);
        self::assertSame(['rhill@example.net'], array_keys($this->emailsByRecipient()));
    }

    public function testHighPriorityOptIn(): void
    {
        $rhill = $this->user('rhill');
        $rhill->getPref()->set('notify_about_high_priority_issues', '1');
        $this->em->persist($rhill->getPref());
        $this->em->flush();
        $this->createIssue($this->user('jsmith'), 'ecommerce', ['priority_id' => 2]);
        self::assertSame([], $this->emails());
        $this->createIssue($this->user('jsmith'), 'ecommerce', ['priority_id' => 4]);
        self::assertSame(['rhill@example.net'], array_keys($this->emailsByRecipient()));
    }

    public function testEmailsHeaderAndFooter(): void
    {
        $this->settings()->set('emails_header', 'HEADER *text*');
        $this->settings()->set('emails_footer', 'FOOTER text');
        $this->em->flush();
        $this->createIssue($this->user('jsmith'), 'ecommerce', ['assigned_to_id' => $this->user('dlopper')->id]);
        $e = $this->emails()[0];
        self::assertStringStartsWith('HEADER *text*', (string) $e->getTextBody());
        self::assertStringContainsString("-- \nFOOTER text", (string) $e->getTextBody());
        self::assertStringContainsString('<span class="footer"><p>FOOTER text</p>', (string) $e->getHtmlBody());
    }
}

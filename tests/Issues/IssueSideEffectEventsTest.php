<?php

declare(strict_types=1);

namespace App\Tests\Issues;

use App\Entity\Issue;
use App\Entity\Journal;
use App\Event\IssueEvent;
use App\Service\Settings;
use App\Tests\Notifications\NotificationTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Journals saved outside the main issue save must be notified like in Redmine (Journal#send_notification is an
 * after_create_commit callback of every journal) and the issues saved as a side effect must trigger the
 * issue.updated / issue.deleted webhooks (acts_as_webhookable after_*_commit callbacks).
 *
 * Redmine: app/models/journal.rb (send_notification, split_private_notes), app/models/issue.rb (relation_added,
 * close_duplicates, reschedule_on!, recalculate_attributes_for), lib/redmine/nested_set/issue_nested_set.rb (destroy_children).
 */
final class IssueSideEffectEventsTest extends NotificationTestCase
{
    /** @var list<IssueEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = [];
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(IssueEvent::class, function (IssueEvent $e): void {
            $this->events[] = $e;
        }, 100);
    }

    /** @return list<array{0: int, 1: string, 2: bool, 3: bool, 4: list<string>}> issue id, action, has journal, webhook, detail keys */
    private function summary(): array
    {
        return array_map(static fn (IssueEvent $e) => [
            (int) $e->issue->id,
            $e->action,
            null !== $e->journal,
            $e->webhook,
            null === $e->journal ? [] : array_map(static fn ($d) => $d->property.':'.$d->propKey, $e->journal->details->toArray()),
        ], $this->events);
    }

    /** @return list<string> "recipient: subject" of the e-mails sent */
    private function mails(): array
    {
        $out = array_map(static fn ($e) => $e->getTo()[0]->getAddress().': '.$e->getSubject(), $this->emails());
        sort($out);

        return $out;
    }

    private function reset(): void
    {
        $this->events = [];
        $this->clearEmails();
    }

    /** Issue#relation_added / relation_removed: only the journals are saved => e-mail, no issue.updated webhook */
    public function testRelationJournalsAreNotifiedWithoutWebhook(): void
    {
        $jsmith = $this->user('jsmith');
        $dlopper = $this->user('dlopper');
        $a = $this->createIssue($jsmith, 'ecommerce', ['subject' => 'Relation A', 'assigned_to_id' => $dlopper->id]);
        $b = $this->createIssue($jsmith, 'ecommerce', ['subject' => 'Relation B', 'assigned_to_id' => $dlopper->id]);
        $this->reset();

        [$relation, $errors] = $this->issues()->createRelation($a, $b, 'relates', null, $jsmith);
        self::assertSame([], $errors);
        self::assertSame([
            [(int) $a->id, IssueEvent::UPDATED, true, false, ['relation:relates']],
            [(int) $b->id, IssueEvent::UPDATED, true, false, ['relation:relates']],
        ], $this->summary());
        self::assertSame([
            'dlopper@example.net: [eCommerce - Bug #'.$a->id.'] Relation A',
            'dlopper@example.net: [eCommerce - Bug #'.$b->id.'] Relation B',
        ], $this->mails());

        $this->reset();
        $this->issues()->deleteRelation($relation, $jsmith);
        self::assertSame([
            [(int) $a->id, IssueEvent::UPDATED, true, false, ['relation:relates']],
            [(int) $b->id, IssueEvent::UPDATED, true, false, ['relation:relates']],
        ], $this->summary());
        self::assertCount(2, $this->emails());
    }

    /** Issue#close_duplicates: duplicate.update_attribute :status => journal e-mail and issue.updated webhook of the duplicate */
    public function testAutoClosedDuplicateIsNotified(): void
    {
        $admin = $this->user('admin');
        $dlopper = $this->user('dlopper');
        $original = $this->createIssue($admin, 'ecommerce', ['subject' => 'Original', 'assigned_to_id' => $dlopper->id]);
        $duplicate = $this->createIssue($admin, 'ecommerce', ['subject' => 'Duplicate', 'assigned_to_id' => $dlopper->id]);
        $this->issues()->createRelation($duplicate, $original, 'duplicates', null, $admin);
        $this->reset();

        $this->updateIssue($original, $admin, ['status_id' => 5, 'notes' => 'done']);
        $summary = $this->summary();
        self::assertContains([(int) $original->id, IssueEvent::UPDATED, true, true, ['attr:status_id']], $summary);
        self::assertContains([(int) $duplicate->id, IssueEvent::UPDATED, true, true, ['attr:status_id']], $summary);
        self::assertCount(2, $summary);
        self::assertSame([
            'dlopper@example.net: [eCommerce - Bug #'.$original->id.'] (Closed) Original',
            'dlopper@example.net: [eCommerce - Bug #'.$duplicate->id.'] (Closed) Duplicate',
        ], $this->mails());
        self::assertSame('done', $this->events[1]->journal?->notes ?? $this->events[0]->journal?->notes);
    }

    /** Issue#reschedule_following_issues => reschedule_on!(date, journal): the following issue is saved with a journal */
    public function testRescheduledFollowerIsNotified(): void
    {
        $admin = $this->user('admin');
        $dlopper = $this->user('dlopper');
        $first = $this->createIssue($admin, 'ecommerce', ['subject' => 'First', 'start_date' => '2012-10-15', 'due_date' => '2012-10-17']);
        $second = $this->createIssue($admin, 'ecommerce', ['subject' => 'Second', 'start_date' => '2012-10-18', 'due_date' => '2012-10-19', 'assigned_to_id' => $dlopper->id]);
        $this->issues()->createRelation($first, $second, 'precedes', null, $admin);
        $this->reset();

        $this->updateIssue($first, $admin, ['due_date' => '2012-10-23']);
        self::assertContains([(int) $second->id, IssueEvent::UPDATED, true, true, ['attr:start_date', 'attr:due_date']], $this->summary());
        self::assertSame(['dlopper@example.net: [eCommerce - Bug #'.$second->id.'] Second'], $this->mails());
        self::assertSame('2012-10-24', $second->startDate?->format('Y-m-d'));
    }

    /** Issue#recalculate_attributes_for: p.save(:validate => false) => issue.updated webhook of the parent, no journal, no e-mail */
    public function testRecalculatedParentTriggersTheUpdatedEventWithoutJournal(): void
    {
        $child = $this->em->find(Issue::class, 15); // subtask of #14
        $this->reset();
        $this->updateIssue($child, $this->user('jsmith'), ['done_ratio' => 80]);
        $summary = $this->summary();
        self::assertContains([15, IssueEvent::UPDATED, true, true, ['attr:done_ratio']], $summary);
        self::assertContains([14, IssueEvent::UPDATED, false, true, []], $summary);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM journal_details d JOIN journals j ON j.id = d.journal_id WHERE j.journalized_id = 14 AND d.prop_key = 'done_ratio'"));
    }

    /** Journal#split_private_notes: the changes go to a public journal, each journal is notified to those who can see it */
    public function testPrivateNotesWithChangesAreSplitAndNotifiedSeparately(): void
    {
        // #13: author jsmith (manager), assigned to the Developers group, watched by jsmith and rhill (reporter: no private notes)
        $issue = $this->em->find(Issue::class, 13);
        $this->reset();
        $this->updateIssue($issue, $this->user('dlopper'), ['notes' => 'for your eyes only', 'private_notes' => '1', 'status_id' => 3]);

        self::assertSame([
            [13, IssueEvent::UPDATED, true, false, ['attr:status_id']],
            [13, IssueEvent::UPDATED, true, true, []],
        ], $this->summary());
        [$changes, $notes] = [$this->events[0]->journal, $this->events[1]->journal];
        self::assertInstanceOf(Journal::class, $changes);
        self::assertFalse($changes->privateNotes);
        self::assertFalse($changes->hasNotes());
        self::assertTrue($notes->privateNotes);
        self::assertSame('for your eyes only', $notes->notes);

        $byRecipient = [];
        foreach ($this->emails() as $e) {
            $byRecipient[$e->getTo()[0]->getAddress()][] = (string) $e->getTextBody();
        }
        ksort($byRecipient);
        self::assertSame(['jsmith@example.net', 'rhill@example.net'], array_keys($byRecipient));
        // rhill only receives the changes
        self::assertCount(1, $byRecipient['rhill@example.net']);
        self::assertStringContainsString('Status changed from In Progress to Resolved', $byRecipient['rhill@example.net'][0]);
        self::assertStringNotContainsString('for your eyes only', $byRecipient['rhill@example.net'][0]);
        // jsmith receives the changes and the private notes
        self::assertCount(2, $byRecipient['jsmith@example.net']);
        self::assertStringContainsString('for your eyes only', implode("\n", $byRecipient['jsmith@example.net']));
        self::assertStringContainsString('Status changed from In Progress to Resolved', implode("\n", $byRecipient['jsmith@example.net']));
    }

    /** IssueNestedSet#destroy_children: every destroyed subtask runs its own after_destroy_commit (issue.deleted) */
    public function testDestroyingAParentFiresDeletedForEachSubtask(): void
    {
        $parent = $this->em->find(Issue::class, 14); // subtasks #15 and #16
        $this->actAs($this->user('admin'));
        $this->reset();
        $this->issues()->destroy([$parent], 'destroy', null, $this->user('admin'));
        $deleted = array_values(array_filter($this->summary(), static fn ($e) => IssueEvent::DELETED === $e[1]));
        self::assertSame([14, 15, 16], array_column($deleted, 0));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM issues WHERE id IN (14, 15, 16)'));
    }

    /** Issue#create_parent_issue_journal: the parent is saved with a "child_id" journal => e-mail and webhook */
    public function testSubtaskAddedToAParentIsNotified(): void
    {
        $admin = $this->user('admin');
        $dlopper = $this->user('dlopper');
        $parent = $this->createIssue($admin, 'ecommerce', ['subject' => 'Parent', 'assigned_to_id' => $dlopper->id]);
        $this->reset();
        $child = $this->createIssue($admin, 'ecommerce', ['subject' => 'Child', 'parent_issue_id' => $parent->id]);
        $summary = $this->summary();
        self::assertContains([(int) $child->id, IssueEvent::CREATED, false, true, []], $summary);
        self::assertContains([(int) $parent->id, IssueEvent::UPDATED, true, true, ['attr:child_id']], $summary);
        self::assertContains('dlopper@example.net: [eCommerce - Bug #'.$parent->id.'] Parent', $this->mails());
    }

    /** Journal#initialize: notify = false for the journal of a record being created (the "copied from" relation of a copy) */
    public function testCopyNotifiesTheCopiedIssueOnly(): void
    {
        $admin = $this->user('admin');
        $dlopper = $this->user('dlopper');
        $source = $this->createIssue($admin, 'ecommerce', ['subject' => 'Source', 'assigned_to_id' => $dlopper->id]);
        $this->reset();
        $this->actAs($admin);
        $copy = $this->issues()->copyFrom($source, $admin, ['link' => true]);
        self::assertSame([], $this->issues()->save($copy, $admin));
        $this->issues()->afterCopy($copy, $admin);

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM issue_relations WHERE relation_type = 'copied_to' AND issue_from_id = ? AND issue_to_id = ?", [$source->id, $copy->id]));
        $summary = $this->summary();
        self::assertContains([(int) $copy->id, IssueEvent::CREATED, false, true, []], $summary);
        self::assertContains([(int) $source->id, IssueEvent::UPDATED, true, false, ['relation:copied_to']], $summary);
        self::assertCount(2, $summary, 'no event for the "copied from" journal of the new issue');
        self::assertSame([
            'dlopper@example.net: [eCommerce - Bug #'.$source->id.'] Source',
            'dlopper@example.net: [eCommerce - Bug #'.$copy->id.'] (New) Source',
        ], $this->mails());
    }

    /** Issue#close_duplicates is skipped when Setting.close_duplicate_issues is off */
    public function testDuplicatesSettingOff(): void
    {
        $this->service(Settings::class)->set('close_duplicate_issues', '0');
        $this->em->flush();
        $admin = $this->user('admin');
        $original = $this->createIssue($admin, 'ecommerce', ['subject' => 'Original']);
        $duplicate = $this->createIssue($admin, 'ecommerce', ['subject' => 'Duplicate']);
        $this->issues()->createRelation($duplicate, $original, 'duplicates', null, $admin);
        $this->reset();
        $this->updateIssue($original, $admin, ['status_id' => 5]);
        self::assertFalse($duplicate->isClosed());
        self::assertSame([(int) $original->id], array_values(array_unique(array_column($this->summary(), 0))));
    }

    /** Issue#copy_from: the watchers are assigned before the copy is saved, they receive the "issue added" e-mail */
    public function testWatchersOfTheCopiedIssueAreNotifiedOfTheCopy(): void
    {
        $admin = $this->user('admin');
        $source = $this->em->find(Issue::class, 13); // watched by jsmith and rhill, assigned to Developers (dlopper)
        $this->reset();
        $this->actAs($admin);
        $copy = $this->issues()->copyFrom($source, $admin, ['link' => false, 'subtasks' => false]);
        $copy->subject = 'Copy for the watchers';
        self::assertSame([], $this->issues()->save($copy, $admin));
        // at the time the notification is built the watchers are already there
        $created = array_values(array_filter($this->events, static fn (IssueEvent $e) => IssueEvent::CREATED === $e->action));
        self::assertCount(1, $created);
        $added = array_values(array_filter($this->mails(), static fn (string $m) => str_contains($m, 'Copy for the watchers')));
        self::assertSame([
            'dlopper@example.net: [eCommerce - Bug #'.$copy->id.'] (New) Copy for the watchers',
            'jsmith@example.net: [eCommerce - Bug #'.$copy->id.'] (New) Copy for the watchers',
            'rhill@example.net: [eCommerce - Bug #'.$copy->id.'] (New) Copy for the watchers',
        ], $added);
        $this->issues()->afterCopy($copy, $admin);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = ? AND user_id IN (5, 7)", [$copy->id]), 'not added twice');
    }

    /** A relation saved without init_journals (IssueImport#build_relations): no journal, no e-mail */
    public function testRelationWithoutJournals(): void
    {
        $admin = $this->user('admin');
        $dlopper = $this->user('dlopper');
        $a = $this->createIssue($admin, 'ecommerce', ['subject' => 'A', 'assigned_to_id' => $dlopper->id]);
        $b = $this->createIssue($admin, 'ecommerce', ['subject' => 'B', 'assigned_to_id' => $dlopper->id]);
        $this->reset();
        [$relation, $errors] = $this->issues()->createRelation($a, $b, 'blocks', null, $admin, false);
        self::assertSame([], $errors);
        self::assertNotNull($relation?->id);
        self::assertSame([], $this->summary());
        self::assertSame([], $this->mails());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM journals WHERE journalized_type = 'Issue' AND journalized_id IN (?, ?)", [$a->id, $b->id]));
    }
}

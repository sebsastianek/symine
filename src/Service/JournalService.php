<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\JournalDetail;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for managing issue journals (activity tracking and comments)
 */
class JournalService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Create a journal entry with a comment/note
     */
    public function createComment(Issue $issue, User $user, string $notes, bool $privateNotes = false): Journal
    {
        $journal = new Journal();
        $journal->setJournalizedId($issue->getId());
        $journal->setJournalizedType('Issue');
        $journal->setUser($user);
        $journal->setNotes($notes);
        $journal->setPrivateNotes($privateNotes ? 1 : 0);
        $journal->setCreatedOn(new \DateTime());

        $this->entityManager->persist($journal);

        return $journal;
    }

    /**
     * Track changes to an issue and create journal entries
     *
     * @param array $changes Array of changes: ['field' => ['old' => value, 'new' => value]]
     */
    public function trackChanges(Issue $issue, User $user, array $changes, ?string $notes = null): ?Journal
    {
        if (empty($changes) && empty($notes)) {
            return null;
        }

        $journal = new Journal();
        $journal->setJournalizedId($issue->getId());
        $journal->setJournalizedType('Issue');
        $journal->setUser($user);
        $journal->setNotes($notes);
        $journal->setPrivateNotes(0);
        $journal->setCreatedOn(new \DateTime());

        $this->entityManager->persist($journal);

        // Create journal details for each change
        foreach ($changes as $field => $change) {
            $detail = new JournalDetail();
            $detail->setJournal($journal);
            $detail->setProperty('attr');
            $detail->setPropKey($field);
            $detail->setOldValue($this->formatValue($change['old']));
            $detail->setNewValue($this->formatValue($change['new']));

            $this->entityManager->persist($detail);
        }

        return $journal;
    }

    /**
     * Get all journals for an issue
     *
     * @return Journal[]
     */
    public function getIssueJournals(Issue $issue, bool $includePrivate = false): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('j')
            ->from(Journal::class, 'j')
            ->where('j.journalizedId = :issueId')
            ->andWhere('j.journalizedType = :type')
            ->setParameter('issueId', $issue->getId())
            ->setParameter('type', 'Issue')
            ->orderBy('j.createdOn', 'ASC');

        if (!$includePrivate) {
            $qb->andWhere('j.privateNotes = 0');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get journal details for a journal entry
     *
     * @return JournalDetail[]
     */
    public function getJournalDetails(Journal $journal): array
    {
        return $this->entityManager->getRepository(JournalDetail::class)->findBy(
            ['journal' => $journal],
            ['id' => 'ASC']
        );
    }

    /**
     * Format a value for storage in journal
     */
    private function formatValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_object($value)) {
            if (method_exists($value, 'getId')) {
                return (string) $value->getId();
            }
            if (method_exists($value, 'getName')) {
                return $value->getName();
            }
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * Detect changes between original and updated issue data
     *
     * @return array Changes in format ['field' => ['old' => value, 'new' => value]]
     */
    public function detectIssueChanges(array $originalData, Issue $updatedIssue): array
    {
        $changes = [];

        // Track status changes
        if (isset($originalData['status']) && $originalData['status'] !== $updatedIssue->getStatus()->getId()) {
            $changes['status_id'] = [
                'old' => $originalData['status'],
                'new' => $updatedIssue->getStatus()->getId()
            ];
        }

        // Track priority changes
        if (isset($originalData['priority']) && $originalData['priority'] !== $updatedIssue->getPriority()->getId()) {
            $changes['priority_id'] = [
                'old' => $originalData['priority'],
                'new' => $updatedIssue->getPriority()->getId()
            ];
        }

        // Track assigned_to changes
        $oldAssignedId = $originalData['assigned_to'] ?? null;
        $newAssignedId = $updatedIssue->getAssignedTo()?->getId();
        if ($oldAssignedId !== $newAssignedId) {
            $changes['assigned_to_id'] = [
                'old' => $oldAssignedId,
                'new' => $newAssignedId
            ];
        }

        // Track subject changes
        if (isset($originalData['subject']) && $originalData['subject'] !== $updatedIssue->getSubject()) {
            $changes['subject'] = [
                'old' => $originalData['subject'],
                'new' => $updatedIssue->getSubject()
            ];
        }

        // Track description changes
        if (isset($originalData['description']) && $originalData['description'] !== $updatedIssue->getDescription()) {
            $changes['description'] = [
                'old' => $originalData['description'],
                'new' => $updatedIssue->getDescription()
            ];
        }

        // Track done_ratio changes
        if (isset($originalData['done_ratio']) && $originalData['done_ratio'] !== $updatedIssue->getDoneRatio()) {
            $changes['done_ratio'] = [
                'old' => $originalData['done_ratio'],
                'new' => $updatedIssue->getDoneRatio()
            ];
        }

        // Track start_date changes
        $oldStartDate = $originalData['start_date'] ?? null;
        $newStartDate = $updatedIssue->getStartDate()?->format('Y-m-d');
        if ($oldStartDate !== $newStartDate) {
            $changes['start_date'] = [
                'old' => $oldStartDate,
                'new' => $newStartDate
            ];
        }

        // Track due_date changes
        $oldDueDate = $originalData['due_date'] ?? null;
        $newDueDate = $updatedIssue->getDueDate()?->format('Y-m-d');
        if ($oldDueDate !== $newDueDate) {
            $changes['due_date'] = [
                'old' => $oldDueDate,
                'new' => $newDueDate
            ];
        }

        return $changes;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Admin\IssueStatuses;

use App\Entity\IssueStatus;
use App\Service\Admin\ActsAsPositioned;
use App\Service\Admin\WorkflowService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saves and deletes issue statuses (Redmine's IssueStatus model callbacks): position in the list, closing
 * date of the issues of a status that becomes a closed one, deletion rules, and the done ratios of issues
 * taken from their status.
 */
final class IssueStatusManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkflowService $workflows,
        private readonly ActsAsPositioned $positioned,
        private readonly Settings $settings,
    ) {
    }

    /** Whether the done ratio of issues is the one of their status (Issue.use_status_for_done_ratio?) */
    public function useStatusForDoneRatio(): bool
    {
        return 'issue_status' === $this->settings->string('issue_done_ratio');
    }

    public function create(IssueStatus $status): void
    {
        $status->position ??= $this->positioned->nextPosition('issue_statuses');
        $this->em->persist($status);
        $this->em->flush();
    }

    /**
     * @param int|null $oldPosition position of the status before it was changed
     * @param bool     $wasClosed   whether the status was a closed one before it was changed
     */
    public function update(IssueStatus $status, ?int $oldPosition, bool $wasClosed): void
    {
        $this->em->flush();
        if (null !== $status->position && $oldPosition !== $status->position) {
            $this->positioned->positionChanged('issue_statuses', (int) $status->id, $oldPosition, $status->position);
        }
        if (!$wasClosed && $status->isClosed) {
            $this->handleIsClosedChange($status);
        }
    }

    /**
     * Deletes the status with its workflow, unless issues have it or a tracker starts with it.
     *
     * @return string|null label of the reason why the status was left untouched, null when it was deleted
     */
    public function destroy(IssueStatus $status): ?string
    {
        $conn = $this->em->getConnection();
        if (false !== $conn->fetchOne('SELECT 1 FROM issues WHERE status_id = ? LIMIT 1', [$status->id])) {
            return 'error_issue_status_in_use_by_issues';
        }
        if (false !== $conn->fetchOne('SELECT 1 FROM trackers WHERE default_status_id = ? LIMIT 1', [$status->id])) {
            return 'error_issue_status_default_for_trackers';
        }
        $sid = (int) $status->id;
        $position = $status->position;
        $this->workflows->deleteForStatus($sid);
        $this->em->remove($status);
        $this->em->flush();
        $this->positioned->removePosition('issue_statuses', $sid, $position);

        return null;
    }

    /**
     * IssueStatus.update_issue_done_ratios: gives every issue the done ratio of its status.
     *
     * @return bool false when done ratios do not come from the statuses: nothing was updated
     */
    public function updateIssueDoneRatios(): bool
    {
        if (!$this->useStatusForDoneRatio()) {
            return false;
        }
        $conn = $this->em->getConnection();
        foreach ($conn->fetchAllAssociative('SELECT id, default_done_ratio FROM issue_statuses WHERE default_done_ratio >= 0') as $row) {
            $conn->executeStatement('UPDATE issues SET done_ratio = ? WHERE status_id = ?', [(int) $row['default_done_ratio'], (int) $row['id']]);
        }

        return true;
    }

    /** IssueStatus#handle_is_closed_change: sets closed_on of the issues having this status */
    private function handleIsClosedChange(IssueStatus $status): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "UPDATE issues SET closed_on = (SELECT MAX(j.created_on) FROM journals j JOIN journal_details d ON d.journal_id = j.id WHERE j.journalized_type = 'Issue' AND j.journalized_id = issues.id AND d.property = 'attr' AND d.prop_key = 'status_id' AND d.value = ?) WHERE status_id = ? AND closed_on IS NULL",
            [(string) $status->id, $status->id],
        );
        $conn->executeStatement('UPDATE issues SET closed_on = created_on WHERE status_id = ? AND closed_on IS NULL', [$status->id]);
    }
}

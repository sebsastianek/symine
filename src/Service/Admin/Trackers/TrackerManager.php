<?php

declare(strict_types=1);

namespace App\Service\Admin\Trackers;

use App\Entity\Tracker;
use App\Service\Admin\ActsAsPositioned;
use App\Service\Admin\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saves and deletes trackers (Redmine's Tracker model callbacks): position in the list, custom fields and
 * projects of the tracker, workflow copied to a new tracker, and what goes away with a deleted tracker.
 */
final class TrackerManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkflowService $workflows,
        private readonly ActsAsPositioned $positioned,
        private readonly TrackerFinder $finder,
    ) {
    }

    /**
     * @param list<int>|null $customFieldIds    null: leave the custom fields as they are
     * @param list<int>|null $projectIds        null: leave the projects as they are
     * @param int            $copyWorkflowFrom id of the tracker whose workflow the new tracker gets (0: none)
     */
    public function create(Tracker $tracker, ?array $customFieldIds, ?array $projectIds, int $copyWorkflowFrom = 0): void
    {
        $tracker->position ??= $this->positioned->nextPosition('trackers');
        $this->em->persist($tracker);
        $this->em->flush();
        $this->saveAssociations($tracker, $customFieldIds, $projectIds);
        if ($copyWorkflowFrom && ($source = $this->em->find(Tracker::class, $copyWorkflowFrom))) {
            $this->workflows->copy($source, null, [$tracker], []);
        }
    }

    /**
     * @param int|null       $oldPosition    position of the tracker before it was changed
     * @param list<int>|null $customFieldIds null: leave the custom fields as they are
     * @param list<int>|null $projectIds     null: leave the projects as they are
     */
    public function update(Tracker $tracker, ?int $oldPosition, ?array $customFieldIds, ?array $projectIds): void
    {
        $this->em->flush();
        $this->saveAssociations($tracker, $customFieldIds, $projectIds);
        if (null !== $tracker->position && $oldPosition !== $tracker->position) {
            $this->positioned->positionChanged('trackers', (int) $tracker->id, $oldPosition, $tracker->position);
        }
    }

    /**
     * Deletes the tracker with its workflow, unless issues use it.
     *
     * @return bool false when the tracker has issues and was left untouched
     */
    public function destroy(Tracker $tracker): bool
    {
        if ($this->finder->hasIssues($tracker)) {
            return false;
        }
        $conn = $this->em->getConnection();
        $tid = (int) $tracker->id;
        $position = $tracker->position;
        $this->workflows->deleteForTracker($tid);
        $conn->executeStatement('DELETE FROM projects_trackers WHERE tracker_id = ?', [$tid]);
        $conn->executeStatement('DELETE FROM custom_fields_trackers WHERE tracker_id = ?', [$tid]);
        $this->em->remove($tracker);
        $this->em->flush();
        $this->positioned->removePosition('trackers', $tid, $position);

        return true;
    }

    /**
     * Replaces the issue custom fields and/or the projects of the tracker; ids that do not exist are ignored.
     *
     * @param list<int>|null $customFieldIds null: leave the custom fields as they are
     * @param list<int>|null $projectIds     null: leave the projects as they are
     */
    public function saveAssociations(Tracker $tracker, ?array $customFieldIds, ?array $projectIds): void
    {
        $conn = $this->em->getConnection();
        if (null !== $customFieldIds) {
            $conn->executeStatement('DELETE FROM custom_fields_trackers WHERE tracker_id = ?', [$tracker->id]);
            $valid = $conn->fetchFirstColumn("SELECT id FROM custom_fields WHERE type = 'IssueCustomField'");
            foreach ($customFieldIds as $cf) {
                if (\in_array($cf, array_map('intval', $valid), true)) {
                    $conn->insert('custom_fields_trackers', ['custom_field_id' => $cf, 'tracker_id' => $tracker->id]);
                }
            }
        }
        if (null !== $projectIds) {
            $conn->executeStatement('DELETE FROM projects_trackers WHERE tracker_id = ?', [$tracker->id]);
            $valid = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM projects'));
            foreach ($projectIds as $pid) {
                if (\in_array($pid, $valid, true)) {
                    $conn->insert('projects_trackers', ['project_id' => $pid, 'tracker_id' => $tracker->id]);
                }
            }
        }
        $this->em->refresh($tracker);
    }
}

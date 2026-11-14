<?php

declare(strict_types=1);

namespace App\Service\Admin\Workflows;

use App\Entity\IssueStatus;
use App\Entity\Role;
use App\Entity\Tracker;

/** The roles, trackers and statuses a workflow page is asked to show or change. */
final class WorkflowSelection
{
    /**
     * @param list<Role>|null    $roles            null: no role selected
     * @param list<Tracker>|null $trackers         null: no tracker selected
     * @param list<IssueStatus>  $statuses
     * @param bool               $usedStatusesOnly whether only the statuses used by the trackers are shown
     */
    public function __construct(
        public readonly ?array $roles,
        public readonly ?array $trackers,
        public readonly array $statuses,
        public readonly bool $usedStatusesOnly,
    ) {
    }

    /** Whether both roles and trackers are selected: there is a workflow to show or change */
    public function isComplete(): bool
    {
        return null !== $this->roles && null !== $this->trackers;
    }
}

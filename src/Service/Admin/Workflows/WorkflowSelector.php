<?php

declare(strict_types=1);

namespace App\Service\Admin\Workflows;

use App\Entity\IssueStatus;
use App\Entity\Role;
use App\Entity\Tracker;
use App\Service\Admin\Roles\RoleNames;
use App\Service\Admin\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The role / tracker / status selection shared by the workflow pages: reads it from the parameters
 * (find_roles, find_trackers, find_statuses) and gives the selectors of the pages their variables.
 */
final class WorkflowSelector
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkflowService $workflows,
        private readonly RoleNames $roleNames,
    ) {
    }

    /**
     * find_roles / find_trackers / find_statuses
     *
     * @param array<string, mixed> $p              role_id[], tracker_id[] ("all" for every one) and used_statuses_only
     * @param bool                 $defaultToFirst a page opened without a selection starts with the first role and the
     *                                             first tracker, the ones its selectors show. Symine only: Redmine shows
     *                                             nothing until "Edit" is pressed, which leaves the selectors pointing at
     *                                             something the page is not displaying. Never used when saving.
     */
    public function select(array $p, bool $defaultToFirst = false): WorkflowSelection
    {
        if ($defaultToFirst) {
            if (!\array_key_exists('role_id', $p) && null !== ($first = $this->workflows->workflowRoles()[0] ?? null)) {
                $p['role_id'] = [(string) $first->id];
            }
            if (!\array_key_exists('tracker_id', $p) && null !== ($first = $this->workflows->sortedTrackers()[0] ?? null)) {
                $p['tracker_id'] = [(string) $first->id];
            }
        }
        $roleIds = array_values(array_filter(array_map('strval', (array) ($p['role_id'] ?? [])), static fn ($v) => '' !== $v));
        $roles = null;
        if (['all'] === $roleIds) {
            $roles = $this->workflows->workflowRoles();
        } elseif ([] !== $roleIds) {
            $roles = $this->em->getRepository(Role::class)->findBy(['id' => array_map('intval', $roleIds)], ['builtin' => 'ASC', 'position' => 'ASC']);
        }
        $roles = [] === $roles ? null : $roles;

        $trackerIds = array_values(array_filter(array_map('strval', (array) ($p['tracker_id'] ?? [])), static fn ($v) => '' !== $v));
        $trackers = null;
        if (['all'] === $trackerIds) {
            $trackers = $this->workflows->sortedTrackers();
        } elseif ([] !== $trackerIds) {
            $trackers = $this->em->getRepository(Tracker::class)->findBy(['id' => array_map('intval', $trackerIds)], ['position' => 'ASC']);
        }
        $trackers = [] === $trackers ? null : $trackers;

        $usedOnly = '0' !== (string) ($p['used_statuses_only'] ?? '');
        $statuses = null;
        if (null !== $trackers && $usedOnly) {
            $ids = $this->workflows->usedStatusIds(array_map(static fn (Tracker $t) => (int) $t->id, $trackers));
            if ([] !== $ids) {
                $statuses = $this->em->getRepository(IssueStatus::class)->findBy(['id' => $ids], ['position' => 'ASC']);
            }
        }
        $statuses ??= $this->workflows->sortedStatuses();

        return new WorkflowSelection($roles, $trackers, $statuses, $usedOnly);
    }

    /**
     * Common variables of the edit / permissions pages (role/tracker selectors)
     *
     * @param mixed $usedStatusesOnlyParam the used_statuses_only parameter as it was given
     *
     * @return array<string, mixed>
     */
    public function view(WorkflowSelection $selection, mixed $usedStatusesOnlyParam): array
    {
        $roles = $selection->roles;
        $trackers = $selection->trackers;
        $allRoles = $this->workflows->workflowRoles();
        $allTrackers = $this->workflows->sortedTrackers();
        $selParams = [
            'role_id' => null === $roles ? [] : array_map(static fn ($r) => $r->id, $roles),
            'tracker_id' => null === $trackers ? [] : array_map(static fn ($t) => $t->id, $trackers),
        ];

        return [
            'roles' => $roles,
            'trackers' => $trackers,
            'statuses' => $selection->statuses,
            'used_statuses_only' => $selection->usedStatusesOnly,
            'used_statuses_only_param' => $usedStatusesOnlyParam,
            'role_select' => $this->workflowSelect($allRoles, $roles),
            'tracker_select' => $this->workflowSelect($allTrackers, $trackers),
            'tab_query' => self::railsQuery($selParams),
        ];
    }

    /**
     * options_for_workflow_select: [multiple, all_selected, all_hidden, options => [[id, name, selected]]]
     *
     * @param list<Role>|list<Tracker>      $objects
     * @param list<Role>|list<Tracker>|null $selected
     *
     * @return array{multiple: bool, all_selected: bool, options: list<array{id: ?int, name: ?string, selected: bool}>}
     */
    private function workflowSelect(array $objects, ?array $selected): array
    {
        $multiple = false;
        if (null !== $selected) {
            if (\count($selected) === \count($objects)) {
                $sel = 'all';
            } else {
                $sel = array_map(static fn ($o) => $o->id, $selected);
                $multiple = \count($sel) > 1;
            }
        } else {
            $sel = isset($objects[0]) ? [$objects[0]->id] : [];
        }
        $options = [];
        foreach ($objects as $o) {
            $options[] = ['id' => $o->id, 'name' => $o instanceof Role ? $this->roleNames->of($o) : $o->name, 'selected' => \is_array($sel) && \in_array($o->id, $sel, true)];
        }

        return ['multiple' => $multiple, 'all_selected' => 'all' === $sel, 'options' => $options];
    }

    /**
     * Rails-like query string (role_id[]=1&tracker_id[]=2)
     *
     * @param array<string, list<int|null>> $params
     */
    private static function railsQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $k => $values) {
            foreach ($values as $v) {
                $parts[] = rawurlencode($k.'[]').'='.rawurlencode((string) $v);
            }
        }

        return [] === $parts ? '' : '?'.implode('&', $parts);
    }
}

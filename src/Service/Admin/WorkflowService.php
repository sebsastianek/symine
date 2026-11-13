<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\IssueStatus;
use App\Entity\Role;
use App\Entity\Tracker;
use App\Entity\WorkflowPermission;
use App\Entity\WorkflowRule;
use App\Entity\WorkflowTransition;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Workflow rules administration - port of WorkflowRule.copy, WorkflowTransition.replace_transitions
 * and WorkflowPermission.rules_by_status_id / replace_permissions (the "workflows" table).
 */
final class WorkflowService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<Role> Role.sorted (builtin, position) */
    public function sortedRoles(): array
    {
        return $this->em->createQuery('SELECT r FROM App\Entity\Role r ORDER BY r.builtin ASC, r.position ASC')->getResult();
    }

    /** @return list<Role> Role.sorted.select(&:consider_workflow?) */
    public function workflowRoles(): array
    {
        return array_values(array_filter($this->sortedRoles(), static fn (Role $r) => $r->considerWorkflow()));
    }

    /** @return list<Tracker> */
    public function sortedTrackers(): array
    {
        return $this->em->getRepository(Tracker::class)->findBy([], ['position' => 'ASC']);
    }

    /** @return list<IssueStatus> */
    public function sortedStatuses(): array
    {
        return $this->em->getRepository(IssueStatus::class)->findBy([], ['position' => 'ASC']);
    }

    /** WorkflowTransition.group(:tracker_id, :role_id).count → [trackerId][roleId] => count */
    public function transitionCounts(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative("SELECT tracker_id, role_id, COUNT(*) AS c FROM workflows WHERE type = 'WorkflowTransition' GROUP BY tracker_id, role_id");
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['tracker_id']][(int) $r['role_id']] = (int) $r['c'];
        }

        return $out;
    }

    public function roleHasRules(Role $role): bool
    {
        return false !== $this->em->getConnection()->fetchOne('SELECT 1 FROM workflows WHERE role_id = ? LIMIT 1', [$role->id]);
    }

    public function trackerHasRules(Tracker $tracker): bool
    {
        return false !== $this->em->getConnection()->fetchOne('SELECT 1 FROM workflows WHERE tracker_id = ? LIMIT 1', [$tracker->id]);
    }

    /** @return list<int> statuses having a transition (old_status_id or new_status_id) */
    public function statusIdsWithTransitions(): array
    {
        $ids = $this->em->getConnection()->fetchFirstColumn("SELECT old_status_id FROM workflows WHERE type = 'WorkflowTransition' UNION SELECT new_status_id FROM workflows WHERE type = 'WorkflowTransition'");

        return array_values(array_map('intval', $ids));
    }

    /**
     * Status ids used by the workflows of the trackers (transitions where old <> new) for roles that consider workflow.
     *
     * @param list<int> $trackerIds
     *
     * @return list<int>
     */
    public function usedStatusIds(array $trackerIds): array
    {
        $roleIds = array_map(static fn (Role $r) => $r->id, $this->workflowRoles());
        if ([] === $trackerIds || [] === $roleIds) {
            return [];
        }
        $rows = $this->em->getConnection()->executeQuery(
            "SELECT DISTINCT old_status_id, new_status_id FROM workflows WHERE type = 'WorkflowTransition' AND tracker_id IN (?) AND role_id IN (?) AND old_status_id <> new_status_id",
            [$trackerIds, $roleIds],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER],
        )->fetchAllNumeric();
        $ids = [];
        foreach ($rows as [$o, $n]) {
            $ids[(int) $o] = true;
            $ids[(int) $n] = true;
        }

        return array_keys($ids);
    }

    /**
     * Transitions of trackers × roles split like WorkflowsController#edit.
     *
     * @param list<Tracker> $trackers
     * @param list<Role>    $roles
     *
     * @return array{always: array<string, int>, author: array<string, int>, assignee: array<string, int>} "old-new" => count
     */
    public function transitionCountsByRule(array $trackers, array $roles): array
    {
        $out = ['always' => [], 'author' => [], 'assignee' => []];
        foreach ($this->transitions($trackers, $roles) as $w) {
            $key = $w->oldStatusId.'-'.$w->newStatusId;
            if (!$w->author && !$w->assignee) {
                $out['always'][$key] = ($out['always'][$key] ?? 0) + 1;
            }
            if ($w->author) {
                $out['author'][$key] = ($out['author'][$key] ?? 0) + 1;
            }
            if ($w->assignee) {
                $out['assignee'][$key] = ($out['assignee'][$key] ?? 0) + 1;
            }
        }

        return $out;
    }

    /** @return list<WorkflowTransition> */
    private function transitions(array $trackers, array $roles): array
    {
        if ([] === $trackers || [] === $roles) {
            return [];
        }

        return $this->em->createQuery('SELECT w FROM App\Entity\WorkflowTransition w WHERE w.trackerId IN (:t) AND w.roleId IN (:r) ORDER BY w.id')
            ->setParameters(['t' => self::ids($trackers), 'r' => self::ids($roles)])->getResult();
    }

    /** Adds a single transition (used by the default data loader). */
    public function addTransition(int $trackerId, int $roleId, int $oldStatusId, int $newStatusId, bool $author = false, bool $assignee = false): WorkflowTransition
    {
        $w = new WorkflowTransition();
        $w->trackerId = $trackerId;
        $w->roleId = $roleId;
        $w->oldStatusId = $oldStatusId;
        $w->newStatusId = $newStatusId;
        $w->author = $author;
        $w->assignee = $assignee;
        $this->em->persist($w);

        return $w;
    }

    /**
     * WorkflowTransition.replace_transitions(trackers, roles, transitions)
     * $transitions: [old_status_id => [new_status_id => [rule (always|author|assignee) => '0'|'1']]] ('no_change' values must be removed before).
     *
     * @param list<Tracker> $trackers
     * @param list<Role>    $roles
     */
    public function replaceTransitions(array $trackers, array $roles, array $transitions): void
    {
        $this->em->wrapInTransaction(function () use ($trackers, $roles, $transitions): void {
            $records = [];
            foreach ($this->transitions($trackers, $roles) as $r) {
                $records[$r->oldStatusId.'-'.$r->newStatusId.'-'.$r->trackerId.'-'.$r->roleId][] = $r;
            }
            $destroyed = new \SplObjectStorage();
            foreach ($transitions as $oldStatusId => $byNewStatus) {
                if (!\is_array($byNewStatus)) {
                    continue;
                }
                foreach ($byNewStatus as $newStatusId => $byRule) {
                    if (!\is_array($byRule)) {
                        continue;
                    }
                    $oldId = (int) $oldStatusId;
                    $newId = (int) $newStatusId;
                    foreach ($byRule as $rule => $transition) {
                        foreach ($trackers as $tracker) {
                            foreach ($roles as $role) {
                                $key = $oldId.'-'.$newId.'-'.$tracker->id.'-'.$role->id;
                                $w = array_values(array_filter($records[$key] ?? [], static fn ($r) => !$destroyed->offsetExists($r)));
                                $w = 'always' === $rule
                                    ? array_values(array_filter($w, static fn ($r) => !$r->author && !$r->assignee))
                                    : array_values(array_filter($w, static fn ($r) => $r->author || $r->assignee));
                                foreach (\array_slice($w, 1) as $extra) {
                                    $this->em->remove($extra);
                                    $destroyed->offsetSet($extra);
                                }
                                $w = $w[0] ?? null;
                                if (\in_array($transition, ['1', 1, true], true)) {
                                    if (null === $w) {
                                        $w = $this->addTransition((int) $tracker->id, (int) $role->id, $oldId, $newId);
                                        $records[$key][] = $w;
                                    }
                                    if ('author' === $rule) {
                                        $w->author = true;
                                    }
                                    if ('assignee' === $rule) {
                                        $w->assignee = true;
                                    }
                                } elseif (null !== $w) {
                                    if ('always' === $rule) {
                                        $this->em->remove($w);
                                        $destroyed->offsetSet($w);
                                    } elseif ('author' === $rule) {
                                        if ($w->assignee) {
                                            $w->author = false;
                                        } else {
                                            $this->em->remove($w);
                                            $destroyed->offsetSet($w);
                                        }
                                    } elseif ('assignee' === $rule) {
                                        if ($w->author) {
                                            $w->assignee = false;
                                        } else {
                                            $this->em->remove($w);
                                            $destroyed->offsetSet($w);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $this->em->flush();
        });
    }

    /**
     * WorkflowPermission.rules_by_status_id: [status_id => [field_name => list<rule>]]
     *
     * @param list<Tracker> $trackers
     * @param list<Role>    $roles
     */
    public function rulesByStatusId(array $trackers, array $roles): array
    {
        if ([] === $trackers || [] === $roles) {
            return [];
        }
        $out = [];
        $rows = $this->em->createQuery('SELECT w FROM App\Entity\WorkflowPermission w WHERE w.trackerId IN (:t) AND w.roleId IN (:r) ORDER BY w.id')
            ->setParameters(['t' => self::ids($trackers), 'r' => self::ids($roles)])->getResult();
        foreach ($rows as $w) {
            $out[$w->oldStatusId][(string) $w->fieldName][] = (string) $w->rule;
        }

        return $out;
    }

    /**
     * WorkflowPermission.replace_permissions: [status_id => [field_name => '' | readonly | required]]
     * ('no_change' values must be removed before).
     *
     * @param list<Tracker> $trackers
     * @param list<Role>    $roles
     */
    public function replacePermissions(array $trackers, array $roles, array $permissions): void
    {
        if ([] === $trackers || [] === $roles) {
            return;
        }
        $this->em->wrapInTransaction(function () use ($trackers, $roles, $permissions): void {
            $conn = $this->em->getConnection();
            foreach ($permissions as $statusId => $ruleByField) {
                if (!\is_array($ruleByField)) {
                    continue;
                }
                foreach ($ruleByField as $field => $rule) {
                    $field = (string) $field;
                    $conn->executeStatement(
                        "DELETE FROM workflows WHERE type = 'WorkflowPermission' AND tracker_id IN (?) AND role_id IN (?) AND old_status_id = ? AND field_name = ?",
                        [self::ids($trackers), self::ids($roles), (int) $statusId, $field],
                        [\Doctrine\DBAL\ArrayParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER],
                    );
                    $rule = (string) $rule;
                    if ('' === $rule || !\in_array($rule, WorkflowPermission::RULES, true)) {
                        continue;
                    }
                    if (!\in_array($field, [...Tracker::CORE_FIELDS_UNDISABLABLE, ...Tracker::CORE_FIELDS], true) && !ctype_digit($field)) {
                        continue;
                    }
                    foreach ($trackers as $tracker) {
                        foreach ($roles as $role) {
                            $w = new WorkflowPermission();
                            $w->roleId = (int) $role->id;
                            $w->trackerId = (int) $tracker->id;
                            $w->oldStatusId = (int) $statusId;
                            $w->fieldName = $field;
                            $w->rule = $rule;
                            $this->em->persist($w);
                        }
                    }
                }
            }
            $this->em->flush();
        });
    }

    /**
     * WorkflowRule.copy(source_tracker, source_role, target_trackers, target_roles).
     * Null source = "same as target"; empty targets = all trackers / all workflow roles.
     *
     * @param list<Tracker> $targetTrackers
     * @param list<Role>    $targetRoles
     */
    public function copy(?Tracker $sourceTracker, ?Role $sourceRole, array $targetTrackers = [], array $targetRoles = []): void
    {
        if (null === $sourceTracker && null === $sourceRole) {
            throw new \InvalidArgumentException('source_tracker or source_role must be specified');
        }
        $targetTrackers = [] === $targetTrackers ? $this->sortedTrackers() : $targetTrackers;
        $targetRoles = [] === $targetRoles ? array_values(array_filter($this->em->getRepository(Role::class)->findAll(), static fn (Role $r) => $r->considerWorkflow())) : $targetRoles;
        foreach ($targetTrackers as $tt) {
            foreach ($targetRoles as $tr) {
                $this->copyOne($sourceTracker ?? $tt, $sourceRole ?? $tr, $tt, $tr);
            }
        }
    }

    /** WorkflowRule.copy_one: replaces all rules of target tracker/role with those of the source */
    public function copyOne(Tracker $sourceTracker, Role $sourceRole, Tracker $targetTracker, Role $targetRole): bool
    {
        if (null === $sourceTracker->id || null === $sourceRole->id || null === $targetTracker->id || null === $targetRole->id) {
            throw new \InvalidArgumentException('arguments can not be nil or unsaved objects');
        }
        if ($sourceTracker->id === $targetTracker->id && $sourceRole->id === $targetRole->id) {
            return false;
        }
        $conn = $this->em->getConnection();
        $conn->transactional(static function (\Doctrine\DBAL\Connection $conn) use ($sourceTracker, $sourceRole, $targetTracker, $targetRole): void {
            $conn->executeStatement('DELETE FROM workflows WHERE tracker_id = ? AND role_id = ?', [$targetTracker->id, $targetRole->id]);
            $conn->executeStatement(
                'INSERT INTO workflows (tracker_id, role_id, old_status_id, new_status_id, author, assignee, field_name, '.$conn->quoteSingleIdentifier('rule').', type)'
                .' SELECT ?, ?, old_status_id, new_status_id, author, assignee, field_name, '.$conn->quoteSingleIdentifier('rule').', type FROM workflows WHERE tracker_id = ? AND role_id = ?',
                [$targetTracker->id, $targetRole->id, $sourceTracker->id, $sourceRole->id],
            );
        });

        return true;
    }

    public function deleteForRole(int $roleId): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM workflows WHERE role_id = ?', [$roleId]);
    }

    public function deleteForTracker(int $trackerId): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM workflows WHERE tracker_id = ?', [$trackerId]);
    }

    public function deleteForStatus(int $statusId): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM workflows WHERE old_status_id = ? OR new_status_id = ?', [$statusId, $statusId]);
    }

    /** @return list<int> */
    private static function ids(array $objects): array
    {
        return array_values(array_map(static fn ($o) => (int) $o->id, $objects));
    }
}

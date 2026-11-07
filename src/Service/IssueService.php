<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Attachment;
use App\Entity\Enumeration;
use App\Entity\Group;
use App\Entity\Issue;
use App\Entity\IssueCategory;
use App\Entity\IssueCustomField;
use App\Entity\IssuePriority;
use App\Entity\IssueRelation;
use App\Entity\IssueStatus;
use App\Entity\Journal;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\TimeEntry;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Version;
use App\Entity\Watcher;
use App\Entity\WorkflowPermission;
use App\Event\IssueEvent;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The ticketing flow - a port of Redmine's Issue model behaviour:
 * permissions/visibility, workflow (status transitions, read-only/required fields),
 * safe attribute assignment, validation, journalizing, subtasks (derived attributes),
 * relations (blocking, preceding/rescheduling, duplicates), watchers and copy/move/delete.
 */
final class IssueService
{
    /** Attributes journalized as "attr" details (same keys as Redmine) */
    public const array JOURNALIZED_ATTRIBUTES = [
        'project_id', 'tracker_id', 'subject', 'description', 'category_id', 'status_id', 'assigned_to_id',
        'priority_id', 'fixed_version_id', 'parent_id', 'start_date', 'due_date', 'done_ratio', 'estimated_hours', 'is_private',
    ];

    /** @var array<string, array<string, string>> per-issue/user cache of workflow rules */
    private array $workflowRulesCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly ProjectService $projects,
        private readonly CustomFieldService $customFields,
        private readonly Localizer $i18n,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    // ------------------------------------------------------------------------------------------
    // Visibility & permissions
    // ------------------------------------------------------------------------------------------

    /** DQL condition for issues visible by the user (Issue.visible_condition). $alias is the Issue alias. */
    public function visibleCondition(?User $user = null, string $alias = 'i', bool $skipPreCondition = false): string
    {
        $user = $this->auth->user($user);
        $groupIds = $user->isLogged() ? $user->getGroupIds() : [];

        return $this->auth->allowedToCondition('view_issues', "IDENTITY($alias.project)", $user, function (Role $role, User $u) use ($alias, $groupIds) {
            if ($u->id && $u->isLogged()) {
                $userIds = implode(',', array_map('intval', [$u->id, ...$groupIds]));
                $sql = match ($role->issuesVisibility) {
                    'all' => '1=1',
                    'default' => "($alias.isPrivate = false OR IDENTITY($alias.author) = {$u->id} OR IDENTITY($alias.assignedTo) IN ($userIds))",
                    'own' => "(IDENTITY($alias.author) = {$u->id} OR IDENTITY($alias.assignedTo) IN ($userIds))",
                    default => '1=0',
                };
            } else {
                $sql = "($alias.isPrivate = false)";
            }
            if (!$role->permissionsAllTrackers('view_issues')) {
                $trackerIds = $role->permissionsTrackerIds('view_issues');
                $sql = [] !== $trackerIds ? "($sql AND IDENTITY($alias.tracker) IN (".implode(',', $trackerIds).'))' : '1=0';
            }

            return $sql;
        }, false, $skipPreCondition);
    }

    public function isVisible(Issue $issue, ?User $user = null): bool
    {
        $user = $this->auth->user($user);

        return $this->auth->allowedTo('view_issues', $issue->project, $user, false, function (Role $role, User $u) use ($issue) {
            if ($u->isLogged()) {
                $visible = match ($role->issuesVisibility) {
                    'all' => true,
                    'default' => !$issue->isPrivate || $issue->author->id === $u->id || $u->isOrBelongsTo($issue->assignedTo),
                    'own' => $issue->author->id === $u->id || $u->isOrBelongsTo($issue->assignedTo),
                    default => false,
                };
            } else {
                $visible = !$issue->isPrivate;
            }
            if (!$role->permissionsAllTrackers('view_issues')) {
                $visible = $visible && $role->permissionsTrackerIdsHas('view_issues', (int) $issue->tracker->id);
            }

            return $visible;
        });
    }

    /** User has the permission on the issue's project for the issue's tracker */
    public function userTrackerPermission(Issue $issue, string $permission, ?User $user = null): bool
    {
        $user = $this->auth->user($user);
        if (!isset($issue->project)) {
            return false;
        }
        if ($user->admin) {
            return $this->auth->allowedTo($permission, $issue->project, $user);
        }
        $trackerId = isset($issue->tracker) ? (int) $issue->tracker->id : 0;
        foreach ($this->auth->rolesForProject($issue->project, $user) as $role) {
            if ($this->auth->roleAllowedTo($role, $permission) && $this->auth->allowedTo($permission, $issue->project, $user)
                && ($role->permissionsAllTrackers($permission) || $role->permissionsTrackerIdsHas($permission, $trackerId))) {
                return true;
            }
        }

        return false;
    }

    public function isAttributesEditable(Issue $issue, ?User $user = null): bool
    {
        $user = $this->auth->user($user);

        return $this->userTrackerPermission($issue, 'edit_issues', $user)
            || ($this->userTrackerPermission($issue, 'edit_own_issues', $user) && isset($issue->author) && $issue->author->id === $user->id);
    }

    public function isNotesAddable(Issue $issue, ?User $user = null): bool
    {
        return $this->userTrackerPermission($issue, 'add_issue_notes', $user);
    }

    public function isEditable(Issue $issue, ?User $user = null): bool
    {
        return $this->isAttributesEditable($issue, $user) || $this->isNotesAddable($issue, $user);
    }

    public function isDeletable(Issue $issue, ?User $user = null): bool
    {
        return $this->userTrackerPermission($issue, 'delete_issues', $user);
    }

    public function isCopyable(Issue $issue, ?User $user = null): bool
    {
        return $this->userTrackerPermission($issue, 'copy_issues', $user) && $this->userTrackerPermission($issue, 'add_issues', $user);
    }

    public function attachmentsDeletable(Issue $issue, ?User $user = null): bool
    {
        return $this->isVisible($issue, $user) && $this->isAttributesEditable($issue, $user);
    }

    public function isTimeLoggable(Issue $issue, ?User $user = null): bool
    {
        return $this->auth->allowedTo('log_time', $issue->project, $user) && ($this->settings->bool('timelog_accept_closed_issues') || !$issue->isClosed());
    }

    public function canViewPrivateNotes(Issue $issue, ?User $user = null): bool
    {
        return $this->auth->allowedTo('view_private_notes', $issue->project, $user);
    }

    public function isJournalEditable(Journal $journal, ?User $user = null): bool
    {
        $user = $this->auth->user($user);
        $project = $journal->issue->project;

        return $user->isLogged() && ($this->auth->allowedTo('edit_issue_notes', $project, $user)
            || ($journal->user->id === $user->id && $this->auth->allowedTo('edit_own_issue_notes', $project, $user)));
    }

    // ------------------------------------------------------------------------------------------
    // Building issues
    // ------------------------------------------------------------------------------------------

    public function defaultPriority(): ?IssuePriority
    {
        return $this->em->getRepository(IssuePriority::class)->findOneBy(['isDefault' => true, 'active' => true])
            ?? $this->em->getRepository(IssuePriority::class)->findOneBy(['active' => true], ['position' => 'ASC']);
    }

    /** A new issue for the project with Redmine's defaults (tracker/status/priority/start date/version). */
    public function newIssue(Project $project, ?User $author = null): Issue
    {
        $author = $this->auth->user($author);
        $issue = new Issue();
        $issue->project = $project;
        $issue->author = $author;
        $trackers = $this->projects->allowedTargetTrackers($project, $author);
        if ($trackers) {
            $issue->tracker = $trackers[0];
            if ($trackers[0]->defaultStatus) {
                $issue->status = $trackers[0]->defaultStatus;
            }
            $issue->isPrivate = $trackers[0]->privateByDefault && $this->canSetPrivate($issue, $author);
        }
        if ($p = $this->defaultPriority()) {
            $issue->priority = $p;
        }
        if ($this->settings->bool('default_issue_start_date_to_creation_date')) {
            $issue->startDate = $this->i18n->today($author);
        }
        if ($project->defaultVersion && $project->defaultVersion->isOpen()) {
            foreach ($this->projects->sharedVersions($project) as $v) {
                if ($v->id === $project->defaultVersion->id) {
                    $issue->fixedVersion = $v;
                }
            }
        }
        if ($project->defaultAssignedTo && \in_array($project->defaultAssignedTo, $this->assignableUsers($issue), true)) {
            $issue->assignedTo = $project->defaultAssignedTo;
        }

        return $issue;
    }

    public function canSetPrivate(Issue $issue, ?User $user = null): bool
    {
        $user = $this->auth->user($user);

        return $this->auth->allowedTo('set_issues_private', $issue->project, $user)
            || ((!isset($issue->author) || $issue->author->id === $user->id) && $this->auth->allowedTo('set_own_issues_private', $issue->project, $user));
    }

    // ------------------------------------------------------------------------------------------
    // Workflow
    // ------------------------------------------------------------------------------------------

    /** @return list<Role> roles considered for workflow (Issue#roles_for_workflow) */
    public function rolesForWorkflow(Issue $issue, User $user): array
    {
        $roles = $user->admin ? $this->em->getRepository(Role::class)->findAll() : $this->auth->rolesForProject($issue->project, $user);

        return array_values(array_filter($roles, static fn (Role $r) => $r->considerWorkflow()));
    }

    /**
     * Statuses the user can apply to the issue (Issue#new_statuses_allowed_to).
     *
     * @param array{status_id?: int|null, tracker_id?: int|null, assigned_to_id?: int|null} $original values before the pending change
     *
     * @return list<IssueStatus>
     */
    public function newStatusesAllowedTo(Issue $issue, ?User $user = null, bool $includeDefault = false, array $original = []): array
    {
        $user = $this->auth->user($user);
        if (!isset($issue->tracker)) {
            return [];
        }
        $defaultStatus = $issue->tracker->defaultStatus;
        $initial = null;
        if (!$issue->isNew()) {
            $statusWas = \array_key_exists('status_id', $original) ? $this->em->find(IssueStatus::class, (int) $original['status_id']) : $issue->status;
            $trackerWasId = $original['tracker_id'] ?? $issue->tracker->id;
            if ($trackerWasId !== $issue->tracker->id) {
                $trackerWas = $this->em->find(Tracker::class, (int) $trackerWasId);
                if ($trackerWas && $trackerWas->defaultStatus && $statusWas && $trackerWas->defaultStatus->id === $statusWas->id) {
                    $initial = $defaultStatus;
                } elseif ($statusWas && \in_array($statusWas->id, $this->trackerStatusIds($issue->tracker), true)) {
                    $initial = $statusWas;
                } else {
                    $initial = $defaultStatus;
                }
            } else {
                $initial = $statusWas;
            }
        }
        $initialAssignedToId = \array_key_exists('assigned_to_id', $original) ? $original['assigned_to_id'] : $issue->assignedTo?->id;
        $assigneeTransitions = null !== $initialAssignedToId && ($user->id === $initialAssignedToId || \in_array($initialAssignedToId, $user->getGroupIds(), true));
        $isAuthor = isset($issue->author) && $issue->author->id === $user->id;

        $statuses = $this->statusesAllowedByWorkflow($initial, $this->rolesForWorkflow($issue, $user), $issue->tracker, $isAuthor, $assigneeTransitions);
        if ([] !== $statuses && $initial) {
            $statuses[] = $initial;
        }
        if ($defaultStatus && ($includeDefault || ($issue->isNew() && [] === $statuses))) {
            $statuses[] = $defaultStatus;
        }
        $unique = [];
        foreach ($statuses as $s) {
            if ($s) {
                $unique[$s->id] = $s;
            }
        }
        $statuses = array_values($unique);
        usort($statuses, static fn (IssueStatus $a, IssueStatus $b) => $a->position <=> $b->position);
        if (!$this->isClosable($issue)) {
            $statuses = array_values(array_filter($statuses, static fn (IssueStatus $s) => !$s->isClosed));
        }
        if (!$this->isReopenable($issue)) {
            $statuses = array_values(array_filter($statuses, static fn (IssueStatus $s) => $s->isClosed));
        }

        return $statuses;
    }

    /**
     * IssueStatus.new_statuses_allowed
     *
     * @param list<Role> $roles
     *
     * @return list<IssueStatus>
     */
    public function statusesAllowedByWorkflow(?IssueStatus $status, array $roles, Tracker $tracker, bool $author = false, bool $assignee = false): array
    {
        if ([] === $roles) {
            return [];
        }
        $qb = $this->em->createQueryBuilder()
            ->select('DISTINCT s')
            ->from(IssueStatus::class, 's')
            ->join(\App\Entity\WorkflowTransition::class, 'w', 'WITH', 'w.newStatusId = s.id')
            ->where('w.oldStatusId = :old AND w.roleId IN (:roles) AND w.trackerId = :tracker')
            ->setParameter('old', $status?->id ?? 0)
            ->setParameter('roles', array_map(static fn (Role $r) => $r->id, $roles))
            ->setParameter('tracker', $tracker->id);
        if (!($author && $assignee)) {
            if ($author || $assignee) {
                $qb->andWhere('w.author = :a OR w.assignee = :b')->setParameter('a', $author)->setParameter('b', $assignee);
            } else {
                $qb->andWhere('w.author = false AND w.assignee = false');
            }
        }
        $statuses = $qb->getQuery()->getResult();
        usort($statuses, static fn (IssueStatus $a, IssueStatus $b) => $a->position <=> $b->position);

        return $statuses;
    }

    /** @return list<int> statuses used by the tracker's workflow (Tracker#issue_status_ids) */
    public function trackerStatusIds(Tracker $tracker): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn(
            "SELECT DISTINCT old_status_id FROM workflows WHERE tracker_id = ? AND type = 'WorkflowTransition' UNION SELECT DISTINCT new_status_id FROM workflows WHERE tracker_id = ? AND type = 'WorkflowTransition'",
            [$tracker->id, $tracker->id]
        );
        $ids = array_values(array_filter(array_map('intval', $rows)));
        if ($tracker->defaultStatus) {
            $ids[] = $tracker->defaultStatus->id;
        }

        return array_values(array_unique($ids));
    }

    /** @return list<IssueStatus> */
    public function trackerStatuses(Tracker $tracker): array
    {
        $ids = $this->trackerStatusIds($tracker);

        return [] === $ids ? [] : $this->em->getRepository(IssueStatus::class)->findBy(['id' => $ids], ['position' => 'ASC']);
    }

    /** Issue#closable?: no open subtasks and not blocked */
    public function isClosable(Issue $issue): bool
    {
        foreach ($issue->getDescendants() as $d) {
            if (!$d->isClosed()) {
                return false;
            }
        }

        return !$issue->isBlocked();
    }

    public function closableWarning(Issue $issue): ?string
    {
        foreach ($issue->getDescendants() as $d) {
            if (!$d->isClosed()) {
                return $this->i18n->l('notice_issue_not_closable_by_open_tasks');
            }
        }
        if ($issue->isBlocked()) {
            return $this->i18n->l('notice_issue_not_closable_by_blocking_issue');
        }
        foreach ($issue->getAncestors() as $a) {
            if ($a->isClosed()) {
                return $this->i18n->l('notice_issue_not_reopenable_by_closed_parent_issue');
            }
        }

        return null;
    }

    /** Issue#reopenable?: no closed ancestor */
    public function isReopenable(Issue $issue): bool
    {
        foreach ($issue->getAncestors() as $a) {
            if ($a->isClosed()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Workflow field rules for the user on the issue's current tracker/status
     * (Issue#workflow_rule_by_attribute): attribute name or custom field id => readonly|required.
     *
     * @return array<string, string>
     */
    public function workflowRuleByAttribute(Issue $issue, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        if (!isset($issue->tracker, $issue->status)) {
            return [];
        }
        $key = spl_object_id($issue).':'.$user->id.':'.$issue->tracker->id.':'.$issue->status->id.':'.($issue->isNew() ? 'n' : 'e');
        if (isset($this->workflowRulesCache[$key])) {
            return $this->workflowRulesCache[$key];
        }
        $roles = $this->rolesForWorkflow($issue, $user);
        if ([] === $roles) {
            return $this->workflowRulesCache[$key] = [];
        }
        $result = [];
        $permissions = $this->em->getRepository(WorkflowPermission::class)->findBy([
            'trackerId' => $issue->tracker->id,
            'oldStatusId' => $issue->status->id,
            'roleId' => array_map(static fn (Role $r) => $r->id, $roles),
        ]);
        if ([] !== $permissions) {
            $rules = [];
            foreach ($permissions as $wp) {
                $rules[(string) $wp->fieldName][$wp->roleId] = (string) $wp->rule;
            }
            // custom fields not visible to a role are read-only for it
            $fieldsWithRoles = [];
            foreach ($this->em->getRepository(IssueCustomField::class)->findBy(['visible' => false]) as $cf) {
                $fieldsWithRoles[$cf->id] = array_map(static fn (Role $r) => $r->id, $cf->roles->toArray());
            }
            foreach ($roles as $role) {
                foreach ($fieldsWithRoles as $fieldId => $roleIds) {
                    if (!\in_array($role->id, $roleIds, true)) {
                        $rules[(string) $fieldId][$role->id] = 'readonly';
                    }
                }
            }
            foreach ($rules as $attr => $byRole) {
                if (\count($byRole) < \count($roles)) {
                    continue;
                }
                $uniq = array_values(array_unique($byRole));
                $result[(string) $attr] = 1 === \count($uniq) ? $uniq[0] : 'required';
            }
        }

        return $this->workflowRulesCache[$key] = $result;
    }

    /** @return list<string> */
    public function readOnlyAttributeNames(Issue $issue, ?User $user = null): array
    {
        return array_keys(array_filter($this->workflowRuleByAttribute($issue, $user), static fn ($r) => 'readonly' === $r));
    }

    /** @return list<string> */
    public function requiredAttributeNames(Issue $issue, ?User $user = null): array
    {
        $names = array_keys(array_filter($this->workflowRuleByAttribute($issue, $user), static fn ($r) => 'required' === $r));

        return array_map('strval', $names);
    }

    public function isRequiredAttribute(Issue $issue, string $name, ?User $user = null): bool
    {
        if ('subject' === $name || 'tracker_id' === $name || 'status_id' === $name || 'priority_id' === $name || 'project_id' === $name) {
            return true;
        }

        return \in_array($name, $this->requiredAttributeNames($issue, $user), true);
    }

    public function isDatesDerived(Issue $issue): bool
    {
        return !$issue->isLeaf() && 'derived' === $this->settings->string('parent_issue_dates');
    }

    public function isPriorityDerived(Issue $issue): bool
    {
        return !$issue->isLeaf() && 'derived' === $this->settings->string('parent_issue_priority');
    }

    public function isDoneRatioDerived(Issue $issue): bool
    {
        return !$issue->isLeaf() && 'derived' === $this->settings->string('parent_issue_done_ratio');
    }

    public function useStatusForDoneRatio(): bool
    {
        return 'issue_status' === $this->settings->string('issue_done_ratio');
    }

    /**
     * Attributes the user can set (Issue#safe_attribute_names).
     *
     * @return list<string>
     */
    public function safeAttributeNames(Issue $issue, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $names = [];
        if ($issue->isNew() || $this->isAttributesEditable($issue, $user)) {
            array_push($names, 'project_id', 'tracker_id', 'status_id', 'category_id', 'assigned_to_id', 'priority_id', 'fixed_version_id',
                'subject', 'description', 'start_date', 'due_date', 'done_ratio', 'estimated_hours', 'custom_field_values', 'lock_version');
        }
        if ($this->isNotesAddable($issue, $user)) {
            $names[] = 'notes';
        }
        if (!$issue->isNew() && $this->auth->allowedTo('set_notes_private', $issue->project, $user)) {
            $names[] = 'private_notes';
        }
        if ($issue->isNew() && $this->auth->allowedTo('add_issue_watchers', $issue->project, $user)) {
            $names[] = 'watcher_user_ids';
        }
        if ($this->canSetPrivate($issue, $user)) {
            $names[] = 'is_private';
        }
        if (($issue->isNew() || $this->isAttributesEditable($issue, $user)) && $this->auth->allowedTo('manage_subtasks', $issue->project, $user)) {
            $names[] = 'parent_issue_id';
        }
        if ($this->attachmentsDeletable($issue, $user)) {
            $names[] = 'deleted_attachment_ids';
        }
        $names = array_diff($names, isset($issue->tracker) ? $issue->tracker->getDisabledCoreFields() : []);
        if (\in_array('parent_issue_id', isset($issue->tracker) ? $issue->tracker->getDisabledCoreFields() : [], true)) {
            $names = array_diff($names, ['parent_issue_id']);
        }
        $names = array_diff($names, $this->readOnlyAttributeNames($issue, $user));
        if ($issue->isNew()) {
            $names[] = 'project_id';
        }
        if ($this->isDatesDerived($issue)) {
            $names = array_diff($names, ['start_date', 'due_date']);
        }
        if ($this->isPriorityDerived($issue)) {
            $names = array_diff($names, ['priority_id']);
        }
        if ($this->isDoneRatioDerived($issue)) {
            $names = array_diff($names, ['done_ratio']);
        }
        if ($this->useStatusForDoneRatio()) {
            $names = array_diff($names, ['done_ratio']);
        }

        return array_values(array_unique($names));
    }

    public function isSafeAttribute(Issue $issue, string $name, ?User $user = null): bool
    {
        return \in_array($name, $this->safeAttributeNames($issue, $user), true);
    }

    /**
     * Assigns user submitted attributes, respecting permissions and workflow (Issue#safe_attributes=).
     * Returns the "original" values needed by validation/journalizing.
     *
     * @param array<string, mixed> $attrs
     */
    public function setSafeAttributes(Issue $issue, array $attrs, ?User $user = null): void
    {
        $user = $this->auth->user($user);
        $original = $this->originalValues($issue);

        // project first
        if (\array_key_exists('project_id', $attrs) && $this->isSafeAttribute($issue, 'project_id', $user)) {
            $p = $attrs['project_id'];
            $project = \is_string($p) && !ctype_digit($p) ? $this->em->getRepository(Project::class)->findOneBy(['identifier' => $p]) : $this->em->find(Project::class, (int) $p);
            $allowedIds = array_map(static fn (Project $x) => $x->id, $this->projects->allowedTargetProjects($user, $issue->isNew() ? null : $issue->project));
            if ($project && \in_array($project->id, $allowedIds, true) && (!isset($issue->project) || $project->id !== $issue->project->id)) {
                $previousCategoryId = $issue->category?->id;
                $this->changeProject($issue, $project);
                if (isset($attrs['category_id']) && (string) $attrs['category_id'] === (string) $previousCategoryId) {
                    unset($attrs['category_id']);
                }
            }
        }
        unset($attrs['project_id']);

        if (\array_key_exists('tracker_id', $attrs) && $this->isSafeAttribute($issue, 'tracker_id', $user)) {
            $allowed = $this->projects->allowedTargetTrackers($issue->project, $user, $original['tracker_id']);
            foreach ($allowed as $t) {
                if ($t->id === (int) $attrs['tracker_id']) {
                    $issue->tracker = $t;
                    break;
                }
            }
        }
        unset($attrs['tracker_id']);
        if (!isset($issue->tracker)) {
            $allowed = $this->projects->allowedTargetTrackers($issue->project, $user);
            if (!empty($attrs['parent_issue_id'])) {
                foreach ($allowed as $t) {
                    if ($t->isCoreFieldEnabled('parent_issue_id')) {
                        $issue->tracker = $t;
                        break;
                    }
                }
            }
            if (!isset($issue->tracker) && $allowed) {
                $issue->tracker = $allowed[0];
            }
        }
        $this->workflowRulesCache = [];

        if (!isset($issue->status) && isset($issue->tracker) && $issue->tracker->defaultStatus) {
            $issue->status = $issue->tracker->defaultStatus;
        }
        $statusesAllowed = $this->newStatusesAllowedTo($issue, $user, false, $original);
        if (\array_key_exists('status_id', $attrs) && $this->isSafeAttribute($issue, 'status_id', $user)) {
            foreach ($statusesAllowed as $s) {
                if ($s->id === (int) $attrs['status_id']) {
                    $issue->status = $s;
                    break;
                }
            }
        }
        unset($attrs['status_id']);
        if ($issue->isNew() && (!isset($issue->status) || !\in_array($issue->status, $statusesAllowed, true))) {
            $first = $statusesAllowed[0] ?? $issue->tracker->defaultStatus ?? null;
            if ($first) {
                $issue->status = $first;
            }
        }
        $this->workflowRulesCache = [];
        if ($issue->isNew() && isset($issue->tracker) && $issue->tracker->privateByDefault && !\array_key_exists('is_private', $attrs) && $this->isSafeAttribute($issue, 'is_private', $user)) {
            $attrs['is_private'] = '1';
        }
        if (\array_key_exists('assigned_to_id', $attrs) && $this->isSafeAttribute($issue, 'assigned_to_id', $user)) {
            $id = $attrs['assigned_to_id'];
            $issue->assignedTo = ('' === $id || null === $id || 'none' === $id) ? null : ('me' === $id ? $user : $this->em->find(Principal::class, (int) $id));
        }
        unset($attrs['assigned_to_id']);

        $safe = $this->safeAttributeNames($issue, $user);
        foreach ($attrs as $name => $value) {
            if (!\in_array($name, $safe, true)) {
                continue;
            }
            match ($name) {
                'subject' => $issue->subject = trim((string) $value),
                'description' => $issue->description = null === $value ? null : str_replace(["\r\n", "\r", "\n"], "\r\n", (string) $value),
                'category_id' => $issue->category = ('' === (string) $value) ? null : $this->em->find(IssueCategory::class, (int) $value),
                'priority_id' => ($p = $this->em->find(IssuePriority::class, (int) $value)) ? $issue->priority = $p : null,
                'fixed_version_id' => $issue->fixedVersion = ('' === (string) $value) ? null : $this->em->find(Version::class, (int) $value),
                'start_date' => $issue->startDate = self::parseDate($value),
                'due_date' => $issue->dueDate = self::parseDate($value),
                'done_ratio' => $issue->doneRatio = '' === (string) $value ? 0 : (int) $value,
                'estimated_hours' => $issue->estimatedHours = self::parseHours($value),
                'is_private' => $issue->isPrivate = \in_array($value, [true, 1, '1', 'true'], true),
                'lock_version' => $issue->lockVersion = (int) $value,
                default => null,
            };
        }
        if (\in_array('parent_issue_id', $safe, true) && \array_key_exists('parent_issue_id', $attrs)) {
            $s = trim((string) $attrs['parent_issue_id']);
            if ('' === $s) {
                $issue->parent = null;
            } elseif (preg_match('/^#?(\d+)$/', $s, $m)) {
                $parent = $this->em->find(Issue::class, (int) $m[1]);
                if ($parent && ($parent->id === $issue->parent?->id || $this->isVisible($parent, $user))) {
                    $issue->parent = $parent;
                } else {
                    $this->invalidParent[spl_object_id($issue)] = true;
                }
            } else {
                $this->invalidParent[spl_object_id($issue)] = true;
            }
        }
        if (\in_array('custom_field_values', $safe, true) && isset($attrs['custom_field_values']) && \is_array($attrs['custom_field_values'])) {
            $editable = array_map(static fn ($f) => (string) $f->id, $this->editableCustomFields($issue, $user));
            $values = array_filter($attrs['custom_field_values'], static fn ($k) => \in_array((string) $k, $editable, true), \ARRAY_FILTER_USE_KEY);
            $this->pendingCustomValues[spl_object_id($issue)] = $values + ($this->pendingCustomValues[spl_object_id($issue)] ?? []);
        }
        if (\in_array('notes', $safe, true) && \array_key_exists('notes', $attrs)) {
            $this->pendingNotes[spl_object_id($issue)] = (string) $attrs['notes'];
        }
        if (\in_array('private_notes', $safe, true) && \array_key_exists('private_notes', $attrs)) {
            $this->pendingPrivateNotes[spl_object_id($issue)] = \in_array($attrs['private_notes'], [true, 1, '1', 'true'], true);
        }
        if (\in_array('watcher_user_ids', $safe, true) && isset($attrs['watcher_user_ids'])) {
            $this->pendingWatchers[spl_object_id($issue)] = array_map('intval', (array) $attrs['watcher_user_ids']);
        }
        if (\in_array('deleted_attachment_ids', $safe, true) && isset($attrs['deleted_attachment_ids'])) {
            $this->pendingDeletedAttachments[spl_object_id($issue)] = array_map('intval', (array) $attrs['deleted_attachment_ids']);
        }
        // done ratio from status
        if ($this->useStatusForDoneRatio() && null !== $issue->status->defaultDoneRatio) {
            $issue->doneRatio = $issue->status->defaultDoneRatio;
        }
    }

    /** @var array<int, bool> */
    private array $invalidParent = [];
    /** @var array<int, array<int|string, mixed>> */
    private array $pendingCustomValues = [];
    /** @var array<int, string> */
    private array $pendingNotes = [];
    /** @var array<int, bool> */
    private array $pendingPrivateNotes = [];
    /** @var array<int, list<int>> */
    private array $pendingWatchers = [];
    /** @var array<int, list<int>> */
    private array $pendingDeletedAttachments = [];
    /** @var array<int, list<Attachment>> */
    private array $pendingAttachments = [];

    /** Attaches uploaded (already persisted, container-less) attachments on next save */
    public function addAttachments(Issue $issue, array $attachments): void
    {
        $this->pendingAttachments[spl_object_id($issue)] = [...($this->pendingAttachments[spl_object_id($issue)] ?? []), ...$attachments];
    }

    public function setNotes(Issue $issue, ?string $notes, bool $private = false): void
    {
        $this->pendingNotes[spl_object_id($issue)] = (string) $notes;
        $this->pendingPrivateNotes[spl_object_id($issue)] = $private;
    }

    /** Custom field values submitted but not saved yet (for re-displaying a form) */
    public function pendingCustomValues(Issue $issue): array
    {
        return $this->pendingCustomValues[spl_object_id($issue)] ?? [];
    }

    public function pendingNotes(Issue $issue): string
    {
        return $this->pendingNotes[spl_object_id($issue)] ?? '';
    }

    /** Moves the issue to another project, resetting incompatible attributes (Issue#project=) */
    public function changeProject(Issue $issue, Project $project, bool $keepTracker = false): void
    {
        $issue->project = $project;
        if (!$keepTracker && isset($issue->tracker) && !$project->hasTracker($issue->tracker)) {
            $first = $project->trackers->first();
            if ($first) {
                $issue->tracker = $first;
            }
        }
        if ($issue->category) {
            $oldName = $issue->category->name;
            $issue->category = null;
            foreach ($project->issueCategories as $c) {
                if ($c->name === $oldName) {
                    $issue->category = $c;
                }
            }
        }
        if ($issue->isNew() && $issue->assignedTo && !\in_array($issue->assignedTo, $this->assignableUsers($issue), true)) {
            $issue->assignedTo = null;
        }
        if ($issue->fixedVersion && $issue->fixedVersion->project->id !== $project->id
            && !\in_array($issue->fixedVersion, $this->projects->sharedVersions($project), true)) {
            $issue->fixedVersion = null;
        }
        if ($issue->parent && !$this->isValidParentProject($issue, $issue->parent)) {
            $issue->parent = null;
        }
        $this->workflowRulesCache = [];
    }

    /** Setting cross_project_subtasks: '', 'system', 'tree', 'hierarchy', 'descendants' */
    public function isValidParentProject(Issue $issue, Issue $parent): bool
    {
        if ($parent->project->id === $issue->project->id) {
            return true;
        }

        return match ($this->settings->string('cross_project_subtasks')) {
            'system' => true,
            'tree' => $parent->project->getRoot()->id === $issue->project->getRoot()->id,
            'hierarchy' => $parent->project->isDescendantOf($issue->project) || $issue->project->isDescendantOf($parent->project),
            'descendants' => $issue->project->isDescendantOf($parent->project),
            default => false,
        };
    }

    /** @return list<CustomField> */
    public function editableCustomFields(Issue $issue, ?User $user = null): array
    {
        $readOnly = $this->readOnlyAttributeNames($issue, $user);

        return array_values(array_filter($this->customFields->visibleIssueFields($issue, $user), static fn ($f) => !\in_array((string) $f->id, $readOnly, true) && false !== $f->editable));
    }

    /** @return list<Principal> */
    public function assignableUsers(Issue $issue): array
    {
        if (!isset($issue->project)) {
            return [];
        }
        $users = $this->projects->assignableUsers($issue->project, $issue->tracker ?? null);
        if (isset($issue->author) && $issue->author->isActive() && !\in_array($issue->author, $users, true)) {
            $users[] = $issue->author;
        }
        if ($issue->assignedTo && !\in_array($issue->assignedTo, $users, true) && !$issue->isNew()) {
            $users[] = $issue->assignedTo;
        }
        usort($users, static fn (Principal $a, Principal $b) => [$a instanceof Group ? 1 : 0, mb_strtolower($a->getName())] <=> [$b instanceof Group ? 1 : 0, mb_strtolower($b->getName())]);

        return $users;
    }

    /** @return list<Version> */
    public function assignableVersions(Issue $issue): array
    {
        if (!isset($issue->project)) {
            return [];
        }
        $versions = array_values(array_filter($this->projects->sharedVersions($issue->project), static fn (Version $v) => $v->isOpen()));
        if ($issue->fixedVersion && !\in_array($issue->fixedVersion, $versions, true)) {
            $versions[] = $issue->fixedVersion;
        }

        return $this->projects->sortVersions($versions);
    }

    // ------------------------------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------------------------------

    /** @return array<string, mixed> attribute values used to detect changes */
    public function originalValues(Issue $issue): array
    {
        if ($issue->isNew()) {
            return ['status_id' => null, 'tracker_id' => null, 'assigned_to_id' => null, 'snapshot' => []];
        }
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM issues WHERE id = ?', [$issue->id]) ?: [];

        return [
            'status_id' => isset($row['status_id']) ? (int) $row['status_id'] : null,
            'tracker_id' => isset($row['tracker_id']) ? (int) $row['tracker_id'] : null,
            'assigned_to_id' => isset($row['assigned_to_id']) ? (int) $row['assigned_to_id'] : null,
            'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : null,
            'parent_id' => isset($row['parent_id']) ? (int) $row['parent_id'] : null,
            'start_date' => $row['start_date'] ?? null,
            'snapshot' => $row,
        ];
    }

    /**
     * Validates the issue (Issue#validate_issue + validate_required_fields + custom fields).
     *
     * @return list<string> translated error messages
     */
    public function validate(Issue $issue, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $errors = [];
        $original = $this->originalValues($issue);
        $field = fn (string $f) => $this->i18n->l('field_'.$f);
        $msg = fn (string $k, array $p = []) => $this->i18n->l('activerecord.errors.messages.'.$k, $p);

        if (!isset($issue->project)) {
            $errors[] = $field('project').' '.$msg('blank');

            return $errors;
        }
        if (!isset($issue->tracker)) {
            $errors[] = $field('tracker').' '.$msg('blank');

            return $errors;
        }
        if ('' === trim($issue->subject)) {
            $errors[] = $field('subject').' '.$msg('blank');
        } elseif (mb_strlen($issue->subject) > 255) {
            $errors[] = $field('subject').' '.$msg('too_long', ['count' => 255]);
        }
        if (!isset($issue->priority)) {
            $errors[] = $field('priority').' '.$msg('blank');
        }
        if (!isset($issue->status)) {
            $errors[] = $field('status').' '.$msg('blank');
        }
        if ($issue->doneRatio < 0 || $issue->doneRatio > 100) {
            $errors[] = $field('done_ratio').' '.$msg('inclusion');
        }
        if (null !== $issue->estimatedHours && $issue->estimatedHours < 0) {
            $errors[] = $field('estimated_hours').' '.$msg('invalid');
        }
        if ($issue->dueDate && $issue->startDate && $issue->dueDate < $issue->startDate) {
            $errors[] = $field('due_date').' '.$msg('greater_than_start_date');
        }
        if ($issue->startDate && ($soonest = $this->soonestStart($issue)) && $issue->startDate < $soonest
            && ($issue->isNew() || $issue->startDate->format('Y-m-d') !== ($original['start_date'] ?? null))) {
            $errors[] = $field('start_date').' '.$msg('earlier_than_minimum_start_date', ['date' => $this->i18n->formatDate($soonest)]);
        }
        if ($issue->fixedVersion) {
            if (!\in_array($issue->fixedVersion, $this->assignableVersions($issue), true)
                || ($issue->fixedVersion->project->id !== $issue->project->id && !\in_array($issue->fixedVersion, $this->projects->sharedVersions($issue->project), true))) {
                $errors[] = $field('fixed_version').' '.$msg('inclusion');
            } elseif ($this->isReopening($issue, $original) && $issue->fixedVersion->isClosed()) {
                $errors[] = $this->i18n->l('error_can_not_reopen_issue_on_closed_version');
            } elseif ($issue->isNew() && !$issue->fixedVersion->isOpen()) {
                $errors[] = $field('fixed_version').' '.$msg('inclusion');
            }
        }
        if ($issue->category && $issue->category->project->id !== $issue->project->id) {
            $errors[] = $field('category').' '.$msg('inclusion');
        }
        if (!$issue->project->hasTracker($issue->tracker) && ($issue->isNew() || $original['tracker_id'] !== $issue->tracker->id || $original['project_id'] !== $issue->project->id)) {
            $errors[] = $field('tracker').' '.$msg('inclusion');
        }
        if ($issue->assignedTo && ($issue->isNew() || $original['assigned_to_id'] !== $issue->assignedTo->id)
            && !\in_array($issue->assignedTo, $this->assignableUsers($issue), true)) {
            $errors[] = $field('assigned_to').' '.$msg('invalid');
        }
        if (isset($this->invalidParent[spl_object_id($issue)])) {
            $errors[] = $field('parent_issue').' '.$msg('invalid');
        } elseif ($issue->parent) {
            $p = $issue->parent;
            if ($p->id === $issue->id || (!$issue->isNew() && $p->isDescendantOf($issue)) || !$this->isValidParentProject($issue, $p)) {
                $errors[] = $field('parent_issue').' '.$msg('invalid');
            } elseif (!$issue->isClosed() && $p->isClosed()) {
                $errors[] = $msg('open_issue_with_closed_parent');
            } elseif ($p->id !== ($original['parent_id'] ?? null) && $this->relationExistsBetween($issue, $p)) {
                $errors[] = $field('parent_issue').' '.$msg('invalid');
            }
        }
        // required by workflow
        foreach ($this->requiredAttributeNames($issue, $issue->isNew() ? ($issue->author ?? $user) : $user) as $attr) {
            if (ctype_digit($attr)) {
                $cfId = (int) $attr;
                $values = $this->pendingCustomValues[spl_object_id($issue)][$cfId] ?? $this->customFields->values('Issue', $issue->id)[$cfId] ?? null;
                if ([] === array_filter((array) $values, static fn ($v) => '' !== (string) $v)) {
                    $cf = $this->em->find(IssueCustomField::class, $cfId);
                    if ($cf && \in_array($cf, $this->customFields->availableIssueFields($issue), true)) {
                        $errors[] = $cf->name.' '.$msg('blank');
                    }
                }
            } else {
                $empty = match ($attr) {
                    'assigned_to_id' => null === $issue->assignedTo,
                    'category_id' => null === $issue->category,
                    'fixed_version_id' => null === $issue->fixedVersion,
                    'parent_issue_id' => null === $issue->parent,
                    'start_date' => null === $issue->startDate,
                    'due_date' => null === $issue->dueDate,
                    'estimated_hours' => null === $issue->estimatedHours,
                    'description' => null === $issue->description || '' === trim($issue->description),
                    'done_ratio' => false,
                    'priority_id' => !isset($issue->priority),
                    'is_private' => false,
                    default => false,
                };
                if ($empty && $issue->tracker->isCoreFieldEnabled($attr)) {
                    $errors[] = $field(preg_replace('/_id$/', '', $attr) ?? $attr).' '.$msg('blank');
                }
            }
        }
        // custom field formats & required
        $current = $issue->isNew() ? $this->customFields->defaultValues($this->customFields->availableIssueFields($issue)) : $this->customFields->values('Issue', $issue->id);
        $pending = $this->pendingCustomValues[spl_object_id($issue)] ?? [];
        foreach ($this->customFields->visibleIssueFields($issue, $user) as $cf) {
            $value = \array_key_exists($cf->id, $pending) ? $pending[$cf->id] : (\array_key_exists((string) $cf->id, $pending) ? $pending[(string) $cf->id] : ($current[$cf->id] ?? null));
            array_push($errors, ...$this->customFields->validate($cf, $value, $issue->project));
        }

        return array_values(array_unique($errors));
    }

    /** @param array<string, mixed> $original */
    private function isReopening(Issue $issue, array $original): bool
    {
        if ($issue->isNew() || null === $original['status_id']) {
            return false;
        }
        $was = $this->em->find(IssueStatus::class, $original['status_id']);

        return $was && $was->isClosed && !$issue->isClosed();
    }

    private function relationExistsBetween(Issue $a, Issue $b): bool
    {
        if ($a->isNew()) {
            return false;
        }
        foreach ($a->getRelations() as $r) {
            if ($r->otherIssue($a)->id === $b->id) {
                return true;
            }
        }

        return false;
    }

    /** Earliest start date allowed by "precedes" relations (Issue#soonest_start) */
    public function soonestStart(Issue $issue): ?\DateTimeImmutable
    {
        $dates = [];
        foreach ($issue->relationsTo as $rel) {
            if (IssueRelation::TYPE_PRECEDES === $rel->relationType && ($d = $this->successorSoonestStart($rel))) {
                $dates[] = $d;
            }
        }
        foreach ($issue->getAncestors() as $a) {
            foreach ($a->relationsTo as $rel) {
                if (IssueRelation::TYPE_PRECEDES === $rel->relationType && ($d = $this->successorSoonestStart($rel))) {
                    $dates[] = $d;
                }
            }
        }

        return [] === $dates ? null : max($dates);
    }

    private function successorSoonestStart(IssueRelation $rel): ?\DateTimeImmutable
    {
        $from = $rel->issueFrom;
        $base = $from->dueDate ?? $from->startDate;
        if (null === $base) {
            return null;
        }

        return $this->addWorkingDays($base, ($rel->delay ?? 0) + 1);
    }

    // ------------------------------------------------------------------------------------------
    // Saving
    // ------------------------------------------------------------------------------------------

    /**
     * Validates and saves the issue, creating a journal with the changes (and notes).
     *
     * @return list<string> errors (empty on success)
     */
    public function save(Issue $issue, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $oid = spl_object_id($issue);
        $errors = $this->validate($issue, $user);
        if ([] !== $errors) {
            return $errors;
        }
        $isNew = $issue->isNew();
        $original = $this->originalValues($issue);
        $oldCustom = $isNew ? [] : $this->customFields->values('Issue', $issue->id);
        $notes = $this->pendingNotes[$oid] ?? '';
        $privateNotes = $this->pendingPrivateNotes[$oid] ?? false;
        $now = new \DateTimeImmutable();

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            // closed_on
            $wasClosed = !$isNew && $original['status_id'] && ($ws = $this->em->find(IssueStatus::class, $original['status_id'])) && $ws->isClosed;
            if ($issue->isClosed() && ($isNew || !$wasClosed || $original['status_id'] !== $issue->status->id)) {
                if ($isNew || !$wasClosed) {
                    $issue->closedOn = $now;
                }
            }
            if ($this->useStatusForDoneRatio() && null !== $issue->status->defaultDoneRatio) {
                $issue->doneRatio = $issue->status->defaultDoneRatio;
            }
            if ($isNew) {
                $issue->createdOn = $now;
            }
            $issue->updatedOn = $now;
            if ($isNew && isset($issue->tracker) && $issue->tracker->privateByDefault && !$this->canSetPrivate($issue, $user)) {
                $issue->isPrivate = true;
            }
            $this->em->persist($issue);
            $this->em->flush();

            // custom values
            $fields = $this->customFields->availableIssueFields($issue);
            $values = $this->pendingCustomValues[$oid] ?? [];
            if ($isNew) {
                $values += $this->customFields->defaultValues($fields);
            }
            $cfChanges = $this->customFields->saveValues('Issue', (int) $issue->id, $fields, $values);

            // nested set
            $parentWas = $original['parent_id'] ?? null;
            $parentNow = $issue->parent?->id;
            if ($isNew || $parentWas !== $parentNow) {
                $this->rebuildIssueTrees(array_filter([$issue->id, $parentWas, $parentNow]));
            }

            // attachments
            $attachmentsAdded = [];
            foreach ($this->pendingAttachments[$oid] ?? [] as $a) {
                $a->containerType = 'Issue';
                $a->containerId = $issue->id;
                $attachmentsAdded[] = $a;
            }
            $attachmentsDeleted = [];
            foreach ($this->pendingDeletedAttachments[$oid] ?? [] as $aid) {
                $a = $this->em->find(Attachment::class, $aid);
                if ($a && 'Issue' === $a->containerType && $a->containerId === $issue->id) {
                    $attachmentsDeleted[] = $a;
                    $this->em->remove($a);
                }
            }

            // journal
            $journal = null;
            if (!$isNew) {
                $journal = new Journal();
                $journal->issue = $issue;
                $journal->user = $user;
                $journal->notes = $notes;
                $journal->privateNotes = $privateNotes;
                $journal->createdOn = $now;
                $journal->updatedOn = $now;
                $snapshot = $original['snapshot'];
                foreach (self::JOURNALIZED_ATTRIBUTES as $attr) {
                    $old = $snapshot[$attr] ?? null;
                    $new = $this->attributeValue($issue, $attr);
                    if ($this->normalizeForCompare($attr, $old) !== $this->normalizeForCompare($attr, $new)) {
                        $journal->addDetail('attr', $attr, $this->normalizeForCompare($attr, $old), $this->normalizeForCompare($attr, $new));
                    }
                }
                foreach ($cfChanges as $fid => [$old, $new]) {
                    if (\is_array($old) || \is_array($new)) {
                        $old = (array) $old;
                        $new = (array) $new;
                        foreach (array_diff($old, $new) as $removed) {
                            $journal->addDetail('cf', (string) $fid, $removed, null);
                        }
                        foreach (array_diff($new, $old) as $added) {
                            $journal->addDetail('cf', (string) $fid, null, $added);
                        }
                    } else {
                        $journal->addDetail('cf', (string) $fid, '' === (string) $old ? null : $old, '' === (string) $new ? null : $new);
                    }
                }
                foreach ($attachmentsAdded as $a) {
                    $journal->addDetail('attachment', (string) $a->id, null, $a->filename);
                }
                foreach ($attachmentsDeleted as $a) {
                    $journal->addDetail('attachment', (string) $a->id, $a->filename, null);
                }
                if ($journal->isEmpty()) {
                    $journal = null;
                } else {
                    $this->em->persist($journal);
                    $issue->journals->add($journal);
                }
            }

            // watchers on creation
            if ($isNew) {
                foreach ($this->pendingWatchers[$oid] ?? [] as $uid) {
                    if ($p = $this->em->find(Principal::class, $uid)) {
                        $this->addWatcher('Issue', (int) $issue->id, $p);
                    }
                }
                if ($user->isLogged() && $this->autoWatchOnCreate($user)) {
                    $this->addWatcher('Issue', (int) $issue->id, $user);
                }
            } elseif ($user->isLogged() && null !== $journal && $this->autoWatchOnUpdate($user)) {
                $this->addWatcher('Issue', (int) $issue->id, $user);
            }
            $this->em->flush();

            // parent attributes, closing duplicates, rescheduling followers
            foreach (array_unique(array_filter([$parentNow, $parentWas])) as $pid) {
                $this->recalculateAttributesFor((int) $pid, $user);
            }
            if (!$isNew && $issue->isClosed() && !$wasClosed) {
                $this->closeDuplicates($issue, $user);
            }
            if (!$isNew && ($issue->dueDate?->format('Y-m-d') !== ($original['snapshot']['due_date'] ?? null)
                || $issue->startDate?->format('Y-m-d') !== ($original['snapshot']['start_date'] ?? null))) {
                $this->rescheduleFollowingIssues($issue, $user);
            }
            $this->em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
        $this->clearPending($issue);
        $this->auth->reset();

        $this->events->dispatch(new IssueEvent($issue, $isNew ? IssueEvent::CREATED : IssueEvent::UPDATED, $user, $journal));

        return [];
    }

    private function autoWatchOnCreate(User $user): bool
    {
        $v = $user->getPref()->get('auto_watch_on', ['issue_created', 'issue_contributed_to']);

        return \in_array('issue_created', (array) $v, true);
    }

    private function autoWatchOnUpdate(User $user): bool
    {
        $v = $user->getPref()->get('auto_watch_on', ['issue_created', 'issue_contributed_to']);

        return \in_array('issue_contributed_to', (array) $v, true);
    }

    private function clearPending(Issue $issue): void
    {
        $oid = spl_object_id($issue);
        unset($this->pendingCustomValues[$oid], $this->pendingNotes[$oid], $this->pendingPrivateNotes[$oid],
            $this->pendingWatchers[$oid], $this->pendingDeletedAttachments[$oid], $this->pendingAttachments[$oid], $this->invalidParent[$oid]);
        $this->workflowRulesCache = [];
    }

    public function attributeValue(Issue $issue, string $attr): mixed
    {
        return match ($attr) {
            'project_id' => $issue->project->id,
            'tracker_id' => $issue->tracker->id,
            'subject' => $issue->subject,
            'description' => $issue->description,
            'category_id' => $issue->category?->id,
            'status_id' => $issue->status->id,
            'assigned_to_id' => $issue->assignedTo?->id,
            'priority_id' => $issue->priority->id,
            'fixed_version_id' => $issue->fixedVersion?->id,
            'parent_id' => $issue->parent?->id,
            'start_date' => $issue->startDate?->format('Y-m-d'),
            'due_date' => $issue->dueDate?->format('Y-m-d'),
            'done_ratio' => $issue->doneRatio,
            'estimated_hours' => $issue->estimatedHours,
            'is_private' => $issue->isPrivate,
            default => null,
        };
    }

    private function normalizeForCompare(string $attr, mixed $v): ?string
    {
        if (null === $v || '' === $v) {
            return null;
        }

        return match ($attr) {
            'is_private' => \in_array($v, [true, 1, '1', 't', 'true'], true) ? '1' : '0',
            'estimated_hours' => (string) (float) $v,
            'description' => str_replace("\r\n", "\n", (string) $v),
            'start_date', 'due_date' => substr((string) $v, 0, 10),
            default => (string) $v,
        };
    }

    public function addWatcher(string $type, int $id, Principal $principal): void
    {
        $exists = $this->em->getRepository(Watcher::class)->findOneBy(['watchableType' => $type, 'watchableId' => $id, 'user' => $principal]);
        if (null === $exists) {
            $w = new Watcher();
            $w->watchableType = $type;
            $w->watchableId = $id;
            $w->user = $principal;
            $this->em->persist($w);
        }
    }

    /** @return list<Principal> */
    public function watchers(Issue $issue): array
    {
        $ws = $this->em->getRepository(Watcher::class)->findBy(['watchableType' => 'Issue', 'watchableId' => $issue->id]);
        $out = array_values(array_filter(array_map(static fn (Watcher $w) => $w->user, $ws)));
        usort($out, static fn ($a, $b) => strcasecmp($a->getName(), $b->getName()));

        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // Subtasks
    // ------------------------------------------------------------------------------------------

    /** Recomputes derived attributes of a parent issue, then its ancestors (Issue#recalculate_attributes_for) */
    public function recalculateAttributesFor(int $issueId, ?User $user = null): void
    {
        $p = $this->em->find(Issue::class, $issueId);
        if (null === $p) {
            return;
        }
        $this->em->refresh($p);
        $children = $p->children->toArray();
        $changed = false;
        if ($this->isPriorityDerived($p)) {
            $max = null;
            foreach ($children as $c) {
                if (!$c->isClosed() && (null === $max || $c->priority->position > $max->position)) {
                    $max = $c->priority;
                }
            }
            $max ??= $this->em->getRepository(IssuePriority::class)->findOneBy(['isDefault' => true]);
            if ($max && $max->id !== $p->priority->id) {
                $p->priority = $max;
                $changed = true;
            }
        }
        if ($this->isDatesDerived($p)) {
            $starts = array_filter(array_map(static fn (Issue $c) => $c->startDate, $children));
            $dues = array_filter(array_map(static fn (Issue $c) => $c->dueDate, $children));
            $start = $starts ? min($starts) : null;
            $due = $dues ? max($dues) : null;
            if ($start && $due && $due < $start) {
                [$start, $due] = [$due, $start];
            }
            if ($start != $p->startDate || $due != $p->dueDate) {
                $p->startDate = $start;
                $p->dueDate = $due;
                $changed = true;
            }
        }
        if ($this->isDoneRatioDerived($p) && !($this->useStatusForDoneRatio() && null !== $p->status->defaultDoneRatio) && $children) {
            $withHours = array_filter($children, fn (Issue $c) => $this->totalEstimatedHours($c) > 0);
            $average = $withHours ? array_sum(array_map(fn (Issue $c) => $this->totalEstimatedHours($c), $withHours)) / \count($withHours) : 1.0;
            $done = 0.0;
            foreach ($children as $c) {
                $est = $this->totalEstimatedHours($c);
                $est = $est > 0 ? $est : $average;
                $done += $est * ($c->isClosed() ? 100 : $c->doneRatio);
            }
            $ratio = (int) floor($done / ($average * \count($children)) + 1e-9);
            if ($ratio !== $p->doneRatio) {
                $p->doneRatio = $ratio;
                $changed = true;
            }
        }
        if ($changed) {
            $p->updatedOn = new \DateTimeImmutable();
            $this->em->flush();
        }
        if ($p->parent) {
            $this->recalculateAttributesFor((int) $p->parent->id, $user);
        }
    }

    public function totalEstimatedHours(Issue $issue): float
    {
        $total = (float) ($issue->estimatedHours ?? 0);
        foreach ($issue->getDescendants() as $d) {
            $total += (float) ($d->estimatedHours ?? 0);
        }

        return $total;
    }

    public function spentHours(Issue $issue): float
    {
        return (float) $this->em->createQuery('SELECT COALESCE(SUM(t.hours), 0) FROM App\Entity\TimeEntry t WHERE t.issue = :i')->setParameter('i', $issue->id)->getSingleScalarResult();
    }

    public function totalSpentHours(Issue $issue): float
    {
        $ids = [$issue->id, ...array_map(static fn (Issue $d) => $d->id, $issue->getDescendants())];

        return (float) $this->em->createQuery('SELECT COALESCE(SUM(t.hours), 0) FROM App\Entity\TimeEntry t WHERE IDENTITY(t.issue) IN (:ids)')->setParameter('ids', $ids)->getSingleScalarResult();
    }

    /** Rebuilds root_id/lft/rgt of the trees containing the given issues (Redmine nested set) */
    public function rebuildIssueTrees(array $issueIds): void
    {
        $conn = $this->em->getConnection();
        $roots = [];
        foreach (array_unique(array_map('intval', $issueIds)) as $id) {
            $current = $id;
            $guard = 0;
            while ($guard++ < 1000) {
                $parent = $conn->fetchOne('SELECT parent_id FROM issues WHERE id = ?', [$current]);
                if (false === $parent) {
                    $current = null;
                    break;
                }
                if (null === $parent) {
                    break;
                }
                $current = (int) $parent;
            }
            if (null !== $current) {
                $roots[$current] = true;
            }
        }
        foreach (array_keys($roots) as $rootId) {
            $counter = 0;
            $walk = function (int $id) use (&$walk, &$counter, $conn, $rootId): void {
                $lft = ++$counter;
                foreach ($conn->fetchFirstColumn('SELECT id FROM issues WHERE parent_id = ? ORDER BY id', [$id]) as $child) {
                    $walk((int) $child);
                }
                $conn->executeStatement('UPDATE issues SET root_id = ?, lft = ?, rgt = ? WHERE id = ?', [$rootId, $lft, ++$counter, $id]);
            };
            $walk($rootId);
        }
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[Issue::class] ?? [] as $i) {
            if (isset($roots[$i->rootId ?? -1]) || \in_array($i->id, $issueIds, true)) {
                $row = $conn->fetchAssociative('SELECT root_id, lft, rgt FROM issues WHERE id = ?', [$i->id]);
                if ($row) {
                    $i->rootId = (int) $row['root_id'];
                    $i->lft = (int) $row['lft'];
                    $i->rgt = (int) $row['rgt'];
                }
            }
        }
    }

    // ------------------------------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------------------------------

    /**
     * Creates a relation (IssueRelation validations + reverse type normalisation).
     *
     * @return array{0: ?IssueRelation, 1: list<string>}
     */
    public function createRelation(Issue $from, Issue|int|null $to, string $type, ?int $delay = null, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $msg = fn (string $k) => $this->i18n->l('activerecord.errors.messages.'.$k);
        if (\is_int($to)) {
            $to = $this->em->find(Issue::class, $to);
        }
        if (null === $to || !$this->isVisible($to, $user)) {
            return [null, [$this->i18n->l('field_issue_to').' '.$msg('invalid')]];
        }
        if (!isset(IssueRelation::TYPES[$type])) {
            return [null, [$this->i18n->l('field_relation_type').' '.$msg('inclusion')]];
        }
        if ($from->id === $to->id) {
            return [null, [$this->i18n->l('field_issue_to').' '.$msg('invalid')]];
        }
        if (!$this->settings->bool('cross_project_issue_relations') && $from->project->id !== $to->project->id) {
            return [null, [$this->i18n->l('field_issue_to').' '.$msg('not_same_project')]];
        }
        // normalise reverse types
        $reverse = IssueRelation::TYPES[$type][3];
        if (\in_array($type, [IssueRelation::TYPE_DUPLICATED, IssueRelation::TYPE_BLOCKED, IssueRelation::TYPE_FOLLOWS, IssueRelation::TYPE_COPIED_FROM], true)) {
            [$from, $to] = [$to, $from];
            $type = $reverse;
        }
        if (IssueRelation::TYPE_RELATES === $type && $from->id > $to->id) {
            [$from, $to] = [$to, $from];
        }
        foreach ($from->getRelations() as $r) {
            if ($r->otherIssue($from)->id === $to->id) {
                return [null, [$this->i18n->l('field_issue_to').' '.$this->i18n->l('activerecord.errors.messages.taken')]];
            }
        }
        if ($to->isDescendantOf($from) || $from->isDescendantOf($to)) {
            return [null, [$this->i18n->l('activerecord.errors.messages.cant_link_an_issue_with_a_descendant')]];
        }
        if (\in_array($type, [IssueRelation::TYPE_PRECEDES, IssueRelation::TYPE_BLOCKS], true) && $this->wouldCreateCycle($from, $to, $type)) {
            return [null, [$this->i18n->l('activerecord.errors.messages.circular_dependency')]];
        }
        $rel = new IssueRelation();
        $rel->issueFrom = $from;
        $rel->issueTo = $to;
        $rel->relationType = $type;
        $rel->delay = IssueRelation::TYPE_PRECEDES === $type ? ($delay ?? 0) : null;
        $this->em->persist($rel);
        $from->relationsFrom->add($rel);
        $to->relationsTo->add($rel);
        // journal both issues
        foreach ([[$from, $type, $to], [$to, IssueRelation::TYPES[$type][3] ?? $type, $from]] as [$issue, $t, $other]) {
            $j = new Journal();
            $j->issue = $issue;
            $j->user = $user;
            $j->addDetail('relation', $t, null, (string) $other->id);
            $this->em->persist($j);
        }
        $this->em->flush();
        if (IssueRelation::TYPE_PRECEDES === $type) {
            $this->setIssueToDates($rel, $user);
        }

        return [$rel, []];
    }

    public function deleteRelation(IssueRelation $rel, ?User $user = null): void
    {
        $user = $this->auth->user($user);
        foreach ([[$rel->issueFrom, $rel->relationType, $rel->issueTo], [$rel->issueTo, IssueRelation::TYPES[$rel->relationType][3] ?? $rel->relationType, $rel->issueFrom]] as [$issue, $t, $other]) {
            $j = new Journal();
            $j->issue = $issue;
            $j->user = $user;
            $j->addDetail('relation', $t, (string) $other->id, null);
            $this->em->persist($j);
        }
        $rel->issueFrom->relationsFrom->removeElement($rel);
        $rel->issueTo->relationsTo->removeElement($rel);
        $this->em->remove($rel);
        $this->em->flush();
    }

    private function wouldCreateCycle(Issue $from, Issue $to, string $type): bool
    {
        // does "to" already (transitively) precede/block "from"?
        $seen = [];
        $stack = [$to];
        while ($stack) {
            $i = array_pop($stack);
            if ($i->id === $from->id) {
                return true;
            }
            if (isset($seen[$i->id])) {
                continue;
            }
            $seen[$i->id] = true;
            foreach ($i->relationsFrom as $r) {
                if ($r->relationType === $type) {
                    $stack[] = $r->issueTo;
                }
            }
        }

        return false;
    }

    /** Reschedules the following issue of a "precedes" relation (IssueRelation#set_issue_to_dates) */
    private function setIssueToDates(IssueRelation $rel, User $user): void
    {
        $soonest = $this->successorSoonestStart($rel);
        if (null === $soonest) {
            return;
        }
        $this->rescheduleOn($rel->issueTo, $soonest, $user);
    }

    private function rescheduleOn(Issue $issue, \DateTimeImmutable $date, User $user): void
    {
        if ($issue->isLeaf()) {
            if (null === $issue->startDate || $issue->startDate < $date) {
                $original = $this->originalValues($issue);
                if (null !== $issue->startDate && null !== $issue->dueDate) {
                    $duration = $this->workingDuration($issue->startDate, $issue->dueDate);
                    $issue->startDate = $date;
                    $issue->dueDate = $this->addWorkingDays($date, $duration);
                } else {
                    $issue->startDate = $date;
                    if ($issue->dueDate && $issue->dueDate < $date) {
                        $issue->dueDate = $date;
                    }
                }
                $j = new Journal();
                $j->issue = $issue;
                $j->user = $user;
                foreach (['start_date', 'due_date'] as $attr) {
                    $old = $original['snapshot'][$attr] ?? null;
                    $new = $this->attributeValue($issue, $attr);
                    if ($old !== $new) {
                        $j->addDetail('attr', $attr, $old, $new);
                    }
                }
                if (!$j->isEmpty()) {
                    $this->em->persist($j);
                }
                $this->em->flush();
                $this->rescheduleFollowingIssues($issue, $user);
                if ($issue->parent) {
                    $this->recalculateAttributesFor((int) $issue->parent->id, $user);
                }
            }
        } else {
            foreach ($issue->children as $child) {
                $this->rescheduleOn($child, $date, $user);
            }
        }
    }

    private function rescheduleFollowingIssues(Issue $issue, User $user): void
    {
        foreach ($issue->relationsFrom as $rel) {
            if (IssueRelation::TYPE_PRECEDES === $rel->relationType) {
                $this->setIssueToDates($rel, $user);
            }
        }
    }

    private function closeDuplicates(Issue $issue, User $user): void
    {
        foreach ($issue->relationsTo as $rel) {
            if (IssueRelation::TYPE_DUPLICATES !== $rel->relationType) {
                continue;
            }
            $dup = $rel->issueFrom;
            if ($dup->isClosed()) {
                continue;
            }
            $original = $this->originalValues($dup);
            $dup->status = $issue->status;
            $dup->closedOn = new \DateTimeImmutable();
            $j = new Journal();
            $j->issue = $dup;
            $j->user = $user;
            $j->addDetail('attr', 'status_id', (string) $original['status_id'], (string) $dup->status->id);
            $this->em->persist($j);
            $this->em->flush();
            $this->closeDuplicates($dup, $user);
        }
    }

    // Working days (setting non_working_week_days: 6,7 by default)

    /** @return list<int> ISO day numbers */
    private function nonWorkingDays(): array
    {
        return array_map('intval', $this->settings->array('non_working_week_days'));
    }

    public function addWorkingDays(\DateTimeImmutable $date, int $days): \DateTimeImmutable
    {
        $nonWorking = $this->nonWorkingDays();
        if (\count($nonWorking) >= 7) {
            return $date->modify("+$days days");
        }
        $d = $date;
        $added = 0;
        while ($added < $days) {
            $d = $d->modify('+1 day');
            if (!\in_array((int) $d->format('N'), $nonWorking, true)) {
                ++$added;
            }
        }
        while (\in_array((int) $d->format('N'), $nonWorking, true)) {
            $d = $d->modify('+1 day');
        }

        return $d;
    }

    public function workingDuration(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $nonWorking = $this->nonWorkingDays();
        $n = 0;
        $d = $from;
        while ($d < $to) {
            $d = $d->modify('+1 day');
            if (!\in_array((int) $d->format('N'), $nonWorking, true)) {
                ++$n;
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------------------------------
    // Copy / delete
    // ------------------------------------------------------------------------------------------

    /**
     * Copies an issue (Issue#copy_from) into a new unsaved issue.
     *
     * @param array{attachments?: bool, subtasks?: bool, watchers?: bool, link?: bool} $options
     */
    public function copyFrom(Issue $source, ?User $user = null, array $options = []): Issue
    {
        $user = $this->auth->user($user);
        $copy = new Issue();
        foreach (['project', 'tracker', 'subject', 'description', 'category', 'status', 'assignedTo', 'priority', 'fixedVersion', 'startDate', 'dueDate', 'doneRatio', 'estimatedHours', 'isPrivate', 'parent'] as $p) {
            $copy->{$p} = $source->{$p};
        }
        $copy->author = $user;
        if ($source->status && $source->tracker->defaultStatus && !$this->settings->bool('copy_issue_keep_status') && false) {
            $copy->status = $source->tracker->defaultStatus;
        }
        $this->pendingCustomValues[spl_object_id($copy)] = $this->customFields->values('Issue', $source->id);
        $this->copyOptions[spl_object_id($copy)] = ['source' => $source->id] + $options;

        return $copy;
    }

    /** @var array<int, array<string, mixed>> */
    private array $copyOptions = [];

    /** Post-save actions of a copy: link, attachments, subtasks, watchers */
    public function afterCopy(Issue $copy, ?User $user = null): void
    {
        $user = $this->auth->user($user);
        $opts = $this->copyOptions[spl_object_id($copy)] ?? null;
        if (null === $opts) {
            return;
        }
        $source = $this->em->find(Issue::class, $opts['source']);
        if (!$source) {
            return;
        }
        if (($opts['link'] ?? false) && $this->settings->string('link_copied_issue') !== 'no') {
            $this->createRelation($source, $copy, IssueRelation::TYPE_COPIED_TO, null, $user);
        }
        if ($opts['attachments'] ?? false) {
            foreach ($this->em->getRepository(Attachment::class)->findBy(['containerType' => 'Issue', 'containerId' => $source->id]) as $a) {
                $c = clone $a;
                $c->id = null;
                $c->containerId = $copy->id;
                $c->author = $user;
                $c->createdOn = new \DateTimeImmutable();
                $this->em->persist($c);
            }
        }
        if ($opts['watchers'] ?? false) {
            foreach ($this->watchers($source) as $w) {
                $this->addWatcher('Issue', (int) $copy->id, $w);
            }
        }
        $this->em->flush();
        if (($opts['subtasks'] ?? false) && !$source->isLeaf()) {
            foreach ($source->children as $child) {
                if ($child->id === $copy->id) {
                    continue;
                }
                $c = $this->copyFrom($child, $user, ['attachments' => $opts['attachments'] ?? false, 'subtasks' => true, 'watchers' => $opts['watchers'] ?? false]);
                $c->project = $copy->project;
                $c->parent = $copy;
                if ([] === $this->save($c, $user)) {
                    $this->afterCopy($c, $user);
                }
            }
        }
        unset($this->copyOptions[spl_object_id($copy)]);
    }

    /**
     * Deletes issues with their subtasks. $timeEntriesAction: destroy | nullify | reassign (then $reassignTo issue id).
     *
     * @param list<Issue> $issues
     */
    public function destroy(array $issues, string $timeEntriesAction = 'destroy', ?int $reassignToId = null, ?User $user = null): void
    {
        $user = $this->auth->user($user);
        $ids = [];
        foreach ($issues as $issue) {
            $ids[] = $issue->id;
            foreach ($issue->getDescendants() as $d) {
                $ids[] = $d->id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ([] === $ids) {
            return;
        }
        $parents = array_values(array_unique(array_filter(array_map(static fn (Issue $i) => $i->parent?->id, $issues))));
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            match ($timeEntriesAction) {
                'nullify' => $conn->executeStatement('UPDATE time_entries SET issue_id = NULL WHERE issue_id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER]),
                'reassign' => $conn->executeStatement('UPDATE time_entries SET issue_id = ? WHERE issue_id IN (?)', [$reassignToId, $ids], [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]),
                default => $conn->executeStatement('DELETE FROM time_entries WHERE issue_id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER]),
            };
            $p = [\Doctrine\DBAL\ArrayParameterType::INTEGER];
            $conn->executeStatement('DELETE FROM journal_details WHERE journal_id IN (SELECT id FROM journals WHERE journalized_type = \'Issue\' AND journalized_id IN (?))', [$ids], $p);
            $conn->executeStatement('DELETE FROM journals WHERE journalized_type = \'Issue\' AND journalized_id IN (?)', [$ids], $p);
            $conn->executeStatement('DELETE FROM issue_relations WHERE issue_from_id IN (?) OR issue_to_id IN (?)', [$ids, $ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]);
            $conn->executeStatement('DELETE FROM watchers WHERE watchable_type = \'Issue\' AND watchable_id IN (?)', [$ids], $p);
            $conn->executeStatement('DELETE FROM custom_values WHERE customized_type = \'Issue\' AND customized_id IN (?)', [$ids], $p);
            $conn->executeStatement('DELETE FROM changesets_issues WHERE issue_id IN (?)', [$ids], $p);
            $conn->executeStatement('DELETE FROM reactions WHERE reactable_type = \'Issue\' AND reactable_id IN (?)', [$ids], $p);
            $attachments = $conn->fetchAllAssociative('SELECT id FROM attachments WHERE container_type = \'Issue\' AND container_id IN (?)', [$ids], $p);
            $conn->executeStatement('DELETE FROM attachments WHERE container_type = \'Issue\' AND container_id IN (?)', [$ids], $p);
            $conn->executeStatement('UPDATE issues SET parent_id = NULL WHERE id IN (?)', [$ids], $p);
            $conn->executeStatement('DELETE FROM issues WHERE id IN (?)', [$ids], $p);
            $conn->commit();
            unset($attachments);
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
        $this->em->clear();
        foreach ($parents as $pid) {
            if (!\in_array($pid, $ids, true)) {
                $this->rebuildIssueTrees([$pid]);
                $this->recalculateAttributesFor($pid, $this->em->find(User::class, $user->id));
            }
        }
        foreach ($issues as $issue) {
            $this->events->dispatch(new IssueEvent($issue, IssueEvent::DELETED, $user, null));
        }
    }

    /** Spent time on issues to delete (for the confirmation screen) */
    public function spentHoursOn(array $issueIds): float
    {
        if ([] === $issueIds) {
            return 0.0;
        }

        return (float) $this->em->createQuery('SELECT COALESCE(SUM(t.hours), 0) FROM App\Entity\TimeEntry t WHERE IDENTITY(t.issue) IN (:ids)')->setParameter('ids', $issueIds)->getSingleScalarResult();
    }

    // ------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------

    public static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        $value = trim((string) $value);
        if ('' === $value) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $d ? null : $d;
    }

    /** Parses "1.5", "1,5", "1h30", "1:30", "90m" into hours (Redmine String#to_hours) */
    public static function parseHours(mixed $value): ?float
    {
        if (null === $value) {
            return null;
        }
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }
        $s = strtolower(trim((string) $value));
        if ('' === $s) {
            return null;
        }
        $s = str_replace(',', '.', $s);
        if (preg_match('/^(\d+)?:(\d+)$/', $s, $m)) {
            return (int) $m[1] + (int) $m[2] / 60;
        }
        if (preg_match('/^((\d+(\.\d+)?)\s*h(ours?)?)?\s*((\d+)\s*m(in)?)?$/', $s, $m) && ('' !== ($m[1] ?? '') || '' !== ($m[5] ?? ''))) {
            return (float) ($m[2] ?? 0) + (int) ($m[6] ?? 0) / 60;
        }

        return is_numeric($s) ? (float) $s : null;
    }

    /** Label for a journal detail (port of IssuesHelper#show_detail, text version) */
    public function detailLabel(\App\Entity\JournalDetail $d): array
    {
        $field = $d->propKey;
        $label = null;
        $old = $d->oldValue;
        $new = $d->value;
        $find = function (string $class, ?string $id) {
            if (null === $id || '' === $id) {
                return null;
            }
            $o = $this->em->find($class, (int) $id);

            return $o ? (method_exists($o, 'getName') ? $o->getName() : (string) $o) : null;
        };
        switch ($d->property) {
            case 'attr':
                $label = $this->i18n->l('field_'.preg_replace('/_id$/', '', $field === 'parent_id' ? 'parent_issue' : $field));
                [$old, $new] = match ($field) {
                    'due_date', 'start_date' => [$old ? $this->i18n->formatDate(self::parseDate($old)) : null, $new ? $this->i18n->formatDate(self::parseDate($new)) : null],
                    'project_id' => [$find(Project::class, $old), $find(Project::class, $new)],
                    'status_id' => [$find(IssueStatus::class, $old), $find(IssueStatus::class, $new)],
                    'tracker_id' => [$find(Tracker::class, $old), $find(Tracker::class, $new)],
                    'assigned_to_id' => [$find(Principal::class, $old), $find(Principal::class, $new)],
                    'priority_id' => [$find(Enumeration::class, $old), $find(Enumeration::class, $new)],
                    'category_id' => [$find(IssueCategory::class, $old), $find(IssueCategory::class, $new)],
                    'fixed_version_id' => [$find(Version::class, $old), $find(Version::class, $new)],
                    'estimated_hours' => [null !== $old && '' !== $old ? $this->i18n->formatHours((float) $old) : null, null !== $new && '' !== $new ? $this->i18n->formatHours((float) $new) : null],
                    'parent_id' => [$old ? '#'.$old : null, $new ? '#'.$new : null],
                    'is_private' => [null === $old ? null : ('1' === $old || 't' === $old || 'true' === $old ? $this->i18n->l('general_text_Yes') : $this->i18n->l('general_text_No')), null === $new ? null : ('1' === $new || 't' === $new || 'true' === $new ? $this->i18n->l('general_text_Yes') : $this->i18n->l('general_text_No'))],
                    default => [$old, $new],
                };
                break;
            case 'cf':
                $cf = $this->em->find(\App\Entity\CustomField::class, (int) $field);
                $label = $cf?->name ?? $field;
                if ($cf) {
                    $old = null !== $old ? $this->customFields->formatValue($cf, $old) : null;
                    $new = null !== $new ? $this->customFields->formatValue($cf, $new) : null;
                }
                break;
            case 'attachment':
                $label = $this->i18n->l('label_attachment');
                break;
            case 'relation':
                $label = $this->i18n->l(IssueRelation::TYPES[$field][0] ?? 'label_related_issues');
                break;
        }

        return ['label' => $label ?? $field, 'old' => $old, 'new' => $new, 'property' => $d->property, 'key' => $field];
    }
}

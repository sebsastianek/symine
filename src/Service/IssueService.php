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
        private readonly ?\App\Doctrine\BatchLoader $batch = null,
        private readonly ?\App\Doctrine\QueryMemo $memo = null,
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
        // asked for every issue of a board or of a bulk edit
        $all = fn (): array => null === $this->memo
            ? $this->em->getRepository(Role::class)->findAll()
            : $this->memo->get('roles:all', ['roles'], fn (): array => $this->em->getRepository(Role::class)->findAll());
        $roles = $user->admin ? $all() : $this->auth->rolesForProject($issue->project, $user);

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
        $this->batch?->descendants($issue, 'children');
        foreach ($issue->getDescendants() as $d) {
            if (!$d->isClosed()) {
                return false;
            }
        }
        $this->batch?->collection($issue, 'relationsTo');

        return !$issue->isBlocked();
    }

    public function closableWarning(Issue $issue): ?string
    {
        $this->batch?->descendants($issue, 'children');
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
        // custom fields are named by their id, which PHP keeps as an integer key: always hand out strings
        return array_map('strval', array_keys(array_filter($this->workflowRuleByAttribute($issue, $user), static fn ($r) => 'readonly' === $r)));
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
        $this->attributesSetBy[spl_object_id($issue)] = $user;

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
                    $this->changeTracker($issue, $t);
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
            // an id that does not exist is not "nobody": Redmine keeps it and the validation refuses it
            $this->markUnknownId($issue, 'assigned_to_id', null === $issue->assignedTo && !('' === $id || null === $id || 'none' === $id));
        }
        unset($attrs['assigned_to_id']);

        $safe = $this->safeAttributeNames($issue, $user);
        foreach ($attrs as $name => $value) {
            if (!\in_array($name, $safe, true)) {
                continue;
            }
            match ($name) {
                'subject' => $issue->subject = trim((string) $value),
                'description' => $issue->description = null === $value ? null : preg_replace('/\r\n?|\n/', "\r\n", (string) $value),
                'category_id' => $this->markUnknownId($issue, 'category_id', null === ($issue->category = ('' === (string) $value) ? null : $this->em->find(IssueCategory::class, (int) $value)) && '' !== (string) $value),
                'priority_id' => $this->assignPriority($issue, $value),
                'fixed_version_id' => $this->markUnknownId($issue, 'fixed_version_id', null === ($issue->fixedVersion = ('' === (string) $value) ? null : $this->em->find(Version::class, (int) $value)) && '' !== (string) $value),
                'start_date', 'due_date' => $this->assignDate($issue, $name, $value),
                'done_ratio' => $issue->doneRatio = '' === (string) $value ? 0 : (int) $value,
                'estimated_hours' => $this->assignEstimatedHours($issue, $value),
                'is_private' => $issue->isPrivate = \in_array($value, [true, 1, '1', 'true'], true),
                'lock_version' => $issue->lockVersion = (int) $value,
                default => null,
            };
        }
        if (\in_array('parent_issue_id', $safe, true) && \array_key_exists('parent_issue_id', $attrs)) {
            $this->parentAssigned[spl_object_id($issue)] = true;
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
            $this->customValuesChanged[spl_object_id($issue)] = true;
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

    /** @var array<int, array<string, true>> invalid date strings per issue object (validates :start_date, :due_date, :date => true) */
    private array $invalidDates = [];

    /** @var array<int, string> submitted estimated time of the issues for which it is not a number (validates :estimated_hours, numericality) */
    private array $invalidHours = [];

    /** @var array<int, true> issues whose submitted priority does not exist (validates_presence_of :priority) */
    private array $invalidPriority = [];

    /** @var array<int, true> issues whose custom field values were assigned or reassigned (custom_field_values_changed?) */
    private array $customValuesChanged = [];

    /** @var array<int, array<string, true>> submitted assigned_to_id/category_id/fixed_version_id that do not exist */
    private array $unknownIds = [];

    /** @var array<int, User> user who assigned the attributes of a new issue (@attributes_set_by) */
    private array $attributesSetBy = [];

    /** @var array<int, true> issues whose parent_issue_id was submitted (@parent_issue) */
    private array $parentAssigned = [];

    private function markUnknownId(Issue $issue, string $attribute, bool $unknown): void
    {
        if ($unknown) {
            $this->unknownIds[spl_object_id($issue)][$attribute] = true;
        } else {
            unset($this->unknownIds[spl_object_id($issue)][$attribute]);
        }
    }

    /** The estimated time as submitted when it is not a number (to display the form again), null otherwise */
    public function invalidEstimatedHours(Issue $issue): ?string
    {
        return $this->invalidHours[spl_object_id($issue)] ?? null;
    }

    /** Issue#was_closed?: the status the issue has in the database is a closed one */
    public function wasClosed(Issue $issue): bool
    {
        if ($issue->isNew()) {
            return false;
        }
        $closed = $this->em->getConnection()->fetchOne('SELECT s.is_closed FROM issues i INNER JOIN issue_statuses s ON s.id = i.status_id WHERE i.id = ?', [$issue->id]);

        return \in_array($closed, [1, '1', true, 't', 'true'], true);
    }

    /** Issue#priority_id=: a blank value falls back to the default priority, an unknown id leaves the priority blank */
    private function assignPriority(Issue $issue, mixed $value): void
    {
        $oid = spl_object_id($issue);
        unset($this->invalidPriority[$oid]);
        if (null === $value || '' === trim((string) $value)) {
            if ($p = $this->defaultPriority()) {
                $issue->priority = $p;
            }

            return;
        }
        $p = ctype_digit((string) $value) ? $this->em->find(IssuePriority::class, (int) $value) : null;
        if (null !== $p) {
            $issue->priority = $p;
        } else {
            $this->invalidPriority[$oid] = true;
        }
    }

    /** Issue#estimated_hours=: String#to_hours, a value that is not a number is kept for the validation error */
    private function assignEstimatedHours(Issue $issue, mixed $value): void
    {
        $oid = spl_object_id($issue);
        unset($this->invalidHours[$oid]);
        if (null === $value || (\is_string($value) && '' === trim($value))) {
            $issue->estimatedHours = null;

            return;
        }
        $hours = \is_bool($value) || \is_array($value) ? null : self::parseHours($value);
        if (null === $hours) {
            $this->invalidHours[$oid] = \is_scalar($value) ? (string) $value : '';

            return;
        }
        $issue->estimatedHours = $hours;
    }

    private function assignDate(Issue $issue, string $name, mixed $value): void
    {
        $date = self::parseDate($value);
        if (null === $date && '' !== trim((string) $value)) {
            $this->invalidDates[spl_object_id($issue)][$name] = true;
        } else {
            unset($this->invalidDates[spl_object_id($issue)][$name]);
        }
        // the same day submitted again is not a change (the object is kept, so that Doctrine sees none either)
        $current = 'start_date' === $name ? $issue->startDate : $issue->dueDate;
        if (null !== $date && $current?->format('Y-m-d') === $date->format('Y-m-d')) {
            return;
        }
        if ('start_date' === $name) {
            $issue->startDate = $date;
        } else {
            $issue->dueDate = $date;
        }
    }
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

    /**
     * Issue#tracker=: the status becomes the default status of the new tracker when it was the default
     * status of the previous tracker, or when it is not part of the new tracker's statuses.
     */
    public function changeTracker(Issue $issue, Tracker $tracker): void
    {
        $was = isset($issue->tracker) ? $issue->tracker : null;
        $issue->tracker = $tracker;
        if (null !== $was && $was->id !== $tracker->id && isset($issue->status)) {
            $reset = ($was->defaultStatus && $was->defaultStatus->id === $issue->status->id)
                || !\in_array($issue->status->id, $this->trackerStatusIds($tracker), true);
            if ($reset && $tracker->defaultStatus) {
                $issue->status = $tracker->defaultStatus;
            }
        }
        $this->workflowRulesCache = [];
    }

    /**
     * Issue#clear_disabled_fields (before_validation): core fields disabled for the tracker are blanked,
     * then the priority and % done fall back to their defaults.
     */
    private function clearDisabledFields(Issue $issue): void
    {
        if (!isset($issue->tracker)) {
            return;
        }
        foreach ($issue->tracker->getDisabledCoreFields() as $field) {
            switch ($field) {
                case 'assigned_to_id': $issue->assignedTo = null; break;
                case 'category_id': $issue->category = null; break;
                case 'fixed_version_id': $issue->fixedVersion = null; break;
                case 'parent_issue_id': $issue->parent = null; unset($this->invalidParent[spl_object_id($issue)]); break;
                case 'start_date': $issue->startDate = null; unset($this->invalidDates[spl_object_id($issue)]['start_date']); break;
                case 'due_date': $issue->dueDate = null; unset($this->invalidDates[spl_object_id($issue)]['due_date']); break;
                case 'estimated_hours': $issue->estimatedHours = null; unset($this->invalidHours[spl_object_id($issue)]); break;
                case 'done_ratio': $issue->doneRatio = 0; break;
                case 'description': $issue->description = null; break;
                case 'priority_id':
                    unset($this->invalidPriority[spl_object_id($issue)]);
                    if ($p = $this->defaultPriority()) {
                        $issue->priority = $p;
                    }
                    break;
            }
        }
        if (!isset($issue->priority) && !isset($this->invalidPriority[spl_object_id($issue)]) && ($p = $this->defaultPriority())) {
            $issue->priority = $p;
        }
    }

    /** Moves the issue to another project, resetting incompatible attributes (Issue#project=) */
    public function changeProject(Issue $issue, Project $project, bool $keepTracker = false): void
    {
        $issue->project = $project;
        if (!$keepTracker && isset($issue->tracker) && !$project->hasTracker($issue->tracker)) {
            $first = $project->trackers->first();
            if ($first) {
                $this->changeTracker($issue, $first);
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
        // Issue#assignable_users: the persisted assignee (assigned_to_id_was) stays assignable, not a new one
        if (!$issue->isNew() && ($wasId = $this->em->getConnection()->fetchOne('SELECT assigned_to_id FROM issues WHERE id = ?', [$issue->id]))
            && ($was = $this->em->find(Principal::class, (int) $wasId)) && !\in_array($was, $users, true)) {
            $users[] = $was;
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
        $shared = $this->projects->sharedVersions($issue->project);
        $versions = array_values(array_filter($shared, static fn (Version $v) => $v->isOpen()));
        if ($issue->fixedVersion && !\in_array($issue->fixedVersion, $versions, true)) {
            // Issue#assignable_versions: a closed version is kept only when it is the unchanged persisted one
            $was = $issue->isNew() ? false : $this->em->getConnection()->fetchAssociative('SELECT fixed_version_id, project_id FROM issues WHERE id = ?', [$issue->id]);
            if (\is_array($was) && (int) $was['fixed_version_id'] === $issue->fixedVersion->id
                && ((int) $was['project_id'] === $issue->project->id || \in_array($issue->fixedVersion, $shared, true))) {
                $versions[] = $issue->fixedVersion;
            }
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
            // no project: no tracker nor default status either (same errors as Redmine)
            if (!isset($issue->tracker)) {
                $errors[] = $field('tracker').' '.$msg('blank');
            }
            if (!isset($issue->status)) {
                $errors[] = $field('status').' '.$msg('blank');
            }

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
        if (!isset($issue->priority) || isset($this->invalidPriority[spl_object_id($issue)])) {
            $errors[] = $field('priority').' '.$msg('blank');
        }
        if (!isset($issue->status)) {
            $errors[] = $field('status').' '.$msg('blank');
        }
        if ($issue->doneRatio < 0 || $issue->doneRatio > 100) {
            $errors[] = $field('done_ratio').' '.$msg('inclusion');
        }
        if (isset($this->invalidHours[spl_object_id($issue)]) || (null !== $issue->estimatedHours && $issue->estimatedHours < 0)) {
            $errors[] = $field('estimated_hours').' '.$msg('invalid');
        }
        // (start_date_changed? || due_date_changed?): dates that were already inconsistent do not block other updates
        if ($issue->dueDate && $issue->startDate && $issue->dueDate < $issue->startDate
            && ($issue->isNew() || $issue->startDate->format('Y-m-d') !== substr((string) ($original['snapshot']['start_date'] ?? ''), 0, 10)
                || $issue->dueDate->format('Y-m-d') !== substr((string) ($original['snapshot']['due_date'] ?? ''), 0, 10))) {
            $errors[] = $field('due_date').' '.$msg('greater_than_start_date');
        }
        if ($issue->startDate && ($soonest = $this->soonestStart($issue)) && $issue->startDate < $soonest
            && ($issue->isNew() || $issue->startDate->format('Y-m-d') !== ($original['start_date'] ?? null))) {
            $errors[] = $field('start_date').' '.$msg('earlier_than_minimum_start_date', ['date' => $this->i18n->formatDate($soonest)]);
        }
        $unknown = $this->unknownIds[spl_object_id($issue)] ?? [];
        if (isset($unknown['fixed_version_id'])) {
            // fixed_version.nil? with a fixed_version_id
            $errors[] = $field('fixed_version').' '.$msg('inclusion');
        }
        if (isset($unknown['category_id'])) {
            // project.issue_category_ids.include?(category_id)
            $errors[] = $field('category').' '.$msg('inclusion');
        }
        if (isset($unknown['assigned_to_id'])) {
            // assignable_users.include?(assigned_to) with an assigned_to_id that does not exist
            $errors[] = $field('assigned_to').' '.$msg('invalid');
        }
        // Issue#validate_permissions: the user who copies an issue must be allowed to add issues with its tracker
        if ($issue->isNew() && isset($this->copyOptions[spl_object_id($issue)], $this->attributesSetBy[spl_object_id($issue)])
            && !\in_array($issue->tracker, $this->projects->allowedTargetTrackers($issue->project, $this->attributesSetBy[spl_object_id($issue)]), true)) {
            $errors[] = $field('tracker').' '.$msg('invalid');
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
        foreach (array_keys($this->invalidDates[spl_object_id($issue)] ?? []) as $dateField) {
            $errors[] = $field($dateField).' '.$msg('not_a_date');
        }
        if (isset($this->invalidParent[spl_object_id($issue)])) {
            $errors[] = $field('parent_issue').' '.$msg('invalid');
        } elseif ($issue->parent && ($issue->isNew() || isset($this->parentAssigned[spl_object_id($issue)]) || $issue->parent->id !== ($original['parent_id'] ?? null))) {
            // @parent_issue: only when the parent was assigned (a save that does not touch it does not validate it again)
            $p = $issue->parent;
            if (!$this->isValidParentProject($issue, $p)) {
                $errors[] = $field('parent_issue').' '.$msg('invalid');
            } elseif ($p->id !== ($original['parent_id'] ?? null) && !$issue->isNew() && $p->id !== $issue->id && !$p->isDescendantOf($issue)
                && $this->parentWouldCreateSchedulingLoop($issue, $p)) {
                // the new parent would be rescheduled by this issue, or precedes an issue that reschedules it
                $errors[] = $field('parent_issue').' '.$msg('invalid');
            } elseif (!$issue->isClosed() && $p->isClosed()) {
                // cannot attach an open issue to a closed parent
                $errors[] = $msg('open_issue_with_closed_parent');
            } elseif (!$issue->isNew() && ($p->id === $issue->id || $p->isDescendantOf($issue))) {
                // move_possible?: an issue cannot be moved under itself or one of its descendants
                $errors[] = $field('parent_issue').' '.$msg('invalid');
            } elseif ($issue->isNew() && $p->id === $issue->id) {
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
                if ($empty && 'category_id' === $attr && 0 === $issue->project->issueCategories->count()) {
                    continue; // next if attribute == 'category_id' && project.try(:issue_categories).blank?
                }
                if ($empty && 'fixed_version_id' === $attr && [] === $this->assignableVersions($issue)) {
                    continue; // next if attribute == 'fixed_version_id' && assignable_versions.blank?
                }
                if ($empty && $issue->tracker->isCoreFieldEnabled($attr)) {
                    $errors[] = $field(preg_replace('/_id$/', '', $attr) ?? $attr).' '.$msg('blank');
                }
            }
        }
        // Issue#validate_custom_field_values: custom field formats & required, only for a new issue or when
        // custom field values were assigned (or reassigned by a tracker/project change), and only for the
        // fields the user can edit
        if ($issue->isNew() || $this->customFieldValuesChanged($issue, $original)) {
            $current = $issue->isNew() ? $this->customFields->defaultValues($this->customFields->availableIssueFields($issue)) : $this->customFields->values('Issue', $issue->id);
            $pending = $this->pendingCustomValues[spl_object_id($issue)] ?? [];
            foreach ($this->editableCustomFields($issue, $issue->isNew() ? ($issue->author ?? $user) : $user) as $cf) {
                $value = \array_key_exists($cf->id, $pending) ? $pending[$cf->id] : (\array_key_exists((string) $cf->id, $pending) ? $pending[(string) $cf->id] : ($current[$cf->id] ?? null));
                array_push($errors, ...$this->customFields->validate($cf, $value, $issue->project, $issue->isNew() ? null : ($current[$cf->id] ?? null)));
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * acts_as_customizable custom_field_values_changed?: values were assigned through safe attributes,
     * or reassigned because the tracker or the project changed.
     *
     * @param array<string, mixed> $original
     */
    private function customFieldValuesChanged(Issue $issue, array $original): bool
    {
        if (isset($this->customValuesChanged[spl_object_id($issue)])) {
            return true;
        }

        return !$issue->isNew() && (($original['tracker_id'] ?? null) !== $issue->tracker->id || ($original['project_id'] ?? null) !== $issue->project->id);
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

    /**
     * Issue#validate_issue, parent assignment: self.would_reschedule?(parent) or one of the parent's
     * (self and ancestors) following issues would reschedule this issue.
     */
    private function parentWouldCreateSchedulingLoop(Issue $issue, Issue $parent): bool
    {
        // the relations are followed as they are in the database: the pending parent of $issue is not part of them
        $pending = $issue->parent;
        $issue->parent = null !== ($pid = $this->originalValues($issue)['parent_id'] ?? null) ? $this->em->find(Issue::class, $pid) : null;
        try {
            if ($this->wouldReschedule($issue, $parent)) {
                return true;
            }
            foreach ([$parent, ...$parent->getAncestors()] as $a) {
                foreach ($a->relationsFrom as $r) {
                    if (IssueRelation::TYPE_PRECEDES === $r->relationType && $this->wouldReschedule($r->issueTo, $issue)) {
                        return true;
                    }
                }
            }

            return false;
        } finally {
            $issue->parent = $pending;
        }
    }

    /**
     * Earliest start date allowed by "precedes" relations (Issue#soonest_start); the parent's soonest start
     * applies too when the dates of parent tasks are derived from their subtasks.
     */
    public function soonestStart(Issue $issue): ?\DateTimeImmutable
    {
        $dates = [];
        foreach ($issue->relationsTo as $rel) {
            if (IssueRelation::TYPE_PRECEDES === $rel->relationType && ($d = $this->successorSoonestStart($rel))) {
                $dates[] = $d;
            }
        }
        if ($issue->parent && 'derived' === $this->settings->string('parent_issue_dates') && !$issue->parent->isDescendantOf($issue)
            && $issue->parent->id !== $issue->id && ($d = $this->soonestStart($issue->parent))) {
            $dates[] = $d;
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
        return $this->performSave($issue, $this->auth->user($user), true);
    }

    /**
     * Issue#save without init_journal: the changes are saved but not journalized, hence not notified
     * (e.g. the parent set by a CSV import callback: child.save!).
     *
     * @return list<string> errors (empty on success)
     */
    public function saveWithoutJournal(Issue $issue, ?User $user = null): array
    {
        return $this->performSave($issue, $this->auth->user($user), false);
    }

    /** @var list<IssueEvent> events queued until the outermost save/relation change is committed */
    private array $queuedEvents = [];

    private int $unitDepth = 0;

    /** Starts a unit of work whose notifications/webhooks are sent once everything is committed */
    private function beginUnit(): void
    {
        ++$this->unitDepth;
    }

    private function endUnit(bool $success): void
    {
        if (--$this->unitDepth > 0) {
            return;
        }
        $this->unitDepth = 0;
        $events = $this->queuedEvents;
        $this->queuedEvents = [];
        if ($success) {
            foreach ($events as $event) {
                $this->events->dispatch($event);
            }
        }
    }

    private function queueEvent(IssueEvent $event): void
    {
        if ($this->unitDepth > 0) {
            $this->queuedEvents[] = $event;
        } else {
            $this->events->dispatch($event);
        }
    }

    /**
     * Journal#save with its callbacks: an empty journal is not saved, private notes submitted with changes are
     * split (Journal#split_private_notes), the author and the assignee are added as watchers according to
     * their preferences (Journal#add_watcher) and the notification is queued (Journal#send_notification).
     *
     * @param bool $issueSaved false when only the journal is saved (relations): Redmine then sends the
     *                         e-mail notification but no issue.updated webhook
     *
     * @return ?Journal the saved journal (the one holding the notes), null when there was nothing to save
     */
    private function storeJournal(Issue $issue, Journal $journal, User $user, bool $issueSaved = true): ?Journal
    {
        if ($journal->isEmpty()) {
            return null;
        }
        $changes = null;
        if ($journal->privateNotes && !$journal->hasNotes()) {
            // blank notes are never private
            $journal->privateNotes = false;
        } elseif ($journal->privateNotes && $journal->details->count() > 0) {
            // the changes stay visible to everyone, the notes are private
            $changes = new Journal();
            $changes->issue = $issue;
            $changes->user = $journal->user;
            $changes->notes = null;
            $changes->privateNotes = false;
            $changes->createdOn = $journal->createdOn;
            $changes->updatedOn = $journal->updatedOn;
            foreach ($journal->details->toArray() as $d) {
                $journal->details->removeElement($d);
                $d->journal = $changes;
                $changes->details->add($d);
            }
            $this->em->persist($changes);
            $issue->journals->add($changes);
        }
        $this->em->persist($journal);
        $issue->journals->add($journal);
        $this->em->flush();

        // Journal#add_watcher
        $author = $journal->user;
        if ($this->autoWatchOn($author, 'issue_contributed_to') && $this->isAutoWatcher($issue, $author)) {
            $this->addWatcher('Issue', (int) $issue->id, $author);
            $this->em->flush();
        }
        $assignee = $issue->assignedTo;
        if ($assignee instanceof User && $this->autoWatchOn($assignee, 'issue_assigned_to_me') && $this->isAutoWatcher($issue, $assignee)) {
            $this->addWatcher('Issue', (int) $issue->id, $assignee);
            $this->em->flush();
        }

        if (isset($this->silentJournals[(int) $issue->id])) {
            return $journal;
        }
        if (null !== $changes) {
            $this->queueEvent(new IssueEvent($issue, IssueEvent::UPDATED, $user, $changes, false));
        }
        $this->queueEvent(new IssueEvent($issue, IssueEvent::UPDATED, $user, $journal, $issueSaved));

        return $journal;
    }

    /** UserPreference#auto_watch_on? */
    private function autoWatchOn(User $user, string $action): bool
    {
        if (!$user->isLogged()) {
            return false;
        }

        return \in_array($action, (array) $user->getPref()->get('auto_watch_on', ['issue_created', 'issue_contributed_to']), true);
    }

    /** Issue#auto_watcher? / Journal#valid_watcher?: active, allowed to add watchers, can see the issue, not watching yet */
    private function isAutoWatcher(Issue $issue, User $user): bool
    {
        if (!$user->isLogged() || !$user->isActive() || !$this->auth->allowedTo('add_issue_watchers', $issue->project, $user) || !$this->isVisible($issue, $user)) {
            return false;
        }

        return null === $this->em->getRepository(Watcher::class)->findOneBy(['watchableType' => 'Issue', 'watchableId' => $issue->id, 'user' => $user]);
    }

    /** @return list<string> */
    private function performSave(Issue $issue, User $user, bool $journalize): array
    {
        $oid = spl_object_id($issue);
        // Issue#default_assign (before_validation on create): category assignee, then project default assignee
        if ($issue->isNew() && null === $issue->assignedTo && isset($issue->project)) {
            $assignable = null;
            foreach ([$issue->category?->assignedTo, $issue->project->defaultAssignedTo] as $candidate) {
                if (null !== $candidate && \in_array($candidate, $assignable ??= $this->assignableUsers($issue), true)) {
                    $issue->assignedTo = $candidate;
                    break;
                }
            }
        }
        $this->clearDisabledFields($issue);
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
        $projectWasId = $isNew ? null : ($original['project_id'] ?? null);
        $projectChanged = null !== $projectWasId && $projectWasId !== $issue->project->id;
        $trackerChanged = !$isNew && ($original['tracker_id'] ?? null) !== $issue->tracker->id;
        $parentWas = $original['parent_id'] ?? null;
        $journal = null;

        $conn = $this->em->getConnection();
        $this->beginUnit();
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
            // Issue#force_updated_on_change: updated_on only moves when something changes. An update that changes
            // nothing is not written at all, so it does not bump lock_version either (no edit conflict for others).
            $written = $isNew || $this->hasChanges($issue);
            if ($written) {
                $issue->updatedOn = $now;
            }
            if ($isNew && isset($issue->tracker) && $issue->tracker->privateByDefault && !$this->canSetPrivate($issue, $user)) {
                $issue->isPrivate = true;
            }
            $this->em->persist($issue);
            $this->em->flush();

            // custom values
            $fields = $this->customFields->availableIssueFields($issue);
            $values = $this->pendingCustomValues[$oid] ?? [];
            if ($isNew) {
                $defaults = $this->customFields->defaultValues($fields);
                // Issue#force_default_value_on_noneditable_custom_fields: values the author cannot edit
                // (e.g. carried over by a copy) are replaced by the default value of the field
                if ([] !== $values) {
                    $editableIds = array_map(static fn ($f) => (int) $f->id, $this->editableCustomFields($issue, $issue->author ?? $user));
                    foreach ($fields as $f) {
                        if (!\in_array((int) $f->id, $editableIds, true)) {
                            unset($values[$f->id], $values[(string) $f->id]);
                        }
                    }
                }
                $values += $defaults;
            }
            $cfChanges = $this->customFields->saveValues('Issue', (int) $issue->id, $fields, $values);
            if (!$isNew) {
                // save_custom_field_values runs after every save (acts_as_customizable after_save):
                // "self.custom_values = target_custom_values" only keeps the values of the custom fields that
                // are available for the issue. A removed value is journalized when its field stopped applying
                // because of this change of tracker/project (it was in the journal's snapshot of the values).
                $removed = $this->pruneCustomValues($issue, $fields);
                if ([] !== $removed && ($projectChanged || $trackerChanged)) {
                    $before = new Issue();
                    $before->project = $this->em->find(Project::class, (int) $projectWasId) ?? $issue->project;
                    $before->tracker = $this->em->find(Tracker::class, (int) ($original['tracker_id'] ?? 0)) ?? $issue->tracker;
                    $beforeIds = array_map(static fn ($f) => (int) $f->id, $this->customFields->availableIssueFields($before));
                    foreach (array_keys($removed) as $fid) {
                        $old = $oldCustom[$fid] ?? null;
                        if (\in_array((int) $fid, $beforeIds, true) && [] !== array_filter((array) $old, static fn ($v) => null !== $v && '' !== (string) $v)) {
                            $cfChanges[$fid] = [$old, \is_array($old) ? [] : null];
                        }
                    }
                }
            }

            // nested set
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
            if (!$isNew && $journalize) {
                $journal = new Journal();
                $journal->issue = $issue;
                $journal->user = $user;
                $journal->notes = $notes;
                $journal->privateNotes = $privateNotes;
                $journal->createdOn = $now;
                $journal->updatedOn = $now;
                $this->addAttributeDetails($journal, $issue, $original['snapshot']);
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
                $journal = $this->storeJournal($issue, $journal, $user);
            }
            // notes, attachments and custom values change the issue too (the journal, or acts_as_customizable's touch)
            if (!$written && (null !== $journal || [] !== $cfChanges || [] !== $attachmentsAdded || [] !== $attachmentsDeleted)) {
                $issue->updatedOn = $now;
                $written = true;
            }

            if ($isNew) {
                // Issue#copy_from assigns the watchers and the attachments of the copied issue before the copy
                // is saved (so that the "issue added" notification goes to the watchers); the watchers
                // submitted with the form replace them
                $copyOptions = $this->copyOptions[$oid] ?? null;
                $copied = null !== $copyOptions ? $this->em->find(Issue::class, $copyOptions['source']) : null;
                $watchers = [];
                if (isset($this->pendingWatchers[$oid])) {
                    foreach ($this->pendingWatchers[$oid] as $uid) {
                        if ($p = $this->em->find(Principal::class, $uid)) {
                            $watchers[] = $p;
                        }
                    }
                } elseif (null !== $copied && false !== ($copyOptions['watchers'] ?? true)) {
                    // issue.visible_watcher_users.select {|u| u.status == User::STATUS_ACTIVE}
                    $canView = $this->auth->allowedTo('view_issue_watchers', $copied->project, $user);
                    foreach ($this->watchers($copied) as $w) {
                        if ($w->isActive() && ($canView || $w->id === $user->id)) {
                            $watchers[] = $w;
                        }
                    }
                }
                foreach ($watchers as $p) {
                    $this->addWatcher('Issue', (int) $issue->id, $p);
                }
                if (null !== $copied) {
                    if (false !== ($copyOptions['attachments'] ?? true)) {
                        // Attachment#copy: the same file with its author and creation date, downloads excepted
                        foreach ($this->em->getRepository(Attachment::class)->findBy(['containerType' => 'Issue', 'containerId' => $copied->id], ['id' => 'ASC']) as $a) {
                            $c = clone $a;
                            $c->id = null;
                            $c->containerId = $issue->id;
                            $c->downloads = 0;
                            $this->em->persist($c);
                        }
                    }
                    $this->copyOptions[$oid]['saved_with_issue'] = true;
                }
                $this->em->flush();
                $author = $issue->author ?? null;
                if ($author instanceof User && $this->autoWatchOn($author, 'issue_created') && $this->isAutoWatcher($issue, $author)) {
                    $this->addWatcher('Issue', (int) $issue->id, $author);
                    $this->em->flush();
                }
                $assignee = $issue->assignedTo;
                if ($assignee instanceof User && $this->autoWatchOn($assignee, 'issue_assigned_to_me') && $this->isAutoWatcher($issue, $assignee)) {
                    $this->addWatcher('Issue', (int) $issue->id, $assignee);
                }
            }
            $this->em->flush();

            // Issue#after_project_change
            if ($projectChanged) {
                $moveErrors = $this->afterProjectChange($issue, (int) $projectWasId, $user);
                if ([] !== $moveErrors) {
                    $conn->rollBack();
                    $this->endUnit(false);

                    return $moveErrors;
                }
            }

            // parent attributes, closing duplicates, rescheduling followers
            foreach (array_unique(array_filter([$parentNow, $parentWas])) as $pid) {
                $this->recalculateAttributesFor((int) $pid, $user);
            }
            if (!$isNew && $issue->isClosed() && !$wasClosed) {
                $this->closeDuplicates($issue, $user, $journalize ? $notes : '', $journalize && $privateNotes, $journalize);
            }
            if (!$isNew && ($issue->dueDate?->format('Y-m-d') !== ($original['snapshot']['due_date'] ?? null)
                || $issue->startDate?->format('Y-m-d') !== ($original['snapshot']['start_date'] ?? null))) {
                $this->rescheduleFollowingIssues($issue, $user, $journalize);
            }
            $this->em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->endUnit(false);
            throw $e;
        }
        $this->clearPending($issue);
        $this->auth->reset();

        if ($isNew) {
            $this->queueEvent(new IssueEvent($issue, IssueEvent::CREATED, $user, null));
        } elseif (null === $journal && $written) {
            // the issue was saved without a journal: issue.updated webhook, no e-mail
            $this->queueEvent(new IssueEvent($issue, IssueEvent::UPDATED, $user, null));
        }
        // Issue#create_parent_issue_journal (after_commit)
        if ($isNew || $parentWas !== $parentNow) {
            $this->journalizeParentChange((int) $issue->id, $isNew ? null : $parentWas, $parentNow, $user);
        }
        $this->endUnit(true);

        return [];
    }

    /** ActiveRecord's changed?: whether saving the issue would write anything to its row */
    private function hasChanges(Issue $issue): bool
    {
        if (!$this->em->contains($issue)) {
            return true;
        }
        $meta = $this->em->getClassMetadata(Issue::class);
        $changed = false;
        foreach ($this->em->getUnitOfWork()->getOriginalEntityData($issue) as $name => $was) {
            if (!$meta->hasField($name) && !($meta->hasAssociation($name) && $meta->isSingleValuedAssociation($name))) {
                continue;
            }
            $now = $meta->getFieldValue($issue, $name);
            if ($was === $now) {
                continue;
            }
            if ($was instanceof \DateTimeInterface && $now instanceof \DateTimeInterface && $was == $now) {
                // the same moment in another object: put the original back, or Doctrine would write the row
                $meta->setFieldValue($issue, $name, $was);
                continue;
            }
            if (null === $was && '' === $now) {
                // an empty field of the form for a value that was never set (an issue created through the API
                // without a description): nothing the journal would record, so nothing to write either
                $meta->setFieldValue($issue, $name, null);
                continue;
            }
            $changed = true;
        }

        return $changed;
    }

    /**
     * acts_as_customizable#save_custom_field_values, run after every save of an issue: the custom values of the
     * fields that are not available for the issue (tracker/project) are deleted. The caller flushes.
     *
     * @param list<\App\Entity\CustomField>|null $fields the available fields when already known
     *
     * @return array<int, true> ids of the custom fields whose values were removed
     */
    private function pruneCustomValues(Issue $issue, ?array $fields = null): array
    {
        if ($issue->isNew()) {
            return [];
        }
        $ids = array_map(static fn ($f) => (int) $f->id, $fields ?? $this->customFields->availableIssueFields($issue));
        $removed = [];
        foreach ($this->em->getRepository(\App\Entity\CustomValue::class)->findBy(['customizedType' => 'Issue', 'customizedId' => $issue->id]) as $cv) {
            if (!\in_array((int) $cv->customField->id, $ids, true)) {
                $removed[(int) $cv->customField->id] = true;
                $this->em->remove($cv);
            }
        }

        return $removed;
    }

    /** Adds the "attr" details of the journalized attributes that differ from the snapshot */
    private function addAttributeDetails(Journal $journal, Issue $issue, array $snapshot, ?array $attributes = null): void
    {
        foreach ($attributes ?? self::JOURNALIZED_ATTRIBUTES as $attr) {
            $old = $snapshot[$attr] ?? null;
            $new = $this->attributeValue($issue, $attr);
            if ($this->normalizeForCompare($attr, $old) !== $this->normalizeForCompare($attr, $new)) {
                $journal->addDetail('attr', $attr, $this->normalizeForCompare($attr, $old), $this->normalizeForCompare($attr, $new));
            }
        }
    }

    /**
     * Issue#after_project_change: the time entries follow the issue (with the activity of the new project),
     * the relations are removed unless cross-project relations are allowed and the subtasks that were in
     * the same project are moved along.
     *
     * @return list<string> errors when a subtask cannot be moved (the whole move is then rolled back)
     */
    private function afterProjectChange(Issue $issue, int $projectWasId, User $user): array
    {
        $conn = $this->em->getConnection();
        foreach ($conn->fetchFirstColumn('SELECT DISTINCT activity_id FROM time_entries WHERE issue_id = ?', [$issue->id]) as $activityId) {
            $conn->executeStatement('UPDATE time_entries SET project_id = ?, activity_id = ? WHERE issue_id = ? AND activity_id = ?',
                [$issue->project->id, $this->activityIdForProject((int) $activityId, $issue->project), $issue->id, (int) $activityId]);
        }
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[TimeEntry::class] ?? [] as $entry) {
            if ($entry->issue?->id === $issue->id) {
                $this->em->refresh($entry);
            }
        }

        if (!$this->settings->bool('cross_project_issue_relations')) {
            foreach ($issue->getRelations() as $rel) {
                $rel->issueFrom->relationsFrom->removeElement($rel);
                $rel->issueTo->relationsTo->removeElement($rel);
                $this->em->remove($rel);
            }
            $this->em->flush();
        }

        foreach ($issue->children->toArray() as $child) {
            if ($child->project->id !== $projectWasId) {
                continue;
            }
            // the tracker is kept, the child is saved without a journal like in Redmine
            $this->changeProject($child, $issue->project, true);
            $errors = $this->performSave($child, $user, false);
            if ([] !== $errors) {
                return [$this->i18n->l('error_move_of_child_not_possible', ['child' => '#'.$child->id, 'errors' => implode(', ', $errors)])];
            }
        }

        return [];
    }

    /** TimeEntryActivity.activity_id_for_project: the project override of the shared activity if any */
    private function activityIdForProject(int $activityId, Project $project): int
    {
        $row = $this->em->getConnection()->fetchAssociative("SELECT id, parent_id FROM enumerations WHERE id = ? AND type = 'TimeEntryActivity'", [$activityId]);
        if (false === $row) {
            return $activityId;
        }
        $shared = null !== $row['parent_id'] ? (int) $row['parent_id'] : (int) $row['id'];
        $override = $this->em->getConnection()->fetchOne("SELECT id FROM enumerations WHERE type = 'TimeEntryActivity' AND project_id = ? AND parent_id = ?", [$project->id, $shared]);

        return false !== $override && null !== $override ? (int) $override : $shared;
    }

    /**
     * Issue#create_parent_issue_journal: "Subtask #N added/removed" in the history of the former and of the
     * new parent (when the user can see them).
     */
    private function journalizeParentChange(int $childId, ?int $oldParentId, ?int $newParentId, User $user): void
    {
        foreach ([[$oldParentId, $childId, null], [$newParentId, null, $childId]] as [$parentId, $old, $new]) {
            if (null === $parentId) {
                continue;
            }
            $parent = $this->em->find(Issue::class, $parentId);
            if (null === $parent || !$this->isVisible($parent, $user)) {
                continue;
            }
            $journal = new Journal();
            $journal->issue = $parent;
            $journal->user = $user;
            $journal->addDetail('attr', 'child_id', $old, $new);
            $parent->updatedOn = new \DateTimeImmutable();
            $this->pruneCustomValues($parent);
            $this->storeJournal($parent, $journal, $user);
        }
    }

    private function clearPending(Issue $issue): void
    {
        $oid = spl_object_id($issue);
        unset($this->pendingCustomValues[$oid], $this->pendingNotes[$oid], $this->pendingPrivateNotes[$oid],
            $this->pendingWatchers[$oid], $this->pendingDeletedAttachments[$oid], $this->pendingAttachments[$oid], $this->invalidParent[$oid], $this->invalidDates[$oid],
            $this->invalidHours[$oid], $this->invalidPriority[$oid], $this->customValuesChanged[$oid], $this->unknownIds[$oid], $this->attributesSetBy[$oid], $this->parentAssigned[$oid]);
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
            // Float#to_s: "2.0", "2.5" (PHP would print "2")
            'estimated_hours' => var_export((float) $v, true),
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
        $datesChanged = false;
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
                $datesChanged = true;
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
        }
        if ([] !== $this->pruneCustomValues($p) || $changed) {
            $this->em->flush();
        }
        // the parent is saved without a journal (p.save(:validate => false)): issue.updated webhook, no e-mail
        $actor = $this->auth->user($user);
        $this->queueEvent(new IssueEvent($p, IssueEvent::UPDATED, $actor, null));
        if ($datesChanged) {
            // Issue#reschedule_following_issues of the parent, without a journal
            $this->rescheduleFollowingIssues($p, $actor, false);
        }
        if ($p->parent) {
            $this->recalculateAttributesFor((int) $p->parent->id, $user);
        }
    }

    public function totalEstimatedHours(Issue $issue): float
    {
        $total = (float) ($issue->estimatedHours ?? 0);
        // asked for every issue of a list or of a version: the subtasks of all of them come in one query
        $this->batch?->descendants($issue, 'children');
        foreach ($issue->getDescendants() as $d) {
            $total += (float) ($d->estimatedHours ?? 0);
        }

        return $total;
    }

    public function spentHours(Issue $issue): float
    {
        $load = fn (): float => (float) $this->em->createQuery('SELECT COALESCE(SUM(t.hours), 0) FROM App\Entity\TimeEntry t WHERE t.issue = :i')->setParameter('i', $issue->id)->getSingleScalarResult();

        return null === $this->memo || null === $issue->id ? $load() : $this->memo->get('issue_spent_hours:'.$issue->id, ['time_entries'], $load);
    }

    public function totalSpentHours(Issue $issue): float
    {
        $this->batch?->descendants($issue, 'children');
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
            // corrupted data (an issue that is its own ancestor) must not hang the request
            $visited = [];
            $walk = function (int $id) use (&$walk, &$counter, &$visited, $conn, $rootId): void {
                if (isset($visited[$id])) {
                    return;
                }
                $visited[$id] = true;
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
    public function createRelation(Issue $from, Issue|int|null $to, string $type, ?int $delay = null, ?User $user = null, bool $journalize = true): array
    {
        $user = $this->auth->user($user);
        $msg = fn (string $k) => $this->i18n->l('activerecord.errors.messages.'.$k);
        $issueTo = $this->i18n->l('field_issue_to');
        if (\is_int($to)) {
            $to = $this->em->find(Issue::class, $to);
        }
        if (null === $to || !$this->isVisible($to, $user)) {
            return [null, [$issueTo.' '.$msg('invalid')]];
        }
        if (!isset(IssueRelation::TYPES[$type])) {
            return [null, [$this->i18n->l('field_relation_type').' '.$msg('inclusion')]];
        }
        $exists = static function (Issue $a, Issue $b): bool {
            foreach ($a->relationsFrom as $r) {
                if ($r->issueTo->id === $b->id) {
                    return true;
                }
            }

            return false;
        };
        // validations run on the relation as entered (it is reversed before being saved only)
        $errors = [];
        if ($exists($from, $to)) {
            // validates_uniqueness_of :issue_to_id, :scope => :issue_from_id
            $errors[] = $issueTo.' '.$msg('taken');
        }
        if ($from->id === $to->id) {
            $errors[] = $issueTo.' '.$msg('invalid');
        }
        if (!$this->settings->bool('cross_project_issue_relations') && $from->project->id !== $to->project->id) {
            $errors[] = $issueTo.' '.$msg('not_same_project');
        }
        // IssueRelation#circular_dependency?
        $circular = match ($type) {
            IssueRelation::TYPE_FOLLOWS => $this->wouldReschedule($from, $to),
            IssueRelation::TYPE_PRECEDES => $this->wouldReschedule($to, $from),
            IssueRelation::TYPE_BLOCKED => $this->blocks($from, $to),
            IssueRelation::TYPE_BLOCKS => $this->blocks($to, $from),
            IssueRelation::TYPE_RELATES => $exists($to, $from),
            default => false,
        };
        if ($circular) {
            $errors[] = $msg('circular_dependency');
        }
        if ($to->isDescendantOf($from) || $from->isDescendantOf($to)) {
            $errors[] = $msg('cant_link_an_issue_with_a_descendant');
        }
        if ([] !== $errors) {
            return [null, $errors];
        }
        // IssueRelation#reverse_if_needed
        $reverse = IssueRelation::TYPES[$type][3];
        if (\in_array($type, [IssueRelation::TYPE_DUPLICATED, IssueRelation::TYPE_BLOCKED, IssueRelation::TYPE_FOLLOWS, IssueRelation::TYPE_COPIED_FROM], true)) {
            [$from, $to] = [$to, $from];
            $type = $reverse;
        }
        if (IssueRelation::TYPE_RELATES === $type && $from->id > $to->id) {
            [$from, $to] = [$to, $from];
        }
        if ($exists($from, $to)) {
            // unique index on (issue_from_id, issue_to_id): ActiveRecord::RecordNotUnique
            return [null, [$msg('taken')]];
        }
        $rel = new IssueRelation();
        $rel->issueFrom = $from;
        $rel->issueTo = $to;
        $rel->relationType = $type;
        $rel->delay = IssueRelation::TYPE_PRECEDES === $type ? ($delay ?? 0) : null;

        $this->beginUnit();
        try {
            $this->em->persist($rel);
            $from->relationsFrom->add($rel);
            $to->relationsTo->add($rel);
            $this->em->flush();
            if ($journalize) {
                // one journal per issue (IssueRelation#init_journals); the journal of the following issue also
                // holds its new dates when it is rescheduled (IssueRelation#set_issue_to_dates)
                $journals = [];
                foreach ([$from, $to] as $issue) {
                    $j = new Journal();
                    $j->issue = $issue;
                    $j->user = $user;
                    $journals[$issue->id] = $j;
                }
                $rescheduled = IssueRelation::TYPE_PRECEDES === $type && $this->setIssueToDates($rel, $user, true, $journals[$to->id]);
                $journals[$from->id]->addDetail('relation', $type, null, (string) $to->id);
                $journals[$to->id]->addDetail('relation', IssueRelation::TYPES[$type][3] ?? $type, null, (string) $from->id);
                $this->storeJournal($from, $journals[$from->id], $user, false);
                $this->storeJournal($to, $journals[$to->id], $user, $rescheduled);
            } elseif (IssueRelation::TYPE_PRECEDES === $type) {
                // a relation saved without init_journals (issue import): the following issue is rescheduled
                // without a journal
                $this->setIssueToDates($rel, $user, false);
            }
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->endUnit(false);
            throw $e;
        }
        $this->endUnit(true);

        return [$rel, []];
    }

    public function deleteRelation(IssueRelation $rel, ?User $user = null): void
    {
        $user = $this->auth->user($user);
        $this->beginUnit();
        try {
            $from = $rel->issueFrom;
            $to = $rel->issueTo;
            $type = $rel->relationType;
            $from->relationsFrom->removeElement($rel);
            $to->relationsTo->removeElement($rel);
            $this->em->remove($rel);
            $this->em->flush();
            foreach ([[$from, $type, $to], [$to, IssueRelation::TYPES[$type][3] ?? $type, $from]] as [$issue, $t, $other]) {
                $j = new Journal();
                $j->issue = $issue;
                $j->user = $user;
                $j->addDetail('relation', $t, (string) $other->id, null);
                $this->storeJournal($issue, $j, $user, false);
            }
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->endUnit(false);
            throw $e;
        }
        $this->endUnit(true);
    }

    /** Issue#blocks?: does $issue (transitively) block $other? */
    private function blocks(Issue $issue, Issue $other): bool
    {
        $all = [$issue->id => true];
        $last = [$issue];
        while ([] !== $last) {
            $current = [];
            foreach ($last as $i) {
                foreach ($i->relationsFrom as $r) {
                    if (IssueRelation::TYPE_BLOCKS === $r->relationType && !isset($all[$r->issueTo->id])) {
                        $current[$r->issueTo->id] = $r->issueTo;
                    }
                }
            }
            if (isset($current[$other->id])) {
                return true;
            }
            $all += array_fill_keys(array_keys($current), true);
            $last = array_values($current);
        }

        return false;
    }

    /** Issue#would_reschedule?: might $other be rescheduled when the dates of $issue change? */
    private function wouldReschedule(Issue $issue, Issue $other): bool
    {
        $all = [$issue->id => true];
        $last = [$issue];
        while ([] !== $last) {
            $current = [];
            foreach ($last as $i) {
                $candidates = [];
                foreach ([$i, ...$i->getAncestors()] as $source) {
                    foreach ($source->relationsFrom as $r) {
                        if (IssueRelation::TYPE_PRECEDES === $r->relationType) {
                            $candidates[] = $r->issueTo;
                        }
                    }
                }
                array_push($candidates, ...$this->leaves($i));
                foreach ($candidates as $c) {
                    if (!isset($all[$c->id])) {
                        $current[$c->id] = $c;
                    }
                }
            }
            if (isset($current[$other->id])) {
                return true;
            }
            $all += array_fill_keys(array_keys($current), true);
            $last = array_values($current);
        }

        return false;
    }

    /** @return list<Issue> descendants without children */
    private function leaves(Issue $issue): array
    {
        return array_values(array_filter($issue->getDescendants(), static fn (Issue $d) => $d->isLeaf()));
    }

    /**
     * IssueRelation#set_issue_to_dates: reschedules the following issue of a "precedes" relation.
     *
     * @return bool true when the following issue itself was changed
     */
    private function setIssueToDates(IssueRelation $rel, User $user, bool $journalize, ?Journal $into = null): bool
    {
        if (IssueRelation::TYPE_PRECEDES !== $rel->relationType) {
            return false;
        }
        $soonest = $this->successorSoonestStart($rel);
        if (null === $soonest) {
            return false;
        }

        return $this->rescheduleOn($rel->issueTo, $soonest, $user, $journalize, $into);
    }

    /**
     * Issue#reschedule_on!: moves the issue on the given date (or the next working day) keeping its working
     * duration; a parent task with derived dates is rescheduled through its subtasks. An issue that starts
     * later than the date is moved back, but not before its soonest start date.
     *
     * @param ?Journal $into journal receiving the date changes (it is saved by the caller)
     *
     * @return bool true when the issue itself was changed
     */
    private function rescheduleOn(Issue $issue, \DateTimeImmutable $date, User $user, bool $journalize, ?Journal $into = null): bool
    {
        if (!$issue->isLeaf() && $this->isDatesDerived($issue)) {
            foreach ($this->leaves($issue) as $leaf) {
                // only move a subtask that starts at the same date as the parent or before the given date
                if (null === $leaf->startDate || $issue->startDate == $leaf->startDate || $date > $leaf->startDate) {
                    $this->rescheduleOn($leaf, $date, $user, false);
                }
            }

            return false;
        }
        if (null !== $issue->startDate && $issue->startDate == $date) {
            return false;
        }
        if (null !== $issue->startDate && $issue->startDate > $date) {
            // the issue can not be moved earlier than its soonest start date
            $soonest = $this->soonestStart($issue);
            if (null !== $soonest && $soonest > $date) {
                $date = $soonest;
            }
        }
        // Issue#reschedule_on
        $duration = null !== $issue->startDate && null !== $issue->dueDate ? $this->workingDuration($this->nextWorkingDate($issue->startDate), $issue->dueDate) : 0;
        $start = $this->nextWorkingDate($date);
        $due = $this->addWorkingDays($start, $duration);
        $snapshot = $this->originalValues($issue)['snapshot'];
        // Issue#save: before_validation callbacks and validations run like for any save, the issue is left
        // untouched when it would not be valid (required field, start date before a preceding issue...)
        $memento = [$issue->assignedTo, $issue->category, $issue->fixedVersion, $issue->parent, $issue->startDate, $issue->dueDate,
            $issue->estimatedHours, $issue->doneRatio, $issue->description, $issue->priority];
        $issue->startDate = $start;
        $issue->dueDate = $due;
        $this->clearDisabledFields($issue);
        if ([] !== $this->validate($issue, $user)) {
            [$issue->assignedTo, $issue->category, $issue->fixedVersion, $issue->parent, $issue->startDate, $issue->dueDate,
                $issue->estimatedHours, $issue->doneRatio, $issue->description, $issue->priority] = $memento;

            return false;
        }
        $changes = new Journal();
        $this->addAttributeDetails($changes, $issue, $snapshot);
        if (0 === $changes->details->count()) {
            return false;
        }
        $parentWasId = isset($snapshot['parent_id']) ? (int) $snapshot['parent_id'] : null;
        $issue->updatedOn = new \DateTimeImmutable();
        $this->pruneCustomValues($issue);
        $this->em->flush();
        if ($parentWasId !== $issue->parent?->id) {
            $this->rebuildIssueTrees(array_filter([$issue->id, $parentWasId, $issue->parent?->id]));
        }
        if (null !== $into) {
            $this->addAttributeDetails($into, $issue, $snapshot);
        } elseif ($journalize) {
            $journal = new Journal();
            $journal->issue = $issue;
            $journal->user = $user;
            $this->addAttributeDetails($journal, $issue, $snapshot);
            $this->storeJournal($issue, $journal, $user);
        } else {
            $this->queueEvent(new IssueEvent($issue, IssueEvent::UPDATED, $user, null));
        }
        $this->rescheduleFollowingIssues($issue, $user, $journalize);
        foreach (array_unique(array_filter([$issue->parent?->id, $parentWasId])) as $pid) {
            $this->recalculateAttributesFor((int) $pid, $user);
        }

        return true;
    }

    /** Issue#reschedule_following_issues */
    private function rescheduleFollowingIssues(Issue $issue, User $user, bool $journalize = true): void
    {
        foreach ($issue->relationsFrom->toArray() as $rel) {
            $this->setIssueToDates($rel, $user, $journalize);
        }
    }

    /**
     * Issue#close_duplicates: when the issue is closed and Setting.close_duplicate_issues is on, its open
     * duplicates get the same status, by the same user and with the same notes.
     */
    private function closeDuplicates(Issue $issue, User $user, string $notes = '', bool $privateNotes = false, bool $journalize = true): void
    {
        if (!$this->settings->bool('close_duplicate_issues')) {
            return;
        }
        foreach ($issue->relationsTo->toArray() as $rel) {
            if (IssueRelation::TYPE_DUPLICATES !== $rel->relationType) {
                continue;
            }
            $dup = $rel->issueFrom;
            if ($dup->isClosed()) {
                continue;
            }
            $snapshot = $this->originalValues($dup)['snapshot'];
            $now = new \DateTimeImmutable();
            $dup->status = $issue->status;
            $dup->closedOn = $now;
            $dup->updatedOn = $now;
            if ($this->useStatusForDoneRatio() && null !== $dup->status->defaultDoneRatio) {
                $dup->doneRatio = $dup->status->defaultDoneRatio;
            }
            $this->pruneCustomValues($dup);
            $this->em->flush();
            $stored = null;
            if ($journalize) {
                $j = new Journal();
                $j->issue = $dup;
                $j->user = $user;
                $j->notes = $notes;
                $j->privateNotes = $privateNotes;
                $this->addAttributeDetails($j, $dup, $snapshot, ['status_id', 'done_ratio']);
                $stored = $this->storeJournal($dup, $j, $user);
            }
            if (null === $stored) {
                $this->queueEvent(new IssueEvent($dup, IssueEvent::UPDATED, $user, null));
            }
            $this->closeDuplicates($dup, $user, $notes, $privateNotes, $journalize);
            if ($dup->parent) {
                $this->recalculateAttributesFor((int) $dup->parent->id, $user);
            }
        }
    }

    // Working days (setting non_working_week_days: 6,7 by default)

    /** @return list<int> ISO day numbers */
    private function nonWorkingDays(): array
    {
        $days = array_map('intval', $this->settings->array('non_working_week_days'));

        return \count($days) < 7 ? $days : [];
    }

    /** Redmine::Utils::DateCalculation#next_working_date: the first working day on or after the date */
    public function nextWorkingDate(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $nonWorking = $this->nonWorkingDays();
        while (\in_array((int) $date->format('N'), $nonWorking, true)) {
            $date = $date->modify('+1 day');
        }

        return $date;
    }

    public function addWorkingDays(\DateTimeImmutable $date, int $days): \DateTimeImmutable
    {
        if ($days <= 0) {
            return $date;
        }
        $nonWorking = $this->nonWorkingDays();
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
     * Copies an issue (Issue#copy_from) into a new unsaved issue. Like in Redmine the parent, the status
     * (unless keep_status), the % done and the closing date are not copied; attachments, watchers, subtasks
     * and the "copied to" relation are handled by afterCopy() unless the option is false.
     *
     * @param array{attachments?: bool, subtasks?: bool, watchers?: bool, link?: bool, keep_status?: bool} $options
     */
    public function copyFrom(Issue $source, ?User $user = null, array $options = []): Issue
    {
        $user = $this->auth->user($user);
        $copy = new Issue();
        foreach (['project', 'tracker', 'subject', 'description', 'category', 'assignedTo', 'priority', 'fixedVersion', 'startDate', 'dueDate', 'estimatedHours', 'isPrivate'] as $p) {
            $copy->{$p} = $source->{$p};
        }
        if ($options['keep_status'] ?? false) {
            $copy->status = $source->status;
            $copy->doneRatio = $source->doneRatio;
        } else {
            $copy->status = $source->tracker->defaultStatus ?? $source->status;
        }
        $copy->author = $user;
        $this->pendingCustomValues[spl_object_id($copy)] = $this->customFields->values('Issue', $source->id);
        $this->customValuesChanged[spl_object_id($copy)] = true;
        $this->copyOptions[spl_object_id($copy)] = ['source' => $source->id] + $options;

        return $copy;
    }

    /** @var array<int, array<string, mixed>> */
    private array $copyOptions = [];

    /** @var array<int, true> issues whose journals are not notified (Journal#notify = false for a record being created) */
    private array $silentJournals = [];

    /** Post-save actions of a copy (Issue#after_create_from_copy): link, attachments, watchers, subtasks */
    public function afterCopy(Issue $copy, ?User $user = null): void
    {
        $user = $this->auth->user($user);
        $opts = $this->copyOptions[spl_object_id($copy)] ?? null;
        if (null === $opts) {
            return;
        }
        unset($this->copyOptions[spl_object_id($copy)]);
        $source = $this->em->find(Issue::class, $opts['source']);
        if (!$source) {
            return;
        }
        $this->beginUnit();
        try {
            if (false !== ($opts['link'] ?? true)
                && ($source->project->id === $copy->project->id || $this->settings->bool('cross_project_issue_relations'))) {
                // the "copied from" journal of the issue that was just created is not notified
                $this->silentJournals[(int) $copy->id] = true;
                try {
                    $this->createRelation($source, $copy, IssueRelation::TYPE_COPIED_TO, null, $user);
                } finally {
                    unset($this->silentJournals[(int) $copy->id]);
                }
            }
            // the attachments and the watchers were copied when the copy was saved (see performSave)
            $this->em->flush();
            if (false !== ($opts['subtasks'] ?? true) && !$source->isLeaf()) {
                $childOptions = ['subtasks' => false] + $opts;
                unset($childOptions['source'], $childOptions['saved_with_issue']);
                $copies = [(int) $source->id => $copy];
                foreach ($source->getDescendants() as $child) {
                    // do not copy self when copying an issue as a descendant of the copied issue
                    if ($child->id === $copy->id) {
                        continue;
                    }
                    // do not copy subtasks of issues that were not copied
                    $parentCopy = null !== $child->parent ? ($copies[(int) $child->parent->id] ?? null) : null;
                    if (null === $parentCopy) {
                        continue;
                    }
                    // do not copy subtasks that are not visible, to avoid disclosing private data
                    if (!$this->isVisible($child, $user)) {
                        continue;
                    }
                    $c = $this->copyFrom($child, $user, $childOptions);
                    $c->author = $copy->author;
                    if ($c->project->id !== $copy->project->id) {
                        $this->changeProject($c, $copy->project);
                    }
                    $c->parent = $parentCopy;
                    if (null === $child->fixedVersion || !$child->fixedVersion->isOpen()) {
                        $c->fixedVersion = null;
                    }
                    if (null === $child->assignedTo || !$child->assignedTo->isActive()) {
                        $c->assignedTo = null;
                    }
                    if ([] !== $this->performSave($c, $user, true)) {
                        unset($this->copyOptions[spl_object_id($c)]);
                        continue;
                    }
                    $this->afterCopy($c, $user);
                    $copies[(int) $child->id] = $c;
                }
            }
        } catch (\Throwable $e) {
            $this->endUnit(false);
            throw $e;
        }
        $this->endUnit(true);
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
        // children removed from a surviving parent (Issue#create_parent_issue_journal)
        $removedChildren = [];
        foreach ($issues as $issue) {
            if (null !== $issue->parent && !\in_array($issue->parent->id, $ids, true)) {
                $removedChildren[(int) $issue->id] = (int) $issue->parent->id;
            }
        }
        // after_destroy_commit webhooks are built from the in-memory issue: build them while it is still
        // loaded (its custom values, journals... are deleted below and the EntityManager is cleared).
        // The subtasks destroyed with their parent trigger issue.deleted too.
        $notified = [];
        foreach ($issues as $issue) {
            foreach ([$issue, ...$issue->getDescendants()] as $destroyed) {
                if (!isset($notified[$destroyed->id])) {
                    $notified[$destroyed->id] = true;
                    $this->events->dispatch(new IssueEvent($destroyed, IssueEvent::DELETED, $user, null));
                }
            }
        }
        if (!\in_array($timeEntriesAction, ['nullify', 'reassign'], true)) {
            // has_many :time_entries, dependent: :destroy: the entries destroyed with the issues trigger time_entry.deleted
            foreach ($this->em->getRepository(TimeEntry::class)->findBy(['issue' => $ids]) as $entry) {
                $this->events->dispatch(new \App\Event\ContentEvent('time_entry', 'deleted', $entry, $user));
            }
        }
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
            $conn->executeStatement('DELETE FROM reactions WHERE reactable_type = \'Journal\' AND reactable_id IN (SELECT id FROM journals WHERE journalized_type = \'Issue\' AND journalized_id IN (?))', [$ids], $p);
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
        $actor = null !== $user->id ? ($this->em->find(User::class, $user->id) ?? $user) : $user;
        foreach ($parents as $pid) {
            if (!\in_array($pid, $ids, true)) {
                $this->rebuildIssueTrees([$pid]);
                $this->recalculateAttributesFor($pid, $actor);
            }
        }
        if ($actor->isLogged() || null !== $actor->id) {
            foreach ($removedChildren as $childId => $pid) {
                $this->journalizeParentChange($childId, $pid, null, $actor);
            }
            $this->em->flush();
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
        // DateValidator: yyyy-mm-dd of an existing day (createFromFormat alone would roll 2026-13-45 over)
        if (1 !== preg_match('/\A(\d{4}-\d{2}-\d{2})( 00:00:00)?\z/', $value, $m)) {
            return null;
        }
        $value = $m[1];
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $d || $d->format('Y-m-d') !== $value ? null : $d;
    }

    /**
     * Redmine String#to_hours: "1.5", "1,5", "2h", "1:30", "2h30", "2h30m", "2 hours 30 min", "30m" => hours;
     * null when the string is not a number.
     */
    public static function parseHours(mixed $value): ?float
    {
        if (null === $value) {
            return null;
        }
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }
        $s = trim((string) $value);
        if (preg_match('/^(\d+([.,]\d+)?)h?$/', $s, $m)) {
            $s = $m[1];
        } else {
            // 2:30 => 2.5
            // (returns at once: in Ruby the result is a float string such as "2.0", which the next pattern cannot
            // match, while PHP would print "2" and read it again as 2 minutes)
            if (preg_match('/^(\d+):(\d+)$/', $s, $m)) {
                return (int) $m[1] + (int) $m[2] / 60;
            }
            // 2h30, 2h, 30m => 2.5, 2, 0.5
            if (preg_match('/^((\d+)\s*(h|hours?))?\s*((\d+)\s*(m|min)?)?$/i', $s, $m) && ('' !== ($m[1] ?? '') || '' !== ($m[4] ?? ''))) {
                return (int) ($m[2] ?? 0) + (int) ($m[5] ?? 0) / 60;
            }
        }
        $s = str_replace(',', '.', $s);

        // Kernel.Float(s, exception: false)
        return 1 === preg_match('/^[+-]?(\d+(\.\d+)?|\.\d+)([eE][+-]?\d+)?$/', $s) ? (float) $s : null;
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

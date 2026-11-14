<?php

declare(strict_types=1);

namespace App\Query\TimeEntry;

use App\Entity\CustomField;
use App\Entity\Group;
use App\Entity\Project;
use App\Entity\ProjectCustomField;
use App\Entity\Role;
use App\Entity\TimeEntry;
use App\Entity\TimeEntryActivity;
use App\Entity\TimeEntryCustomField;
use App\Entity\UserCustomField;
use App\Query\AbstractQuery;
use App\Query\QueryColumn;
use App\Query\QueryContext;
use App\Query\StatementInvalid;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Port of Redmine's TimeEntryQuery (app/models/time_entry_query.rb) on top of the generic query engine.
 */
#[Exclude]
class TimeEntryQuery extends AbstractQuery
{
    public function __construct(QueryContext $ctx, \App\Entity\Query $record, ?Project $project = null)
    {
        parent::__construct($ctx, $record, $project);
        // TimeEntryQuery#initialize
        if (null === $record->filters) {
            $record->filters = ['spent_on' => ['operator' => '*', 'values' => []]];
        }
    }

    public function queriedTable(): string
    {
        return 'time_entries';
    }

    public function queriedClass(): string
    {
        return 'TimeEntry';
    }

    public static function viewPermission(): string
    {
        return 'view_time_entries';
    }

    public function baseFromSql(): string
    {
        return 'time_entries INNER JOIN projects ON projects.id = time_entries.project_id'
            .' INNER JOIN users ON users.id = time_entries.user_id'
            .' LEFT OUTER JOIN enumerations ON enumerations.id = time_entries.activity_id'
            .' LEFT OUTER JOIN issues ON issues.id = time_entries.issue_id AND ('.$this->ctx->issueVisibleSql(null, 'issues').')';
    }

    public function baseConditionSql(): string
    {
        return '('.$this->ctx->timeEntryVisibleSql().')';
    }

    /** TimeEntryQuery#build_from_params: :from/:to shortcut filters */
    public function buildFromParams(array $params, array $defaults = []): static
    {
        parent::buildFromParams($params, $defaults);
        $from = (string) ($params['from'] ?? '');
        $to = (string) ($params['to'] ?? '');
        if ('' !== $from && '' !== $to) {
            $this->addFilter('spent_on', '><', [$from, $to]);
        } elseif ('' !== $from) {
            $this->addFilter('spent_on', '>=', [$from]);
        } elseif ('' !== $to) {
            $this->addFilter('spent_on', '<=', [$to]);
        }

        return $this;
    }

    // ------------------------------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------------------------------

    protected function initializeAvailableFilters(): void
    {
        $ctx = $this->ctx;
        $l = $ctx->l(...);
        $attr = static fn (string $key) => $l('label_attribute_of_issue', ['name' => $l($key)]);
        $this->addAvailableFilter('spent_on', 'date_past');
        if (null === $this->project) {
            $this->addAvailableFilter('project_id', 'list', fn () => $this->projectValues(), remote: false);
        }
        if (null !== $this->project && ($this->project->rgt - $this->project->lft) > 1) {
            $this->addAvailableFilter('subproject_id', 'list_subprojects', fn () => $this->subprojectValues(), remote: false);
        }
        $this->addAvailableFilter('issue_id', 'tree', null, null, 'label_issue');
        $this->addAvailableFilter('issue.tracker_id', 'list', fn () => array_map(static fn ($t) => [$t->name, (string) $t->id], $this->trackers()), $attr('field_tracker'), remote: false);
        $this->addAvailableFilter('issue.parent_id', 'tree', null, $attr('field_parent_issue'));
        $this->addAvailableFilter('issue.status_id', 'list', fn () => $this->issueStatusesValues(), $attr('field_status'), remote: false);
        $this->addAvailableFilter('issue.fixed_version_id', 'list', fn () => $this->fixedVersionValues(), $attr('field_fixed_version'), remote: false);
        if (null !== $this->project) {
            $project = $this->project;
            $this->addAvailableFilter('issue.category_id', 'list_optional', fn () => array_map(
                static fn ($c) => [$c['name'], (string) $c['id']],
                $ctx->conn()->fetchAllAssociative('SELECT id, name FROM issue_categories WHERE project_id = ? ORDER BY name', [$project->id])
            ), $attr('field_category'), remote: false);
        }
        $this->addAvailableFilter('issue.subject', 'text', null, $attr('field_subject'));
        $this->addAvailableFilter('user_id', 'list_optional', fn () => $this->authorValues(), remote: false);
        $this->addAvailableFilter('user.group', 'list_optional', fn () => $this->groupValues(), $l('label_attribute_of_user', ['name' => $l('label_group')]), remote: false);
        $this->addAvailableFilter('user.role', 'list_optional', fn () => $this->roleValues(), $l('label_attribute_of_user', ['name' => $l('field_role')]), remote: false);
        $this->addAvailableFilter('author_id', 'list_optional', fn () => $this->authorValues(), remote: false);
        $activities = null !== $this->project ? $this->activities($this->project) : $this->sharedActivities();
        $this->addAvailableFilter('activity_id', 'list', array_map(static fn (TimeEntryActivity $a) => [$a->name, (string) ($a->parent?->id ?? $a->id)], $activities));
        if (null === $this->project || ($this->project->rgt - $this->project->lft) > 1) {
            $this->addAvailableFilter('project.status', 'list', fn () => $this->projectStatusesValues(), $l('label_attribute_of_project', ['name' => $l('field_status')]), remote: false);
        }
        $this->addAvailableFilter('comments', 'text');
        $this->addAvailableFilter('hours', 'hour');

        $this->addCustomFieldsFilters($this->timeEntryCustomFields());
        $this->addAssociationsCustomFieldsFilters(['project' => ProjectCustomField::class]);
        $this->addCustomFieldsFilters($this->issueCustomFields(), 'issue');
        $this->addAssociationsCustomFieldsFilters(['user' => UserCustomField::class]);
    }

    /** @return list<TimeEntryActivity> Project#activities */
    private function activities(Project $project): array
    {
        $overridden = array_map('intval', $this->ctx->conn()->fetchFirstColumn(
            "SELECT parent_id FROM enumerations WHERE type = 'TimeEntryActivity' AND project_id = ? AND parent_id IS NOT NULL", [$project->id]
        ));
        $all = $this->ctx->em->createQuery('SELECT a FROM App\Entity\TimeEntryActivity a WHERE (a.project IS NULL OR a.project = :p) AND a.active = true ORDER BY a.position, a.id')
            ->setParameter('p', $project->id)->getResult();

        return array_values(array_filter($all, static fn (TimeEntryActivity $a) => !\in_array($a->id, $overridden, true)));
    }

    /** @return list<TimeEntryActivity> TimeEntryActivity.shared */
    private function sharedActivities(): array
    {
        return $this->ctx->em->createQuery('SELECT a FROM App\Entity\TimeEntryActivity a WHERE a.project IS NULL ORDER BY a.position, a.id')->getResult();
    }

    /** @return list<TimeEntryCustomField> */
    public function timeEntryCustomFields(): array
    {
        return $this->memo['time_entry_custom_fields'] ??= $this->ctx->customFields->fieldsOf(TimeEntryCustomField::class);
    }

    /** @return list<ProjectCustomField> */
    public function projectCustomFields(): array
    {
        return $this->memo['project_custom_fields'] ??= $this->ctx->customFields->fieldsOf(ProjectCustomField::class);
    }

    public function groupValues(): array
    {
        $groups = $this->ctx->em->createQuery('SELECT g FROM App\Entity\Group g WHERE g INSTANCE OF App\Entity\Group ORDER BY g.lastname')->getResult();

        return array_values(array_map(static fn (Group $g) => [$g->getName(), (string) $g->id], array_filter($groups, static fn ($g) => $g instanceof Group && $g::class === Group::class)));
    }

    public function roleValues(): array
    {
        $roles = $this->ctx->em->createQuery('SELECT r FROM App\Entity\Role r WHERE r.builtin = 0 ORDER BY r.position')->getResult();

        return array_map(static fn (Role $r) => [(string) $r->name, (string) $r->id], $roles);
    }

    protected function associationCustomizedClass(string $assoc): array
    {
        return match ($assoc) {
            'project' => ['projects', 'Project', 'project_id'],
            'issue' => ['issues', 'Issue', 'issue_id'],
            'user' => ['users', 'Principal', 'user_id'],
            default => parent::associationCustomizedClass($assoc),
        };
    }

    // ------------------------------------------------------------------------------------------
    // Columns
    // ------------------------------------------------------------------------------------------

    protected function buildAvailableColumns(): array
    {
        $ctx = $this->ctx;
        $l = $ctx->l(...);
        $ts = fn (string $col) => $ctx->timestampToDate($col);
        $users = $ctx->userOrderFields('users');
        $cols = [
            new QueryColumn('project', $l('field_project'), ['projects.name'], 'time_entries.project_id'),
            new QueryColumn('spent_on', $l('field_spent_on'), ['time_entries.spent_on', 'time_entries.created_on'], 'time_entries.spent_on', false, 'desc'),
            new QueryColumn('created_on', $l('field_created_on'), ['time_entries.created_on'], $ts('time_entries.created_on'), false, 'desc', kind: QueryColumn::TIMESTAMP),
            new QueryColumn('updated_on', $l('field_updated_on'), ['time_entries.updated_on'], $ts('time_entries.updated_on'), false, 'desc', kind: QueryColumn::TIMESTAMP),
            new QueryColumn('tweek', $l('label_week'), ['time_entries.tyear', 'time_entries.tweek']),
            new QueryColumn('author', $l('field_author'), $users),
            new QueryColumn('user', $l('field_user'), $users, 'time_entries.user_id'),
            new QueryColumn('activity', $l('field_activity'), ['enumerations.position'], 'time_entries.activity_id'),
            new QueryColumn('issue', $l('field_issue'), ['issues.id'], 'time_entries.issue_id'),
            new QueryColumn('issue.tracker', $l('field_tracker'), ['trackers.position'], kind: QueryColumn::ASSOCIATION, association: 'issue', attribute: 'tracker'),
            new QueryColumn('issue.parent', $l('field_parent_issue'), ['issues.root_id', 'issues.lft ASC'], null, false, 'desc', kind: QueryColumn::ASSOCIATION, association: 'issue', attribute: 'parent'),
            new QueryColumn('issue.status', $l('field_status'), ['issue_statuses.position'], kind: QueryColumn::ASSOCIATION, association: 'issue', attribute: 'status'),
            new QueryColumn('issue.category', $l('field_category'), ['issue_categories.name'], kind: QueryColumn::ASSOCIATION, association: 'issue', attribute: 'category'),
            new QueryColumn('issue.fixed_version', $l('field_fixed_version'), QueryContext::versionOrderFields(), kind: QueryColumn::ASSOCIATION, association: 'issue', attribute: 'fixed_version'),
            new QueryColumn('comments', $l('field_comments')),
            new QueryColumn('hours', $l('field_hours'), ['time_entries.hours'], null, true),
        ];
        foreach ($this->timeEntryCustomFields() as $cf) {
            if ($this->isCustomFieldVisible($cf)) {
                $cols[] = $this->customFieldColumn($cf);
            }
        }
        foreach ($this->issueCustomFields() as $cf) {
            if ($this->isCustomFieldVisible($cf)) {
                $cols[] = new QueryColumn('issue.cf_'.$cf->id, $cf->name, null, null, false, null, 'text' !== $cf->fieldFormat || '1' !== (string) $cf->getFormatOption('full_width_layout'),
                    false, QueryColumn::ASSOCIATION_CUSTOM_FIELD, $cf, 'issue');
            }
        }
        foreach ($this->projectCustomFields() as $cf) {
            if ($this->isCustomFieldVisible($cf)) {
                $cols[] = new QueryColumn('project.cf_'.$cf->id, $cf->name, null, null, false, null, true, false, QueryColumn::ASSOCIATION_CUSTOM_FIELD, $cf, 'project');
            }
        }

        return $cols;
    }

    private function customFieldColumn(CustomField $cf): QueryColumn
    {
        $alias = 'cf_'.$cf->id;
        $sortable = null;
        $group = null;
        $join = "LEFT OUTER JOIN custom_values $alias ON $alias.customized_type = 'TimeEntry' AND $alias.customized_id = time_entries.id AND $alias.custom_field_id = {$cf->id}"
            ." AND $alias.value <> '' AND $alias.id = (SELECT max({$alias}_2.id) FROM custom_values {$alias}_2 WHERE {$alias}_2.customized_type = $alias.customized_type"
            ." AND {$alias}_2.customized_id = $alias.customized_id AND {$alias}_2.custom_field_id = $alias.custom_field_id)";
        if (!$cf->isMultiple()) {
            if (\in_array($cf->fieldFormat, ['int', 'float', 'progressbar'], true)) {
                $sortable = ["CAST(CASE $alias.value WHEN '' THEN '0' ELSE $alias.value END AS decimal(30,3))"];
            } elseif ('attachment' !== $cf->fieldFormat) {
                $sortable = ["COALESCE($alias.value, '')"];
            }
            $group = match ($cf->fieldFormat) {
                'int', 'progressbar' => "CAST(CASE $alias.value WHEN '' THEN '0' ELSE $alias.value END AS decimal(30,3))",
                'date', 'list', 'bool', 'user', 'version', 'enumeration' => "COALESCE($alias.value, '')",
                default => null,
            };
        }

        return new QueryColumn($alias, $cf->name, $sortable, $group, \in_array($cf->fieldFormat, ['int', 'float'], true), null,
            'text' !== $cf->fieldFormat || '1' !== (string) $cf->getFormatOption('full_width_layout'), false, QueryColumn::CUSTOM_FIELD, $cf, joins: $join);
    }

    public function defaultColumnsNames(): array
    {
        $defaults = $this->ctx->settings->hash('time_entry_list_defaults');
        $columns = array_values(array_map(static fn ($n) => ltrim((string) $n, ':'), (array) ($defaults['column_names'] ?? ['spent_on', 'user', 'activity', 'issue', 'comments', 'hours'])));

        return null !== $this->project ? $columns : array_values(array_unique(array_merge(['project'], $columns)));
    }

    public function defaultTotalableNames(): array
    {
        $defaults = $this->ctx->settings->hash('time_entry_list_defaults');

        return array_values(array_map(static fn ($n) => ltrim((string) $n, ':'), (array) ($defaults['totalable_names'] ?? ['hours'])));
    }

    public function defaultSortCriteria(): array
    {
        return [['spent_on', 'desc']];
    }

    /** If a filter against a single issue is set, returns its id */
    public function filteredIssueId(): ?int
    {
        $v = (string) ($this->valueFor('issue_id') ?? '');

        return preg_match('/\A(\d+)\z/', $v, $m) ? (int) $m[1] : null;
    }

    protected function joinsForOrderStatement(string $orderOptions): string
    {
        $joins = [parent::joinsForOrderStatement($orderOptions)];
        if (str_contains($orderOptions, 'issue_statuses')) {
            $joins[] = 'LEFT OUTER JOIN issue_statuses ON issue_statuses.id = issues.status_id';
        }
        if (str_contains($orderOptions, 'trackers')) {
            $joins[] = 'LEFT OUTER JOIN trackers ON trackers.id = issues.tracker_id';
        }
        if (str_contains($orderOptions, 'issue_categories')) {
            $joins[] = 'LEFT OUTER JOIN issue_categories ON issue_categories.id = issues.category_id';
        }
        if (str_contains($orderOptions, 'versions')) {
            $joins[] = 'LEFT OUTER JOIN versions ON versions.id = issues.fixed_version_id';
        }

        return implode(' ', array_filter($joins));
    }

    // ------------------------------------------------------------------------------------------
    // Specific filters SQL
    // ------------------------------------------------------------------------------------------

    protected function sqlForSpecificField(string $field, string $operator, array $value): ?string
    {
        return match ($field) {
            'issue_id' => $this->sqlForIssueId($operator, $value),
            'issue.fixed_version_id' => $this->sqlForIssueFixedVersionId($operator, $value),
            'issue.parent_id' => $this->sqlForIssueParentId($operator, $value),
            'hours' => $this->sqlForField($field, $operator, array_map(static fn ($v) => (string) (AbstractQuery::toHours((string) $v) ?? $v), $value), 'time_entries', 'hours'),
            'activity_id' => $this->sqlForActivityId($operator, $value),
            'issue.tracker_id' => $this->sqlForField($field, $operator, $value, 'issues', 'tracker_id'),
            'issue.status_id' => $this->sqlForField($field, $operator, $value, 'issues', 'status_id'),
            'issue.category_id' => $this->sqlForField($field, $operator, $value, 'issues', 'category_id'),
            'issue.subject' => $this->sqlForField($field, $operator, $value, 'issues', 'subject'),
            'project.status' => $this->sqlForField($field, $operator, $value, 'projects', 'status'),
            'user.group' => $this->sqlForUserGroup($operator, $value),
            'user.role' => $this->sqlForUserRole($operator, $value),
            default => null,
        };
    }

    private function sqlForIssueId(string $operator, array $value): string
    {
        switch ($operator) {
            case '=':
                return 'time_entries.issue_id = '.(int) ($value[0] ?? 0);
            case '~':
                $row = $this->ctx->conn()->fetchAssociative('SELECT root_id, lft, rgt FROM issues WHERE id = ?', [(int) ($value[0] ?? 0)]);
                if (false === $row) {
                    return '1=0';
                }
                $ids = array_map('intval', $this->ctx->conn()->fetchFirstColumn('SELECT id FROM issues WHERE root_id = ? AND lft >= ? AND rgt <= ?', [$row['root_id'], $row['lft'], $row['rgt']]));

                return [] !== $ids ? 'time_entries.issue_id IN ('.implode(',', $ids).')' : '1=0';
            case '!*':
                return 'time_entries.issue_id IS NULL';
            case '*':
                return 'time_entries.issue_id IS NOT NULL';
        }

        return '1=1';
    }

    private function sqlForIssueFixedVersionId(string $operator, array $value): string
    {
        $versionIds = array_map('intval', $value ?: [0]);
        $ids = array_map('intval', $this->ctx->conn()->fetchFirstColumn('SELECT id FROM issues WHERE fixed_version_id IN ('.implode(',', $versionIds).')'));
        if ('=' === $operator) {
            return [] !== $ids ? 'time_entries.issue_id IN ('.implode(',', $ids).')' : '1=0';
        }

        return [] !== $ids ? 'time_entries.issue_id NOT IN ('.implode(',', $ids).')' : '1=1';
    }

    private function sqlForIssueParentId(string $operator, array $value): string
    {
        $conn = $this->ctx->conn();
        switch ($operator) {
            case '=':
                preg_match_all('/\d+/', (string) ($value[0] ?? ''), $m);
                $parents = array_values(array_unique(array_map('intval', $m[0])));
                $ids = [] !== $parents ? array_map('intval', $conn->fetchFirstColumn('SELECT id FROM issues WHERE parent_id IN ('.implode(',', $parents).')')) : [];

                return [] !== $ids ? 'time_entries.issue_id IN ('.implode(',', $ids).')' : '1=0';
            case '~':
                $row = $conn->fetchAssociative('SELECT root_id, lft, rgt FROM issues WHERE id = ?', [(int) ($value[0] ?? 0)]);
                $ids = false !== $row ? array_map('intval', $conn->fetchFirstColumn('SELECT id FROM issues WHERE root_id = ? AND lft > ? AND rgt < ?', [$row['root_id'], $row['lft'], $row['rgt']])) : [];

                return [] !== $ids ? 'time_entries.issue_id IN ('.implode(',', $ids).')' : '1=0';
        }

        return $this->sqlForField('issue.parent_id', $operator, $value, 'issues', 'parent_id');
    }

    private function sqlForActivityId(string $operator, array $value): string
    {
        $ids = implode(',', array_map('intval', $value ?: [0]));
        if ('=' === $operator) {
            return "(enumerations.id IN ($ids) OR enumerations.parent_id IN ($ids))";
        }

        return "(enumerations.id NOT IN ($ids) AND (enumerations.parent_id IS NULL OR enumerations.parent_id NOT IN ($ids)))";
    }

    private function sqlForUserGroup(string $operator, array $value): string
    {
        $conn = $this->ctx->conn();
        if ('*' === $operator || '!*' === $operator) {
            $groupIds = array_map('intval', $conn->fetchFirstColumn("SELECT id FROM users WHERE type = 'Group'"));
            $operator = '*' === $operator ? '=' : '!';
        } else {
            $groupIds = array_map('intval', $value);
        }
        $userIds = [] !== $groupIds ? array_map('strval', array_unique(array_map('intval', $conn->fetchFirstColumn('SELECT user_id FROM groups_users WHERE group_id IN ('.implode(',', $groupIds).') ORDER BY user_id')))) : [];
        sort($userIds);

        return '('.$this->sqlForField('user_id', $operator, $userIds, 'time_entries', 'user_id').')';
    }

    private function sqlForUserRole(string $operator, array $value): string
    {
        switch ($operator) {
            case '*':
            case '!*':
                $sw = '!*' === $operator ? 'NOT' : '';
                $nl = '!*' === $operator ? 'time_entries.user_id IS NULL OR' : '';

                return "($nl $sw EXISTS (SELECT 1 FROM members WHERE time_entries.project_id = members.project_id AND members.user_id = time_entries.user_id))";
            default:
                $roleCond = [] !== $value ? 'member_roles.role_id IN ('.$this->ctx->quoteList($value).')' : '1=0';
                $sw = '!' === $operator ? 'NOT' : '';
                $nl = '!' === $operator ? 'time_entries.user_id IS NULL OR' : '';

                return "($nl $sw EXISTS (SELECT 1 FROM members INNER JOIN member_roles ON members.id = member_roles.member_id"
                    ." WHERE time_entries.project_id = members.project_id AND members.user_id = time_entries.user_id AND $roleCond))";
        }
    }

    // ------------------------------------------------------------------------------------------
    // Results
    // ------------------------------------------------------------------------------------------

    /** @return list<string> results_scope order */
    private function orderOption(): array
    {
        $order = array_values(array_filter(array_merge($this->groupBySortOrder(), $this->sortClause())));
        $order[] = 'time_entries.id ASC';

        return $order;
    }

    public function entryCount(): int
    {
        return $this->count();
    }

    /** @return list<int> */
    public function entryIds(?int $offset = null, ?int $limit = null, ?array $order = null): array
    {
        $order ??= $this->orderOption();
        $joins = $this->joinsForOrderStatement(implode(',', $order));
        $rows = $this->fetch('time_entries.id AS id', $joins, null, $order, $limit, null === $limit ? null : ($offset ?? 0));

        return array_values(array_unique(array_map(static fn ($r) => (int) $r['id'], $rows)));
    }

    /** @return list<TimeEntry> */
    public function entries(?int $offset = null, ?int $limit = null, ?array $order = null): array
    {
        $ids = $this->entryIds($offset, $limit, $order);
        if ([] === $ids) {
            return [];
        }
        if (null !== $this->ctx->batch && \count($ids) > 1) {
            // users and authors cannot be lazy (see BatchLoader::principals)
            $principals = [];
            foreach ($this->ctx->conn()->fetchAllNumeric('SELECT DISTINCT user_id, author_id FROM time_entries WHERE id IN ('.implode(',', array_map('intval', $ids)).')') as [$user, $author]) {
                $principals[] = $user;
                $principals[] = $author;
            }
            $this->ctx->batch->principals($principals);
        }
        $loaded = $this->ctx->em->createQuery('SELECT te, p, u, a FROM App\Entity\TimeEntry te JOIN te.project p JOIN te.user u JOIN te.activity a WHERE te.id IN (:ids)')
            ->setParameter('ids', $ids)->getResult();
        // the list shows the issue of each entry
        $this->ctx->batch?->references(array_map(static fn ($e) => $e->issue, $loaded));
        $byId = [];
        foreach ($loaded as $e) {
            $byId[$e->id] = $e;
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }
        $this->preloadCustomValues($out);

        return $out;
    }

    /** @var array<string, array<int, array<int, mixed>>> type => id => field id => value */
    private array $customValues = [];

    /** @param list<TimeEntry> $entries */
    private function preloadCustomValues(array $entries): void
    {
        $ids = ['TimeEntry' => [], 'Issue' => [], 'Project' => []];
        foreach ($entries as $e) {
            $ids['TimeEntry'][] = (int) $e->id;
            if (null !== $e->issue) {
                $ids['Issue'][] = (int) $e->issue->id;
            }
            $ids['Project'][] = (int) $e->project->id;
        }
        foreach ($ids as $type => $list) {
            $list = array_values(array_unique($list));
            $this->customValues[$type] = ($this->customValues[$type] ?? []) + $this->ctx->customFields->valuesFor($type, $list);
        }
    }

    /** Raw value of a column for an entry (QueryColumn#value_object) */
    public function columnValue(QueryColumn $column, TimeEntry $e): mixed
    {
        if (QueryColumn::CUSTOM_FIELD === $column->kind && null !== $column->customField) {
            return $this->customValueOf('TimeEntry', (int) $e->id, $column->customField);
        }
        if (QueryColumn::ASSOCIATION_CUSTOM_FIELD === $column->kind && null !== $column->customField) {
            if ('issue' === $column->association) {
                // QueryAssociationCustomFieldColumn#value_object: nothing when the issue is not visible
                return $this->visibleIssue($e) ? $this->customValueOf('Issue', (int) $e->issue->id, $column->customField) : null;
            }

            return $this->customValueOf('Project', (int) $e->project->id, $column->customField);
        }

        return match ($column->name) {
            'project' => $e->project,
            'spent_on' => $e->spentOn,
            'created_on' => $e->createdOn,
            'updated_on' => $e->updatedOn,
            'tweek' => $e->tweek,
            'author' => $e->author,
            'user' => $e->user,
            'activity' => $e->activity,
            'issue' => $e->issue,
            // QueryAssociationColumn#value_object: nothing when the issue is not visible
            'issue.tracker' => $this->visibleIssue($e)?->tracker,
            'issue.parent' => $this->visibleIssue($e)?->parent,
            'issue.status' => $this->visibleIssue($e)?->status,
            'issue.category' => $this->visibleIssue($e)?->category,
            'issue.fixed_version' => $this->visibleIssue($e)?->fixedVersion,
            'comments' => $e->comments,
            'hours' => $e->hours,
            default => null,
        };
    }

    /** @var array<int, bool> issue id => visible by the current user */
    private array $issueVisibility = [];

    /** The issue of the entry when the current user can see it (assoc && assoc.visible?) */
    private function visibleIssue(TimeEntry $e): ?\App\Entity\Issue
    {
        $issue = $e->issue;
        if (null === $issue) {
            return null;
        }
        $this->issueVisibility[(int) $issue->id] ??= $this->ctx->issues->isVisible($issue, $this->ctx->user());

        return $this->issueVisibility[(int) $issue->id] ? $issue : null;
    }

    private function customValueOf(string $type, int $id, CustomField $cf): mixed
    {
        if (!isset($this->customValues[$type][$id])) {
            $this->customValues[$type][$id] = $this->ctx->customFields->values($type, $id);
        }

        return $this->customValues[$type][$id][$cf->id] ?? null;
    }

    /** Key identifying the group of an entry */
    public function groupKey(TimeEntry $e): string
    {
        $column = $this->groupByColumn();
        if (null === $column) {
            return '';
        }
        $v = $this->columnValue($column, $e);
        if (QueryColumn::TIMESTAMP === $column->kind && $v instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($v)->setTimezone($this->ctx->i18n->userTimezone())->format('Y-m-d');
        }

        return match (true) {
            null === $v => '',
            \is_array($v) => (string) ($v[0] ?? ''),
            \is_object($v) && property_exists($v, 'id') => (string) $v->id,
            $v instanceof \DateTimeInterface => $v->format('Y-m-d'),
            default => (string) $v,
        };
    }

    private static function normalizeGroupKey(mixed $k): string
    {
        if (null === $k) {
            return '';
        }
        $s = (string) $k;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return substr($s, 0, 10);
        }
        if (preg_match('/^-?\d+\.0+$/', $s)) {
            return (string) (int) $s;
        }

        return $s;
    }

    /** @return array<string, int>|null */
    public function resultCountByGroup(): ?array
    {
        $column = $this->groupByColumn();
        if (null === $column) {
            return null;
        }
        $st = (string) $column->groupStatement;
        $rows = $this->fetch("$st AS g, COUNT(DISTINCT time_entries.id) AS c", $this->joinsForOrderStatement($st), $st);
        $out = [];
        foreach ($rows as $r) {
            $k = self::normalizeGroupKey($r['g']);
            $out[$k] = ($out[$k] ?? 0) + (int) $r['c'];
        }

        return $out;
    }

    /** TimeEntryQuery#total_for_hours (and custom field totals) */
    public function totalFor(QueryColumn $column): float|int
    {
        return $this->totalWith($column, null)[''] ?? 0;
    }

    /** @return array<string, float|int> */
    public function totalByGroupFor(QueryColumn $column): array
    {
        $g = $this->groupByColumn();

        return null === $g ? [] : $this->totalWith($column, $g);
    }

    /** @return list<array{0: QueryColumn, 1: float|int}> */
    public function totals(): array
    {
        return array_map(fn (QueryColumn $c) => [$c, $this->totalFor($c)], $this->totalableColumns());
    }

    /** @return array<string, float|int> */
    private function totalWith(QueryColumn $column, ?QueryColumn $group): array
    {
        $groupSql = $group?->groupStatement;
        $select = null !== $groupSql ? "$groupSql AS g, " : "'' AS g, ";
        $joins = null !== $groupSql ? $this->joinsForOrderStatement($groupSql) : '';
        if ($column->isCustomField() && null !== $column->customField) {
            $cfId = (int) $column->customField->id;
            $joins .= " INNER JOIN custom_values cv_total ON cv_total.customized_type = 'TimeEntry' AND cv_total.customized_id = time_entries.id AND cv_total.custom_field_id = $cfId AND cv_total.value <> ''";
            $rows = $this->fetch($select.'SUM(CAST(cv_total.value AS decimal(30,3))) AS t', $joins, $groupSql);
            $cast = 'int' === $column->customField->fieldFormat ? static fn ($t) => (int) $t : static fn ($t) => round((float) $t, 2);
        } elseif ('hours' === $column->name) {
            $rows = $this->fetch($select.'SUM(time_entries.hours) AS t', $joins, $groupSql);
            $cast = static fn ($t) => round((float) $t, 2);
        } else {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $k = null !== $groupSql ? self::normalizeGroupKey($r['g']) : '';
            $out[$k] = ($out[$k] ?? 0) + $cast($r['t'] ?? 0);
        }
        if (null === $groupSql && [] === $out) {
            $out[''] = $cast(0);
        }

        return $out;
    }

    /**
     * Raw rows for the time report: SUM(hours) grouped by the given SQL expressions.
     *
     * @param list<string> $groupExprs
     *
     * @return list<array<string, mixed>>
     */
    public function reportRows(array $groupExprs, string $extraJoins = ''): array
    {
        $select = [];
        foreach (array_values($groupExprs) as $i => $e) {
            $select[] = "$e AS g$i";
        }
        $select[] = 'SUM(time_entries.hours) AS hours';
        try {
            return $this->fetch(implode(', ', $select), $extraJoins, implode(', ', $groupExprs));
        } catch (StatementInvalid $e) {
            throw $e;
        }
    }
}

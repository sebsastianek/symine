<?php

declare(strict_types=1);

namespace App\Query;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use App\Entity\Attachment;
use App\Entity\CustomField;
use App\Entity\Group;
use App\Entity\GroupCustomField;
use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\IssueRelation;
use App\Entity\Journal;
use App\Entity\Principal;
use App\Entity\ProjectCustomField;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\UserCustomField;
use App\Entity\Version;
use App\Entity\VersionCustomField;
use App\Service\Activity\SearchService;

/**
 * Port of Redmine's IssueQuery (app/models/issue_query.rb).
 *
 * Results: issues() returns Issue entities in query order; the values that Redmine preloads on the
 * issues (spent hours, relations, last notes, custom values, ...) are kept in this object and read with
 * columnValue($column, $issue).
 */
#[Exclude]
class IssueQuery extends AbstractQuery
{
    public const string ESTIMATED_REMAINING_HOURS_SQL = 'COALESCE(issues.estimated_hours, 0) * (100 - COALESCE(issues.done_ratio, 0)) / 100';

    /** @var array<string, array<int, mixed>> preloaded data by kind => issue id => value */
    private array $loaded = [];

    public function queriedTable(): string
    {
        return 'issues';
    }

    public function queriedClass(): string
    {
        return 'Issue';
    }

    public static function viewPermission(): string
    {
        return 'view_issues';
    }

    public function baseFromSql(): string
    {
        return 'issues INNER JOIN projects ON projects.id = issues.project_id INNER JOIN issue_statuses ON issue_statuses.id = issues.status_id';
    }

    public function baseConditionSql(): string
    {
        return $this->ctx->issueVisibleSql();
    }

    // ------------------------------------------------------------------------------------------
    // Options specific to issue queries (gantt)
    // ------------------------------------------------------------------------------------------

    public function drawRelations(): bool
    {
        $r = $this->record->getOption('draw_relations');

        return null === $r || '1' === (string) $r;
    }

    public function drawProgressLine(): bool
    {
        return '1' === (string) $this->record->getOption('draw_progress_line');
    }

    public function drawSelectedColumns(): bool
    {
        return '1' === (string) $this->record->getOption('draw_selected_columns');
    }

    public function buildFromParams(array $params, array $defaults = []): static
    {
        parent::buildFromParams($params, $defaults);
        $q = \is_array($params['query'] ?? null) ? $params['query'] : [];
        $dr = $params['draw_relations'] ?? $q['draw_relations'] ?? $this->record->getOption('draw_relations');
        $this->record->setOption('draw_relations', '0' === (string) $dr && null !== $dr ? '0' : null);
        $dp = $params['draw_progress_line'] ?? $q['draw_progress_line'] ?? $this->record->getOption('draw_progress_line');
        $this->record->setOption('draw_progress_line', '1' === (string) $dp ? '1' : null);
        // (sic) Redmine falls back on draw_progress_line here
        $ds = $params['draw_selected_columns'] ?? $q['draw_selected_columns'] ?? $this->record->getOption('draw_progress_line');
        $this->record->setOption('draw_selected_columns', '1' === (string) $ds ? '1' : null);

        return $this;
    }

    // ------------------------------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------------------------------

    protected function initializeAvailableFilters(): void
    {
        $ctx = $this->ctx;
        $user = $ctx->user();
        $this->addAvailableFilter('status_id', 'list_status', fn () => $this->issueStatusesValues());
        if (null === $this->project) {
            $this->addAvailableFilter('project_id', 'list', fn () => $this->projectValues());
        }
        $this->addAvailableFilter('tracker_id', 'list_with_history', array_map(static fn ($t) => [$t->name, (string) $t->id], $this->trackers()));
        $priorities = $ctx->em->getRepository(IssuePriority::class)->findBy([], ['position' => 'ASC']);
        $this->addAvailableFilter('priority_id', 'list_with_history', array_map(static fn ($p) => [$p->name, (string) $p->id], $priorities));
        $this->addAvailableFilter('author_id', 'list', fn () => $this->authorValues());
        $this->addAvailableFilter('author.group', 'list', fn () => $this->groupValues(), $ctx->l('label_attribute_of_author', ['name' => $ctx->l('label_group')]));
        $this->addAvailableFilter('author.role', 'list', fn () => $this->roleValues(), $ctx->l('label_attribute_of_author', ['name' => $ctx->l('field_role')]));
        $this->addAvailableFilter('assigned_to_id', 'list_optional_with_history', fn () => $this->assignedToValues());
        $this->addAvailableFilter('member_of_group', 'list_optional', fn () => $this->groupValues());
        $this->addAvailableFilter('assigned_to_role', 'list_optional', fn () => $this->roleValues());
        $this->addAvailableFilter('fixed_version_id', 'list_optional_with_history', fn () => $this->fixedVersionValues());
        $this->addAvailableFilter('fixed_version.due_date', 'date', null, $ctx->l('label_attribute_of_fixed_version', ['name' => $ctx->l('field_effective_date')]));
        $this->addAvailableFilter('fixed_version.status', 'list', array_map(fn ($s) => [$ctx->l('version_status_'.$s), $s], Version::STATUSES),
            $ctx->l('label_attribute_of_fixed_version', ['name' => $ctx->l('field_status')]));
        if (null !== $this->project) {
            $project = $this->project;
            $this->addAvailableFilter('category_id', 'list_optional_with_history', fn () => array_map(static fn ($c) => [$c->name, (string) $c->id],
                $ctx->em->getRepository(\App\Entity\IssueCategory::class)->findBy(['project' => $project], ['name' => 'ASC'])));
        }
        $this->addAvailableFilter('subject', 'text');
        $this->addAvailableFilter('description', 'text');
        $this->addAvailableFilter('notes', 'text');
        $this->addAvailableFilter('created_on', 'date_past');
        $this->addAvailableFilter('updated_on', 'date_past');
        $this->addAvailableFilter('closed_on', 'date_past');
        $this->addAvailableFilter('start_date', 'date');
        $this->addAvailableFilter('due_date', 'date');
        $this->addAvailableFilter('estimated_hours', 'hour');
        if ($ctx->auth->allowedTo('view_time_entries', $this->project, null, true)) {
            $this->addAvailableFilter('spent_time', 'hour', null, null, 'label_spent_time');
        }
        $this->addAvailableFilter('done_ratio', 'integer');
        if ($ctx->auth->allowedToGlobally('set_issues_private') || $ctx->auth->allowedToGlobally('set_own_issues_private')) {
            $this->addAvailableFilter('is_private', 'list', [[$ctx->l('general_text_yes'), '1'], [$ctx->l('general_text_no'), '0']]);
        }
        $this->addAvailableFilter('attachment', 'text', null, $ctx->l('label_attachment'));
        $this->addAvailableFilter('attachment_description', 'text', null, $ctx->l('label_attachment_description'));
        if ($user->isLogged()) {
            $this->addAvailableFilter('watcher_id', 'list', fn () => $this->watcherValues());
        }
        $this->addAvailableFilter('updated_by', 'list', fn () => $this->authorValues());
        $this->addAvailableFilter('last_updated_by', 'list', fn () => $this->authorValues());
        if (null !== $this->project && ($this->project->rgt ?? 0) - ($this->project->lft ?? 0) > 1) {
            $this->addAvailableFilter('subproject_id', 'list_subprojects', fn () => $this->subprojectValues());
        }
        if (null === $this->project || ($this->project->rgt ?? 0) - ($this->project->lft ?? 0) > 1) {
            $this->addAvailableFilter('project.status', 'list', fn () => $this->projectStatusesValues(), $ctx->l('label_attribute_of_project', ['name' => $ctx->l('field_status')]));
        }
        $this->addCustomFieldsFilters($this->issueCustomFields());
        $this->addAssociationsCustomFieldsFilters([
            'project' => ProjectCustomField::class,
            'author' => [UserCustomField::class, GroupCustomField::class],
            'assigned_to' => [UserCustomField::class, GroupCustomField::class],
            'fixed_version' => VersionCustomField::class,
        ]);
        foreach (IssueRelation::TYPES as $type => $options) {
            $this->addAvailableFilter($type, 'relation', fn () => $this->allProjectsValues(), null, $options[0]);
        }
        $this->addAvailableFilter('parent_id', 'tree', null, null, 'field_parent_issue');
        $this->addAvailableFilter('child_id', 'tree', null, null, 'label_subtask_plural');
        $this->addAvailableFilter('issue_id', 'integer', null, null, 'label_issue');
        $this->addAvailableFilter('any_searchable', 'search');
        foreach ($this->disabledCoreFields() as $field) {
            $this->deleteAvailableFilter($field);
        }
    }

    public function groupValues(): array
    {
        $groups = $this->ctx->em->createQuery('SELECT g FROM App\Entity\Group g WHERE g.status = 1 ORDER BY g.lastname')->getResult();

        return array_values(array_map(static fn (Group $g) => [$g->getName(), (string) $g->id], array_filter($groups, static fn ($g) => !$g instanceof \App\Entity\GroupBuiltin)));
    }

    public function roleValues(): array
    {
        $roles = $this->ctx->em->createQuery('SELECT r FROM App\Entity\Role r WHERE r.builtin = 0 ORDER BY r.position')->getResult();

        return array_map(static fn (Role $r) => [(string) $r->name, (string) $r->id], $roles);
    }

    protected function findFilterValues(string $field, array $missing): array
    {
        if (\in_array($field, ['assigned_to_id', 'author_id'], true)) {
            $principals = $this->ctx->em->getRepository(Principal::class)->findBy(['id' => array_map('intval', $missing)]);

            return array_map(static fn (Principal $p) => [$p->getName(), (string) $p->id], $principals);
        }

        return [];
    }

    protected function associationCustomizedClass(string $assoc): array
    {
        return match ($assoc) {
            'project' => ['projects', 'Project', 'project_id'],
            'author' => ['users', 'Principal', 'author_id'],
            'assigned_to' => ['users', 'Principal', 'assigned_to_id'],
            'fixed_version' => ['versions', 'Version', 'fixed_version_id'],
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
        $userOrder = $ctx->userOrderFields('users');
        $authorOrder = $ctx->userOrderFields('authors');
        $issueVisibleSubtasks = $ctx->issueVisibleSql(null, 'subtasks');
        $cols = [
            new QueryColumn('id', '#', ['issues.id'], null, false, 'desc', frozen: true),
            new QueryColumn('project', $l('field_project'), ['projects.name'], 'issues.project_id'),
            new QueryColumn('tracker', $l('field_tracker'), ['trackers.position'], 'issues.tracker_id'),
            new QueryColumn('parent', $l('field_parent_issue'), ['issues.root_id', 'issues.lft ASC'], null, false, 'desc'),
            new QueryColumn('parent.subject', $l('field_parent_issue_subject'), kind: QueryColumn::ASSOCIATION, association: 'parent', attribute: 'subject'),
            new QueryColumn('status', $l('field_status'), ['issue_statuses.position'], 'issues.status_id'),
            new QueryColumn('priority', $l('field_priority'), ['enumerations.position'], 'issues.priority_id', false, 'desc'),
            new QueryColumn('subject', $l('field_subject'), ['issues.subject']),
            new QueryColumn('author', $l('field_author'), $authorOrder, 'issues.author_id'),
            new QueryColumn('assigned_to', $l('field_assigned_to'), $userOrder, 'issues.assigned_to_id'),
            new QueryColumn('watcher_users', $l('label_issue_watchers'), kind: QueryColumn::WATCHER),
            new QueryColumn('updated_on', $l('field_updated_on'), ['issues.updated_on'], $ts('issues.updated_on'), false, 'desc', kind: QueryColumn::TIMESTAMP),
            new QueryColumn('category', $l('field_category'), ['issue_categories.name'], 'issues.category_id'),
            new QueryColumn('fixed_version', $l('field_fixed_version'), QueryContext::versionOrderFields(), 'issues.fixed_version_id'),
            new QueryColumn('start_date', $l('field_start_date'), ['issues.start_date'], 'issues.start_date'),
            new QueryColumn('due_date', $l('field_due_date'), ['issues.due_date'], 'issues.due_date'),
            new QueryColumn('estimated_hours', $l('field_estimated_hours'), ['issues.estimated_hours'], null, true),
            new QueryColumn('estimated_remaining_hours', $l('field_estimated_remaining_hours'), [self::ESTIMATED_REMAINING_HOURS_SQL], null, true),
            new QueryColumn('total_estimated_hours', $l('field_total_estimated_hours'), [
                "COALESCE((SELECT SUM(estimated_hours) FROM issues subtasks WHERE $issueVisibleSubtasks AND subtasks.root_id = issues.root_id AND subtasks.lft >= issues.lft AND subtasks.rgt <= issues.rgt), 0)",
            ], null, false, 'desc'),
            new QueryColumn('done_ratio', $l('field_done_ratio'), ['issues.done_ratio'], 'issues.done_ratio'),
            new QueryColumn('created_on', $l('field_created_on'), ['issues.created_on'], $ts('issues.created_on'), false, 'desc', kind: QueryColumn::TIMESTAMP),
            new QueryColumn('closed_on', $l('field_closed_on'), ['issues.closed_on'], $ts('issues.closed_on'), false, 'desc', kind: QueryColumn::TIMESTAMP),
            new QueryColumn('last_updated_by', $l('field_last_updated_by'), $ctx->userOrderFields('last_journal_user')),
            new QueryColumn('relations', $l('label_related_issues')),
            new QueryColumn('attachments', $l('label_attachment_plural')),
            new QueryColumn('description', $l('field_description'), inline: false),
            new QueryColumn('last_notes', $l('label_last_notes'), inline: false),
        ];
        foreach ($this->issueCustomFields() as $cf) {
            if ($this->isCustomFieldVisible($cf)) {
                $cols[] = $this->customFieldColumn($cf);
            }
        }
        if ($ctx->auth->allowedTo('view_time_entries', $this->project, null, true)) {
            $index = null;
            foreach ($cols as $i => $c) {
                if ('total_estimated_hours' === $c->name) {
                    $index = $i + 1;
                }
            }
            $teVisible = $ctx->timeEntryVisibleSql(null, 'time_entries', 'te_projects.id');
            $sub = "SELECT SUM(hours) FROM time_entries JOIN projects te_projects ON te_projects.id = time_entries.project_id WHERE ($teVisible) AND time_entries.issue_id = issues.id";
            $sub2 = 'SELECT SUM(hours) FROM time_entries JOIN projects te_projects ON te_projects.id = time_entries.project_id JOIN issues subtasks ON subtasks.id = time_entries.issue_id'
                ." WHERE ($teVisible) AND subtasks.root_id = issues.root_id AND subtasks.lft >= issues.lft AND subtasks.rgt <= issues.rgt";
            $new = [
                new QueryColumn('spent_hours', $l('label_spent_time'), ["COALESCE(($sub), 0)"], null, true, 'desc'),
                new QueryColumn('total_spent_hours', $l('label_total_spent_time'), ["COALESCE(($sub2), 0)"], null, false, 'desc'),
            ];
            if (null === $index) {
                $cols = array_merge($cols, $new);
            } else {
                array_splice($cols, $index, 0, $new);
            }
        }
        if ($ctx->auth->allowedToGlobally('set_issues_private') || $ctx->auth->allowedToGlobally('set_own_issues_private')) {
            $cols[] = new QueryColumn('is_private', $l('field_is_private'), ['issues.is_private'], 'issues.is_private');
        }
        $disabled = array_map(static fn ($f) => preg_replace('/_id$/', '', $f), $this->disabledCoreFields());
        if (\in_array('estimated_hours', $disabled, true)) {
            $disabled[] = 'total_estimated_hours';
            $disabled[] = 'estimated_remaining_hours';
        }

        return array_values(array_filter($cols, static fn (QueryColumn $c) => !\in_array($c->name, $disabled, true)));
    }

    protected function customFieldColumn(CustomField $cf): QueryColumn
    {
        $alias = 'cf_'.$cf->id;
        $multiple = $cf->isMultiple();
        $isRecord = \in_array($cf->fieldFormat, ['user', 'version', 'enumeration'], true);
        $sortable = null;
        $group = null;
        $join = "LEFT OUTER JOIN custom_values $alias ON $alias.customized_type = 'Issue' AND $alias.customized_id = issues.id AND $alias.custom_field_id = {$cf->id}"
            .' AND ('.$this->customFieldVisibilityCondition($cf).") AND $alias.value <> ''"
            ." AND $alias.id = (SELECT max({$alias}_2.id) FROM custom_values {$alias}_2 WHERE {$alias}_2.customized_type = $alias.customized_type"
            ." AND {$alias}_2.customized_id = $alias.customized_id AND {$alias}_2.custom_field_id = $alias.custom_field_id)";
        if (!$multiple) {
            if (\in_array($cf->fieldFormat, ['int', 'float', 'progressbar'], true)) {
                $sortable = ["CAST(CASE $alias.value WHEN '' THEN '0' ELSE $alias.value END AS decimal(30,3))"];
            } elseif ($isRecord) {
                $va = $alias.'_'.$cf->fieldFormat;
                $table = match ($cf->fieldFormat) {
                    'user' => 'users',
                    'version' => 'versions',
                    default => 'custom_field_enumerations',
                };
                $join .= " LEFT OUTER JOIN $table $va ON CAST(CASE $alias.value WHEN '' THEN '0' ELSE $alias.value END AS decimal(30,0)) = $va.id";
                $sortable = match ($cf->fieldFormat) {
                    'user' => $this->ctx->userOrderFields($va),
                    'version' => QueryContext::versionOrderFields($va),
                    default => ["$va.position"],
                };
            } elseif ('attachment' !== $cf->fieldFormat) {
                $sortable = ["COALESCE($alias.value, '')"];
            }
            $group = match ($cf->fieldFormat) {
                'int', 'progressbar' => "CAST(CASE $alias.value WHEN '' THEN '0' ELSE $alias.value END AS decimal(30,3))",
                'date', 'list', 'bool' => "COALESCE($alias.value, '')",
                'user', 'version', 'enumeration' => "COALESCE($alias.value, '')",
                default => null,
            };
        }
        $fullWidth = 'text' === $cf->fieldFormat && '1' === (string) $cf->getFormatOption('full_width_layout');

        return new QueryColumn(
            $alias, $cf->name, $sortable, $group,
            \in_array($cf->fieldFormat, ['int', 'float'], true),
            null, !$fullWidth, false, QueryColumn::CUSTOM_FIELD, $cf, joins: $join,
        );
    }

    public function defaultColumnsNames(): array
    {
        $default = array_map(static fn ($n) => ltrim((string) $n, ':'), $this->ctx->settings->array('issue_list_default_columns'));

        return null !== $this->project ? $default : array_values(array_unique(array_merge(['project'], $default)));
    }

    public function defaultTotalableNames(): array
    {
        return array_map(static fn ($n) => ltrim((string) $n, ':'), $this->ctx->settings->array('issue_list_default_totals'));
    }

    public function defaultSortCriteria(): array
    {
        return [['id', 'desc']];
    }

    protected function joinsForOrderStatement(string $orderOptions): string
    {
        $joins = [parent::joinsForOrderStatement($orderOptions)];
        if (str_contains($orderOptions, 'authors')) {
            $joins[] = 'LEFT OUTER JOIN users authors ON authors.id = issues.author_id';
        }
        if (preg_match('/\busers\./', $orderOptions)) {
            $joins[] = 'LEFT OUTER JOIN users ON users.id = issues.assigned_to_id';
        }
        if (str_contains($orderOptions, 'last_journal_user')) {
            $joins[] = 'LEFT OUTER JOIN journals ON journals.id = (SELECT MAX(journals.id) FROM journals'
                ." WHERE journals.journalized_type = 'Issue' AND journals.journalized_id = issues.id AND ".$this->ctx->journalVisibleNotesSql().')'
                .' LEFT OUTER JOIN users last_journal_user ON last_journal_user.id = journals.user_id';
        }
        if (preg_match('/\bversions\./', $orderOptions)) {
            $joins[] = 'LEFT OUTER JOIN versions ON versions.id = issues.fixed_version_id';
        }
        if (str_contains($orderOptions, 'issue_categories')) {
            $joins[] = 'LEFT OUTER JOIN issue_categories ON issue_categories.id = issues.category_id';
        }
        if (str_contains($orderOptions, 'trackers')) {
            $joins[] = 'LEFT OUTER JOIN trackers ON trackers.id = issues.tracker_id';
        }
        if (str_contains($orderOptions, 'enumerations')) {
            $joins[] = 'LEFT OUTER JOIN enumerations ON enumerations.id = issues.priority_id';
        }

        return implode(' ', array_filter($joins));
    }

    // ------------------------------------------------------------------------------------------
    // Specific filters SQL
    // ------------------------------------------------------------------------------------------

    protected function sqlForSpecificField(string $field, string $operator, array $value): ?string
    {
        if (isset(IssueRelation::TYPES[$field])) {
            return $this->sqlForRelations($field, $operator, $value);
        }

        return match ($field) {
            'notes' => $this->sqlForNotesField($field, $operator, $value),
            'updated_by' => $this->sqlForUpdatedByField($field, $operator, $value),
            'last_updated_by' => $this->sqlForLastUpdatedByField($field, $operator, $value),
            'estimated_hours' => $this->sqlForField('estimated_hours', $operator, array_map(static fn ($v) => (string) (self::toHours($v) ?? ''), $value), 'issues', 'estimated_hours'),
            'spent_time' => $this->sqlForSpentTimeField($operator, $value),
            'watcher_id' => $this->sqlForWatcherIdField($field, $operator, $value),
            'member_of_group' => $this->sqlForMemberOfGroupField($operator, $value),
            'assigned_to_role' => $this->sqlForAssignedToRoleField($operator, $value),
            'author.group' => $this->sqlForAuthorGroupField($operator, $value),
            'author.role' => $this->sqlForAuthorRoleField($operator, $value),
            'fixed_version.status' => $this->sqlForFixedVersionAttr($field, $operator, $value, 'status'),
            'fixed_version.due_date' => $this->sqlForFixedVersionAttr($field, $operator, $value, 'effective_date'),
            'is_private' => $this->sqlForIsPrivateField($operator, $value),
            'attachment' => $this->sqlForAttachmentField($operator, $value),
            'attachment_description' => $this->sqlForAttachmentDescriptionField($operator, $value),
            'parent_id' => $this->sqlForParentIdField($operator, $value),
            'child_id' => $this->sqlForChildIdField($operator, $value),
            'updated_on' => $this->sqlForUpdatedOnField($field, $operator, $value),
            'issue_id' => $this->sqlForIssueIdField($field, $operator, $value),
            'project.status' => $this->sqlForField($field, $operator, $value, 'projects', 'status'),
            'any_searchable' => $this->sqlForAnySearchableField($operator, $value),
            default => null,
        };
    }

    protected function sqlForHistory(string $field, string $operator, array $value, string $dbTable, string $dbField): string
    {
        if ('issues' !== $dbTable || [] === $value) {
            return '1=0';
        }
        $neg = str_starts_with($operator, '!') ? 'NOT' : '';
        $list = $this->ctx->quoteList(array_map('strval', $value));
        $sub = 'SELECT 1 FROM journals INNER JOIN journal_details ON journals.id = journal_details.journal_id'
            .' WHERE ('.$this->ctx->journalVisibleNotesSql().' AND journals.journalized_type = \'Issue\' AND journals.journalized_id = issues.id'
            ." AND journal_details.property = 'attr' AND journal_details.prop_key = '$dbField' AND journal_details.old_value IN ($list))";
        $ev = \in_array($operator, ['ev', '!ev'], true) ? " OR issues.$dbField IN ($list)" : '';

        return "$neg (EXISTS ($sub)$ev)";
    }

    private function sqlForNotesField(string $field, string $operator, array $value): string
    {
        $sub = "SELECT 1 FROM journals WHERE journals.journalized_type='Issue' AND journals.journalized_id=issues.id"
            .' AND ('.$this->sqlForField($field, str_starts_with($operator, '!') ? substr($operator, 1) : $operator, $value, 'journals', 'notes').')'
            .' AND ('.$this->ctx->journalVisibleNotesSql().')';

        return (str_starts_with($operator, '!') ? 'NOT EXISTS' : 'EXISTS')." ($sub)";
    }

    private function sqlForUpdatedByField(string $field, string $operator, array $value): string
    {
        $neg = '!' === $operator ? 'NOT' : '';
        $sub = "SELECT 1 FROM journals WHERE journals.journalized_type='Issue' AND journals.journalized_id=issues.id"
            .' AND ('.$this->sqlForField($field, '=', $value, 'journals', 'user_id').') AND ('.$this->ctx->journalVisibleNotesSql().')';

        return "$neg EXISTS ($sub)";
    }

    private function sqlForLastUpdatedByField(string $field, string $operator, array $value): string
    {
        $neg = '!' === $operator ? 'NOT' : '';
        $sub = "SELECT 1 FROM journals sj WHERE sj.journalized_type='Issue' AND sj.journalized_id=issues.id AND (".$this->sqlForField($field, '=', $value, 'sj', 'user_id').')'
            ." AND sj.id IN (SELECT MAX(journals.id) FROM journals WHERE journals.journalized_type='Issue' AND journals.journalized_id=issues.id"
            .' AND ('.$this->ctx->journalVisibleNotesSql().'))';

        return "$neg EXISTS ($sub)";
    }

    private function sqlForSpentTimeField(string $operator, array $value): ?string
    {
        $first = self::toHours((string) ($value[0] ?? ''));
        $second = self::toHours((string) ($value[1] ?? ''));
        $f = static fn (?float $x) => null === $x ? 'NULL' : rtrim(rtrim(\sprintf('%.6F', $x), '0'), '.');
        $op = match ($operator) {
            '=', '>=', '<=' => "$operator ".$f($first),
            '><' => 'BETWEEN '.$f($first).' AND '.$f($second),
            '*' => '> 0',
            '!*' => '= 0',
            default => null,
        };
        if (null === $op) {
            return null;
        }

        return "COALESCE((SELECT ROUND(CAST(SUM(hours) AS DECIMAL(30,3)), 2) FROM time_entries WHERE issue_id = issues.id), 0) $op";
    }

    private function sqlForWatcherIdField(string $field, string $operator, array $value): string
    {
        $user = $this->ctx->user();
        $meIds = array_merge([0, (int) $user->id], $user->isLogged() ? $user->getGroupIds() : []);
        $me = [];
        $others = [];
        foreach ($value as $id) {
            if (\in_array((int) $id, $meIds, true)) {
                $me[] = $id;
            } else {
                $others[] = $id;
            }
        }
        if ([] !== $others) {
            $sql = "SELECT issues.id FROM issues INNER JOIN watchers ON issues.id = watchers.watchable_id AND watchers.watchable_type = 'Issue'"
                .' LEFT OUTER JOIN projects ON projects.id = issues.project_id WHERE ('.$this->sqlForField($field, '=', $me, 'watchers', 'user_id').') OR ('
                .$this->ctx->auth->allowedToCondition('view_issue_watchers', 'projects.id').' AND '.$this->sqlForField($field, '=', $others, 'watchers', 'user_id').')';
        } else {
            $sql = "SELECT watchers.watchable_id FROM watchers WHERE watchers.watchable_type='Issue' AND ".$this->sqlForField($field, '=', $me, 'watchers', 'user_id');
        }

        return 'issues.id '.('=' === $operator ? 'IN' : 'NOT IN')." ($sql)";
    }

    /** @param list<int> $groupIds @return list<string> */
    private function groupAndMemberIds(array $groupIds): array
    {
        if ([] === $groupIds) {
            return [];
        }
        $userIds = array_map('intval', $this->ctx->conn()->fetchFirstColumn('SELECT DISTINCT gu.user_id FROM groups_users gu WHERE gu.group_id IN ('.implode(',', array_map('intval', $groupIds)).')'));
        $ids = array_values(array_unique(array_merge(array_map('intval', $groupIds), $userIds)));
        sort($ids);

        return array_map('strval', $ids);
    }

    /** @return list<int> Group.givable */
    private function givableGroupIds(): array
    {
        return array_map('intval', $this->ctx->conn()->fetchFirstColumn("SELECT id FROM users WHERE type = 'Group'"));
    }

    private function sqlForMemberOfGroupField(string $operator, array $value): string
    {
        if ('*' === $operator) {
            $groups = $this->givableGroupIds();
            $operator = '=';
        } elseif ('!*' === $operator) {
            $groups = $this->givableGroupIds();
            $operator = '!';
        } else {
            $groups = array_map('intval', $this->ctx->conn()->fetchFirstColumn('SELECT id FROM users WHERE id IN ('.implode(',', array_map('intval', $value ?: [0])).") AND type IN ('Group', 'GroupAnonymous', 'GroupNonMember')"));
        }

        return '('.$this->sqlForField('assigned_to_id', $operator, $this->groupAndMemberIds($groups), 'issues', 'assigned_to_id').')';
    }

    private function sqlForAssignedToRoleField(string $operator, array $value): ?string
    {
        if (\in_array($operator, ['*', '!*'], true)) {
            $sw = '!*' === $operator ? 'NOT' : '';
            $nl = '!*' === $operator ? 'issues.assigned_to_id IS NULL OR' : '';

            return "($nl $sw EXISTS (SELECT 1 FROM members WHERE issues.project_id = members.project_id AND members.user_id = issues.assigned_to_id))";
        }
        if (\in_array($operator, ['=', '!'], true)) {
            $roleCond = [] !== $value ? 'member_roles.role_id IN ('.$this->ctx->quoteList($value).')' : '1=0';
            $sw = '!' === $operator ? 'NOT' : '';
            $nl = '!' === $operator ? 'issues.assigned_to_id IS NULL OR' : '';

            return "($nl $sw EXISTS (SELECT 1 FROM members inner join member_roles on members.id = member_roles.member_id"
                ." WHERE issues.project_id = members.project_id AND members.user_id = issues.assigned_to_id AND $roleCond))";
        }

        return null;
    }

    private function sqlForAuthorGroupField(string $operator, array $value): string
    {
        $groups = [] === $value ? $this->givableGroupIds() : array_map('intval', $this->ctx->conn()->fetchFirstColumn('SELECT id FROM users WHERE id IN ('.implode(',', array_map('intval', $value)).")"));

        return '('.$this->sqlForField('author_id', $operator, $this->groupAndMemberIds($groups), 'issues', 'author_id').')';
    }

    private function sqlForAuthorRoleField(string $operator, array $value): string
    {
        $roleCond = [] !== $value ? 'member_roles.role_id IN ('.$this->ctx->quoteList($value).')' : '1=0';
        $sw = '!' === $operator ? 'NOT' : '';
        $nl = '!' === $operator ? 'issues.author_id IS NULL OR' : '';

        return "($nl $sw EXISTS (SELECT 1 FROM members inner join member_roles on members.id = member_roles.member_id"
            ." WHERE issues.project_id = members.project_id AND members.user_id = issues.author_id AND $roleCond))";
    }

    private function sqlForFixedVersionAttr(string $field, string $operator, array $value, string $attribute): string
    {
        $where = $this->sqlForField($field, $operator, $value, 'versions', $attribute);
        if (null !== $this->project) {
            $candidates = array_map(static fn (Version $v) => (int) $v->id, $this->ctx->projects->sharedVersions($this->project));
        } else {
            $candidates = array_map(static fn (Version $v) => (int) $v->id, $this->visibleVersions());
        }
        $ids = [] === $candidates ? [] : array_map('strval', $this->ctx->conn()->fetchFirstColumn('SELECT id FROM versions WHERE id IN ('.implode(',', $candidates).") AND ($where)"));
        $nl = ('status' === $attribute ? '!' === $operator : '!*' === $operator) ? 'issues.fixed_version_id IS NULL OR' : '';

        return "($nl ".$this->sqlForFieldTyped('list_optional', 'fixed_version_id', '=', $ids, 'issues', 'fixed_version_id').')';
    }

    /** sql_for_field with an explicit filter type (for fields that are not in the available filters) */
    private function sqlForFieldTyped(string $type, string $field, string $operator, array $value, string $table, string $column): string
    {
        if ('=' === $operator) {
            return [] === $value ? '1=0' : "$table.$column IN (".$this->ctx->quoteList($value).')';
        }

        return $this->sqlForField($field, $operator, $value, $table, $column);
    }

    private function sqlForIsPrivateField(string $operator, array $value): string
    {
        $op = '=' === $operator ? 'IN' : 'NOT IN';
        $va = array_values(array_unique(array_map(fn ($v) => '0' === (string) $v ? $this->ctx->quotedFalse() : $this->ctx->quotedTrue(), $value)));

        return "issues.is_private $op (".implode(',', $va).')';
    }

    private function sqlForAttachmentField(string $operator, array $value): ?string
    {
        $base = "SELECT 1 FROM attachments a WHERE a.container_type = 'Issue' AND a.container_id = issues.id";

        return match ($operator) {
            '*', '!*' => ('*' === $operator ? 'EXISTS' : 'NOT EXISTS')." ($base)",
            '~', '!~', '*~' => ('!~' === $operator ? 'NOT EXISTS' : 'EXISTS')." ($base AND (".$this->sqlContains('a.filename', $value[0] ?? '', allWords: '*~' !== $operator).'))',
            '^', '$' => "EXISTS ($base AND (".$this->sqlContains('a.filename', $value[0] ?? '', startsWith: '^' === $operator, endsWith: '$' === $operator).'))',
            default => null,
        };
    }

    private function sqlForAttachmentDescriptionField(string $operator, array $value): string
    {
        $cond = "a.description IS NOT NULL AND a.description <> ''";
        $c = match ($operator) {
            '*' => $cond,
            '!*' => "NOT ($cond)",
            '~', '!~', '*~' => ('~' === $operator ? '' : "$cond AND ").$this->sqlContains('a.description', $value[0] ?? '', match: '!~' !== $operator, allWords: '*~' !== $operator),
            '^', '$' => $this->sqlContains('a.description', $value[0] ?? '', startsWith: '^' === $operator, endsWith: '$' === $operator),
            default => '1=0',
        };

        return "EXISTS (SELECT 1 FROM attachments a WHERE a.container_type = 'Issue' AND a.container_id = issues.id AND ($c))";
    }

    /** @return list<int> */
    private static function scanIds(?string $s): array
    {
        preg_match_all('/\d+/', (string) $s, $m);

        return array_values(array_unique(array_map('intval', $m[0])));
    }

    private function sqlForParentIdField(string $operator, array $value): ?string
    {
        switch ($operator) {
            case '=':
                $ids = self::scanIds($value[0] ?? '');

                return [] !== $ids ? 'issues.parent_id IN ('.implode(',', $ids).')' : '1=0';
            case '~':
                $conds = [];
                foreach (self::scanIds($value[0] ?? '') as $id) {
                    $row = $this->ctx->conn()->fetchAssociative('SELECT root_id, lft, rgt FROM issues WHERE id = ?', [$id]);
                    if ($row && null !== $row['root_id'] && null !== $row['lft'] && null !== $row['rgt']) {
                        $conds[] = '(issues.root_id = '.(int) $row['root_id'].' AND issues.lft > '.(int) $row['lft'].' AND issues.rgt < '.(int) $row['rgt'].')';
                    }
                }

                return [] !== $conds ? '('.implode(' OR ', $conds).')' : '1=0';
            case '!*':
                return 'issues.parent_id IS NULL';
            case '*':
                return 'issues.parent_id IS NOT NULL';
        }

        return null;
    }

    private function sqlForChildIdField(string $operator, array $value): ?string
    {
        switch ($operator) {
            case '=':
                $childIds = self::scanIds($value[0] ?? '');
                $ids = [] === $childIds ? [] : array_values(array_unique(array_filter(array_map('intval', $this->ctx->conn()->fetchFirstColumn('SELECT parent_id FROM issues WHERE id IN ('.implode(',', $childIds).') AND parent_id IS NOT NULL')))));

                return [] !== $ids ? 'issues.id IN ('.implode(',', $ids).')' : '1=0';
            case '~':
                $row = $this->ctx->conn()->fetchAssociative('SELECT root_id, lft, rgt FROM issues WHERE id = ?', [(int) ($value[0] ?? 0)]);
                if ($row && null !== $row['root_id'] && null !== $row['lft'] && null !== $row['rgt']) {
                    return 'issues.root_id = '.(int) $row['root_id'].' AND issues.lft < '.(int) $row['lft'].' AND issues.rgt > '.(int) $row['rgt'];
                }

                return '1=0';
            case '!*':
                return 'issues.rgt - issues.lft = 1';
            case '*':
                return 'issues.rgt - issues.lft > 1';
        }

        return null;
    }

    private function sqlForUpdatedOnField(string $field, string $operator, array $value): string
    {
        return match ($operator) {
            '!*' => 'issues.updated_on = issues.created_on',
            '*' => 'issues.updated_on > issues.created_on',
            default => $this->sqlForField('updated_on', $operator, $value, 'issues', 'updated_on'),
        };
    }

    private function sqlForIssueIdField(string $field, string $operator, array $value): string
    {
        if ('=' === $operator) {
            preg_match_all('/\d+/', (string) ($value[0] ?? ''), $m);
            $ids = array_map('intval', $m[0]);

            return [] !== $ids ? 'issues.id IN ('.implode(',', $ids).')' : '1=0';
        }

        return $this->sqlForField('id', $operator, $value, 'issues', 'id');
    }

    public function typeFor(string $field): ?string
    {
        // sql_for_field("id", ...) for issue_id uses the issue_id filter type
        return parent::typeFor('id' === $field ? 'issue_id' : $field);
    }

    private function sqlForRelations(string $field, string $operator, array $value, bool $reverse = false): string
    {
        $options = IssueRelation::TYPES[$field];
        $relationType = $field;
        $join = 'issue_from_id';
        $target = 'issue_to_id';
        // types with a reverse type in Redmine: duplicated, blocked, follows, copied_from
        $redmineReverse = [IssueRelation::TYPE_DUPLICATED => IssueRelation::TYPE_DUPLICATES, IssueRelation::TYPE_BLOCKED => IssueRelation::TYPE_BLOCKS,
            IssueRelation::TYPE_FOLLOWS => IssueRelation::TYPE_PRECEDES, IssueRelation::TYPE_COPIED_FROM => IssueRelation::TYPE_COPIED_TO];
        if (isset($redmineReverse[$field]) || $reverse) {
            $relationType = $redmineReverse[$field] ?? $relationType;
            [$join, $target] = [$target, $join];
        }
        $rt = $this->ctx->quote($relationType);
        $sql = '';
        switch ($operator) {
            case '*':
            case '!*':
                $op = '*' === $operator ? 'IN' : 'NOT IN';
                $sql = "issues.id $op (SELECT DISTINCT issue_relations.$join FROM issue_relations WHERE issue_relations.relation_type = $rt)";
                break;
            case '=':
            case '!':
                $ids = self::scanIds($value[0] ?? '');
                if ([] === $ids) {
                    $sql = '1=0';
                    break;
                }
                $op = '=' === $operator ? 'IN' : 'NOT IN';
                $sql = "issues.id $op (SELECT DISTINCT issue_relations.$join FROM issue_relations WHERE issue_relations.relation_type = $rt AND issue_relations.$target IN (".implode(',', $ids).'))';
                break;
            case '=p':
            case '=!p':
            case '!p':
                $op = '!p' === $operator ? 'NOT IN' : 'IN';
                $comp = '=!p' === $operator ? '<>' : '=';
                $sql = "issues.id $op (SELECT DISTINCT issue_relations.$join FROM issue_relations, issues relissues WHERE issue_relations.relation_type = $rt"
                    ." AND issue_relations.$target = relissues.id AND relissues.project_id $comp ".(int) ($value[0] ?? 0).')';
                break;
            case '*o':
            case '!o':
                $op = '!o' === $operator ? 'NOT IN' : 'IN';
                $sql = "issues.id $op (SELECT DISTINCT issue_relations.$join FROM issue_relations, issues relissues WHERE issue_relations.relation_type = $rt"
                    ." AND issue_relations.$target = relissues.id AND relissues.status_id IN (SELECT id FROM issue_statuses WHERE is_closed = ".$this->ctx->quotedFalse().'))';
                break;
        }
        // symmetric relation (relates): also match the reverse direction
        if (IssueRelation::TYPE_RELATES === $field && !$reverse) {
            $sql = $sql.(\in_array($operator, ['!', '!*', '!p', '!o'], true) ? ' AND ' : ' OR ').$this->sqlForRelations($field, $operator, $value, true);
        }
        unset($options);

        return "($sql)";
    }

    /**
     * IssueQuery#sql_for_any_searchable_field: issue ids found by the search engine (Redmine::Search::Fetcher
     * on issues: subject, description, visible notes, searchable custom fields) in the selected and
     * visible (sub)projects; "~" = all words, "*~" / "!~" = any word.
     */
    private function sqlForAnySearchableField(string $operator, array $value): string
    {
        $search = $this->ctx->search;
        $tokens = SearchService::tokens((string) ($value[0] ?? ''));
        $ids = [];
        if (null !== $search && [] !== $tokens) {
            $user = $this->ctx->user();
            // search results only from the selected and visible (sub-)projects
            $projects = null;
            $statement = null;
            if (null !== $this->project) {
                $statement = $this->projectStatement();
            } elseif ($this->hasFilter('project_id')) {
                $values = $this->valuesFor('project_id') ?? [];
                $values = match ($values[0] ?? null) {
                    'mine' => array_map('strval', array_keys($this->ctx->auth->membershipsOf($user))),
                    'bookmarks' => array_map('strval', $this->bookmarkedProjectIds()),
                    default => $values,
                };
                $statement = $this->sqlForField('project_id', (string) $this->operatorFor('project_id'), $values, 'projects', 'id');
            }
            if (null !== $statement) {
                // Project.allowed_to(:view_issues).where(...)
                $projectIds = array_map('intval', $this->ctx->conn()->fetchFirstColumn('SELECT projects.id FROM projects WHERE ('
                    .$this->ctx->auth->allowedToCondition('view_issues', 'projects.id', $user).') AND ('.$statement.')'));
                $projects = [] === $projectIds ? [] : $this->ctx->em->getRepository(\App\Entity\Project::class)->findBy(['id' => $projectIds]);
            }
            $options = [
                'all_words' => '~' === $operator,
                'open_issues' => $this->hasFilter('status_id') && 'o' === $this->operatorFor('status_id'),
                'attachments' => '0',
            ];
            $ids = array_map(static fn (array $r) => (int) $r[1], $search->resultIds($tokens, $user, ['issues'], $projects, $options));
        }
        if ([] !== $ids) {
            return 'issues.id '.('!~' === $operator ? 'NOT ' : '').'IN ('.implode(',', $ids).')';
        }

        return '!~' === $operator ? '1=1' : '1=0';
    }

    // ------------------------------------------------------------------------------------------
    // Execution
    // ------------------------------------------------------------------------------------------

    public function issueCount(): int
    {
        return $this->count();
    }

    /** @return list<string> ORDER BY used for issues/issue_ids */
    private function orderOption(?array $order = null): array
    {
        $order = array_values(array_filter(array_merge($this->groupBySortOrder(), $order ?? $this->sortClause())));
        if (!\in_array('issues.id ASC', $order, true) && !\in_array('issues.id DESC', $order, true)) {
            $order[] = 'issues.id DESC';
        }

        return $order;
    }

    /**
     * Issue ids in query order.
     *
     * @return list<int>
     */
    public function issueIds(?int $offset = null, ?int $limit = null, ?string $extraWhere = null, ?array $order = null): array
    {
        $order = $this->orderOption($order);
        $orderSql = implode(',', $order);
        $joins = $this->joinsForOrderStatement($orderSql);
        // ORDER BY expressions must be selected for DISTINCT on some databases: use GROUP BY-free plain select
        $rows = $this->fetch('issues.id AS id', $joins, null, $order, $limit, null === $limit ? null : ($offset ?? 0), $extraWhere);

        return array_values(array_unique(array_map(static fn ($r) => (int) $r['id'], $rows)));
    }

    /**
     * Issues in query order, with column data preloaded.
     *
     * @return list<Issue>
     */
    public function issues(?int $offset = null, ?int $limit = null, ?string $extraWhere = null, ?array $order = null): array
    {
        $ids = $this->issueIds($offset, $limit, $extraWhere, $order);
        $issues = $this->loadIssues($ids);
        $this->preload($issues);

        return $issues;
    }

    /** @param list<int> $ids @return list<Issue> */
    public function loadIssues(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        // authors and assignees cannot be lazy (see BatchLoader::principals): without this, each of them is one
        // or two queries while the issues are hydrated
        $this->ctx->batch?->issuePrincipals($ids);
        $found = $this->ctx->em->createQuery(
            'SELECT i, p, t, s, pr FROM App\Entity\Issue i JOIN i.project p JOIN i.tracker t JOIN i.status s JOIN i.priority pr WHERE i.id IN (:ids)'
        )->setParameter('ids', $ids)->getResult();
        // lists, calendars and charts ask every issue whether it has subtasks
        $this->ctx->batch?->collections($found, 'children');
        $byId = [];
        foreach ($found as $i) {
            $byId[$i->id] = $i;
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /** @param list<Issue> $issues */
    public function preload(array $issues, ?array $columns = null): void
    {
        if ([] === $issues) {
            return;
        }
        $names = $columns ?? array_map(static fn (QueryColumn $c) => $c->name, $this->columns());
        $ids = array_map(static fn (Issue $i) => (int) $i->id, $issues);
        $has = static fn (string $n) => \in_array($n, $names, true);
        if ($has('spent_hours')) {
            $this->loadSpentHours($ids);
        }
        if ($has('total_spent_hours')) {
            $this->loadTotalSpentHours($issues);
        }
        if ($has('last_updated_by')) {
            $this->loadLastJournal($ids, 'last_updated_by', false);
        }
        if ($has('last_notes')) {
            $this->loadLastJournal($ids, 'last_notes', true);
        }
        if ($has('relations')) {
            $this->loadVisibleRelations($ids);
        }
        if ($has('total_estimated_hours')) {
            $this->loadTotalEstimatedHours($issues);
        }
        $cfNames = array_filter($names, static fn ($n) => str_starts_with($n, 'cf_'));
        if ([] !== $cfNames) {
            $this->loaded['custom_values'] = $this->ctx->customFields->valuesFor('Issue', $ids) + ($this->loaded['custom_values'] ?? []);
        }
        if ($has('watcher_users')) {
            $this->loadWatchers($ids);
        }
        if ($has('attachments')) {
            $atts = $this->ctx->em->createQuery("SELECT a FROM App\Entity\Attachment a WHERE a.containerType = 'Issue' AND a.containerId IN (:ids) ORDER BY a.createdOn, a.id")
                ->setParameter('ids', $ids)->getResult();
            foreach ($ids as $id) {
                $this->loaded['attachments'][$id] = [];
            }
            foreach ($atts as $a) {
                $this->loaded['attachments'][$a->containerId][] = $a;
            }
        }
    }

    /** @param list<int> $ids */
    public function loadSpentHours(array $ids): void
    {
        $cond = $this->ctx->timeEntryVisibleSql();
        $rows = $this->ctx->conn()->fetchAllAssociative('SELECT time_entries.issue_id AS id, SUM(time_entries.hours) AS h FROM time_entries INNER JOIN projects ON projects.id = time_entries.project_id'
            ." WHERE ($cond) AND time_entries.issue_id IN (".implode(',', $ids ?: [0]).') GROUP BY time_entries.issue_id');
        foreach ($ids as $id) {
            $this->loaded['spent_hours'][$id] = 0.0;
        }
        foreach ($rows as $r) {
            $this->loaded['spent_hours'][(int) $r['id']] = (float) $r['h'];
        }
    }

    /** @param list<Issue> $issues */
    public function loadTotalSpentHours(array $issues): void
    {
        $ids = array_map(static fn (Issue $i) => (int) $i->id, $issues);
        $cond = $this->ctx->timeEntryVisibleSql();
        $rows = $this->ctx->conn()->fetchAllAssociative('SELECT parent.id AS id, SUM(time_entries.hours) AS h FROM time_entries INNER JOIN projects ON projects.id = time_entries.project_id'
            .' INNER JOIN issues ON issues.id = time_entries.issue_id JOIN issues parent ON parent.root_id = issues.root_id AND parent.lft <= issues.lft AND parent.rgt >= issues.rgt'
            ." WHERE ($cond) AND parent.id IN (".implode(',', $ids ?: [0]).') GROUP BY parent.id');
        foreach ($ids as $id) {
            $this->loaded['total_spent_hours'][$id] = 0.0;
        }
        foreach ($rows as $r) {
            $this->loaded['total_spent_hours'][(int) $r['id']] = (float) $r['h'];
        }
    }

    /** @param list<Issue> $issues */
    public function loadTotalEstimatedHours(array $issues): void
    {
        $visible = $this->ctx->issueVisibleSql(null, 'subtasks');
        foreach ($issues as $issue) {
            if ($issue->isLeaf()) {
                $this->loaded['total_estimated_hours'][$issue->id] = $issue->estimatedHours;
                continue;
            }
            $sum = $this->ctx->conn()->fetchOne("SELECT SUM(subtasks.estimated_hours) FROM issues subtasks INNER JOIN projects ON projects.id = subtasks.project_id WHERE $visible"
                .' AND subtasks.root_id = ? AND subtasks.lft >= ? AND subtasks.rgt <= ?', [$issue->rootId, $issue->lft, $issue->rgt]);
            $this->loaded['total_estimated_hours'][$issue->id] = null === $sum || false === $sum ? null : (float) $sum;
        }
    }

    /** @param list<int> $ids */
    private function loadLastJournal(array $ids, string $key, bool $withNotes): void
    {
        $cond = $this->ctx->journalVisibleNotesSql();
        $rows = $this->ctx->conn()->fetchAllAssociative('SELECT MAX(journals.id) AS id FROM journals INNER JOIN issues ON issues.id = journals.journalized_id INNER JOIN projects ON projects.id = issues.project_id'
            ." WHERE journals.journalized_type = 'Issue' AND journals.journalized_id IN (".implode(',', $ids ?: [0]).") AND $cond"
            .($withNotes ? " AND journals.notes IS NOT NULL AND journals.notes <> ''" : '').' GROUP BY journals.journalized_id');
        $jids = array_map(static fn ($r) => (int) $r['id'], $rows);
        foreach ($ids as $id) {
            $this->loaded[$key][$id] = $withNotes ? '' : null;
        }
        if ([] === $jids) {
            return;
        }
        $journals = $this->ctx->em->createQuery('SELECT j, u FROM App\Entity\Journal j JOIN j.user u WHERE j.id IN (:ids)')->setParameter('ids', $jids)->getResult();
        foreach ($journals as $j) {
            $this->loaded[$key][$j->issue->id] = $withNotes ? (string) $j->notes : $j->user;
        }
    }

    /** @param list<int> $ids Issue.load_visible_relations */
    public function loadVisibleRelations(array $ids): void
    {
        $visibleTo = $this->ctx->issueVisibleSql(null, 'other');
        $idList = implode(',', $ids ?: [0]);
        $relIds = array_map('intval', $this->ctx->conn()->fetchFirstColumn(
            "SELECT r.id FROM issue_relations r JOIN issues other ON other.id = r.issue_to_id JOIN projects ON projects.id = other.project_id WHERE $visibleTo AND r.issue_from_id IN ($idList)"
            ." UNION SELECT r.id FROM issue_relations r JOIN issues other ON other.id = r.issue_from_id JOIN projects ON projects.id = other.project_id WHERE $visibleTo AND r.issue_to_id IN ($idList)"
        ));
        foreach ($ids as $id) {
            $this->loaded['relations'][$id] = [];
        }
        if ([] === $relIds) {
            return;
        }
        $relations = $this->ctx->em->createQuery('SELECT r, f, t FROM App\Entity\IssueRelation r JOIN r.issueFrom f JOIN r.issueTo t WHERE r.id IN (:ids)')->setParameter('ids', $relIds)->getResult();
        usort($relations, static fn (IssueRelation $a, IssueRelation $b) => [IssueRelation::TYPES[$a->relationType][2] ?? 0, $a->id] <=> [IssueRelation::TYPES[$b->relationType][2] ?? 0, $b->id]);
        foreach ($relations as $r) {
            if (\in_array($r->issueFrom->id, $ids, true)) {
                $this->loaded['relations'][$r->issueFrom->id][] = $r;
            }
            if (\in_array($r->issueTo->id, $ids, true)) {
                $this->loaded['relations'][$r->issueTo->id][] = $r;
            }
        }
    }

    /** @param list<int> $ids */
    private function loadWatchers(array $ids): void
    {
        $watchers = $this->ctx->em->createQuery("SELECT w, u FROM App\Entity\Watcher w JOIN w.user u WHERE w.watchableType = 'Issue' AND w.watchableId IN (:ids)")
            ->setParameter('ids', $ids)->getResult();
        foreach ($ids as $id) {
            $this->loaded['watcher_users'][$id] = [];
        }
        foreach ($watchers as $w) {
            $this->loaded['watcher_users'][$w->watchableId][] = $w->user;
        }
    }

    /** Preloaded value (spent_hours, relations, ...) for an issue */
    public function loadedValue(string $kind, Issue $issue): mixed
    {
        return $this->loaded[$kind][$issue->id] ?? null;
    }

    // ------------------------------------------------------------------------------------------
    // Column values
    // ------------------------------------------------------------------------------------------

    /**
     * QueryColumn#value_object: raw value of a column for an issue. Objects (Project, Principal, ...),
     * scalars, DateTimeImmutable, arrays (relations, attachments, watchers, multiple custom values), or
     * for custom field columns ['custom_field' => CustomField, 'value' => string|list<string>|null].
     */
    public function columnValue(QueryColumn $column, Issue $issue): mixed
    {
        $auth = $this->ctx->auth;
        switch ($column->kind) {
            case QueryColumn::CUSTOM_FIELD:
                $cf = $column->customField;
                if (!$cf->isVisibleBy($issue->project, $this->ctx->user(), fn ($p) => $auth->rolesForProject($p))) {
                    return null;
                }
                if (!isset($this->loaded['custom_values'][$issue->id])) {
                    $this->loaded['custom_values'] = $this->ctx->customFields->valuesFor('Issue', [(int) $issue->id]) + ($this->loaded['custom_values'] ?? []);
                    $this->loaded['custom_values'][$issue->id] ??= [];
                }
                if (!\array_key_exists($cf->id, $this->loaded['custom_values'][$issue->id])) {
                    return null;
                }

                return ['custom_field' => $cf, 'value' => $this->loaded['custom_values'][$issue->id][$cf->id]];
            case QueryColumn::ASSOCIATION:
                if ('parent' === $column->association && null !== $issue->parent && $this->ctx->issues->isVisible($issue->parent)) {
                    return $issue->parent->subject;
                }

                return null;
            case QueryColumn::WATCHER:
                if (!$auth->allowedTo('view_issue_watchers', $issue->project)) {
                    return null;
                }
                if (!isset($this->loaded['watcher_users'][$issue->id])) {
                    $this->loadWatchers([(int) $issue->id]);
                }

                return $this->loaded['watcher_users'][$issue->id];
        }

        return match ($column->name) {
            'id' => $issue->id,
            'project' => $issue->project,
            'tracker' => $issue->tracker,
            'parent' => $issue->parent,
            'status' => $issue->status,
            'priority' => $issue->priority,
            'subject' => $issue->subject,
            'author' => $issue->author,
            'assigned_to' => $issue->assignedTo,
            'updated_on' => $issue->updatedOn,
            'category' => $issue->category,
            'fixed_version' => $issue->fixedVersion,
            'start_date' => $issue->startDate,
            'due_date' => $issue->dueDate,
            'estimated_hours' => $issue->estimatedHours,
            'estimated_remaining_hours' => (float) ($issue->estimatedHours ?? 0) * (100 - $issue->doneRatio) / 100,
            'total_estimated_hours' => $this->lazy('total_estimated_hours', $issue, fn () => $this->loadTotalEstimatedHours([$issue])),
            'done_ratio' => $issue->doneRatio,
            'created_on' => $issue->createdOn,
            'closed_on' => $issue->closedOn,
            'last_updated_by' => $this->lazy('last_updated_by', $issue, fn () => $this->loadLastJournal([(int) $issue->id], 'last_updated_by', false)),
            'relations' => $this->lazy('relations', $issue, fn () => $this->loadVisibleRelations([(int) $issue->id])) ?? [],
            'attachments' => $this->lazy('attachments', $issue, fn () => $this->preload([$issue], ['attachments'])) ?? [],
            'description' => $issue->description,
            'last_notes' => $this->lazy('last_notes', $issue, fn () => $this->loadLastJournal([(int) $issue->id], 'last_notes', true)),
            'spent_hours' => $this->lazy('spent_hours', $issue, fn () => $this->loadSpentHours([(int) $issue->id])) ?? 0.0,
            'total_spent_hours' => $this->lazy('total_spent_hours', $issue, fn () => $this->loadTotalSpentHours([$issue])) ?? 0.0,
            'is_private' => $issue->isPrivate,
            default => null,
        };
    }

    private function lazy(string $kind, Issue $issue, \Closure $loader): mixed
    {
        if (!\array_key_exists($kind, $this->loaded) || !\array_key_exists($issue->id, $this->loaded[$kind])) {
            $loader();
        }

        return $this->loaded[$kind][$issue->id] ?? null;
    }

    /** Key identifying the group of an issue (same as the keys of resultCountByGroup) */
    public function groupKey(Issue $issue): string
    {
        $column = $this->groupByColumn();
        if (null === $column) {
            return '';
        }
        $value = $this->groupValue($issue);
        if (\is_array($value) && isset($value['custom_field'])) {
            $v = $value['value'];

            return null === $v ? '' : (string) (\is_array($v) ? ($v[0] ?? '') : $v);
        }

        return self::keyOf($value);
    }

    /** QueryColumn#group_value */
    public function groupValue(Issue $issue): mixed
    {
        $column = $this->groupByColumn();
        if (null === $column) {
            return null;
        }
        $value = $this->columnValue($column, $issue);
        if (QueryColumn::TIMESTAMP === $column->kind && $value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone($this->ctx->i18n->userTimezone())->setTime(0, 0);
        }

        return $value;
    }

    public static function keyOf(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            \is_bool($value) => $value ? '1' : '0',
            \is_object($value) && property_exists($value, 'id') => (string) $value->id,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            \is_float($value) => rtrim(rtrim(\sprintf('%.6F', $value), '0'), '.'),
            default => (string) $value,
        };
    }

    private static function normalizeGroupKey(mixed $k, ?QueryColumn $column): string
    {
        if (null === $k) {
            return '';
        }
        $s = (string) $k;
        if (null !== $column && QueryColumn::CUSTOM_FIELD === $column->kind && \in_array($column->customField?->fieldFormat, ['int', 'progressbar'], true) && is_numeric($s)) {
            return (string) (int) $s;
        }
        if (null !== $column && 'is_private' === $column->name) {
            return \in_array($s, ['1', 't', 'true'], true) ? '1' : '0';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return substr($s, 0, 10);
        }

        return $s;
    }

    /** @return array<string, int>|null Query#result_count_by_group */
    public function resultCountByGroup(): ?array
    {
        $column = $this->groupByColumn();
        if (null === $column) {
            return null;
        }
        $st = (string) $column->groupStatement;
        $rows = $this->fetch("$st AS g, COUNT(DISTINCT issues.id) AS c", $this->joinsForOrderStatement($st), $st);
        $out = [];
        foreach ($rows as $r) {
            $k = self::normalizeGroupKey($r['g'], $column);
            $out[$k] = ($out[$k] ?? 0) + (int) $r['c'];
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // Totals
    // ------------------------------------------------------------------------------------------

    public function totalFor(QueryColumn|string $column): float|int|null
    {
        $column = \is_string($column) ? $this->findTotalable($column) : $column;

        return null === $column ? null : $this->totalWith($column, null)[''] ?? 0;
    }

    /** @return array<string, float|int>|null */
    public function totalByGroupFor(QueryColumn|string $column): ?array
    {
        $column = \is_string($column) ? $this->findTotalable($column) : $column;
        $g = $this->groupByColumn();
        if (null === $g || null === $column) {
            return null;
        }

        return $this->totalWith($column, $g);
    }

    private function findTotalable(string $name): ?QueryColumn
    {
        foreach ($this->availableTotalableColumns() as $c) {
            if ($c->name === $name) {
                return $c;
            }
        }

        return null;
    }

    /** @return list<array{0: QueryColumn, 1: float|int|null}> */
    public function totals(): array
    {
        return array_map(fn (QueryColumn $c) => [$c, $this->totalFor($c)], $this->totalableColumns());
    }

    /** @return array<string, float|int> group key ('' when not grouped) => total */
    private function totalWith(QueryColumn $column, ?QueryColumn $group): array
    {
        $groupSql = $group?->groupStatement;
        $select = null !== $groupSql ? "$groupSql AS g, " : "'' AS g, ";
        $joins = null !== $groupSql ? $this->joinsForOrderStatement($groupSql) : '';
        if ($column->isCustomField() && null !== $column->customField) {
            $cfId = (int) $column->customField->id;
            $sumExpr = 'SUM(CAST(cv_total.value AS decimal(30,3)))';
            $joins .= " INNER JOIN custom_values cv_total ON cv_total.customized_type = 'Issue' AND cv_total.customized_id = issues.id AND cv_total.custom_field_id = $cfId AND cv_total.value <> ''";
            $rows = $this->fetch($select.$sumExpr.' AS t', $joins, $groupSql);
            $cast = 'int' === $column->customField->fieldFormat ? static fn ($t) => (int) $t : static fn ($t) => round((float) $t, 2);
        } else {
            switch ($column->name) {
                case 'estimated_hours':
                    $rows = $this->fetch($select.'SUM(issues.estimated_hours) AS t', $joins, $groupSql);
                    break;
                case 'estimated_remaining_hours':
                    $rows = $this->fetch($select.'SUM('.self::ESTIMATED_REMAINING_HOURS_SQL.') AS t', $joins, $groupSql);
                    break;
                case 'spent_hours':
                    $te = $this->ctx->timeEntryVisibleSql(null, 'time_entries', 'te_projects.id');
                    $joins .= ' INNER JOIN time_entries ON time_entries.issue_id = issues.id INNER JOIN projects te_projects ON te_projects.id = time_entries.project_id';
                    $rows = $this->fetch($select.'SUM(time_entries.hours) AS t', $joins, $groupSql, [], null, null, $te);
                    break;
                default:
                    return [];
            }
            $cast = static fn ($t) => round((float) $t, 2);
        }
        $out = [];
        foreach ($rows as $r) {
            $k = null !== $groupSql ? self::normalizeGroupKey($r['g'], $group) : '';
            $out[$k] = ($out[$k] ?? 0) + $cast($r['t'] ?? 0);
        }
        if (null === $groupSql && [] === $out) {
            $out[''] = $cast(0);
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // Journals & versions (used by atom feeds, gantt, calendar)
    // ------------------------------------------------------------------------------------------

    /** @return list<Version> IssueQuery#versions */
    public function versions(?string $extraCondition = null): array
    {
        $visible = $this->ctx->auth->allowedToCondition('view_issues', 'projects.id');
        $sql = "SELECT versions.id FROM versions INNER JOIN projects ON projects.id = versions.project_id WHERE $visible";
        if (null !== ($ps = $this->projectStatement())) {
            $sql .= " AND ($ps)";
        }
        if (null !== $extraCondition) {
            $sql .= " AND ($extraCondition)";
        }
        $ids = array_map('intval', $this->ctx->conn()->fetchFirstColumn($sql));

        return [] === $ids ? [] : $this->ctx->em->createQuery('SELECT v, p FROM App\Entity\Version v JOIN v.project p WHERE v.id IN (:ids)')->setParameter('ids', $ids)->getResult();
    }

    /**
     * Journals of the issues matched by the query (IssueQuery#journals)
     *
     * @return list<Journal>
     */
    public function journals(?int $limit = null, ?int $offset = null): array
    {
        $notes = $this->ctx->journalVisibleNotesSql();
        $rows = $this->fetch('journals.id AS id', "INNER JOIN journals ON journals.journalized_type = 'Issue' AND journals.journalized_id = issues.id", null,
            ['journals.created_on DESC', 'journals.id DESC'], $limit, $offset, $notes);
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        if ([] === $ids) {
            return [];
        }
        $journals = $this->ctx->em->createQuery('SELECT j, u, i FROM App\Entity\Journal j JOIN j.user u JOIN j.issue i WHERE j.id IN (:ids)')->setParameter('ids', $ids)->getResult();
        $pos = array_flip($ids);
        usort($journals, static fn (Journal $a, Journal $b) => $pos[$a->id] <=> $pos[$b->id]);

        return $journals;
    }

    public function attachmentFor(int $id): ?Attachment
    {
        return $this->ctx->em->find(Attachment::class, $id);
    }

    public function userFor(int $id): ?User
    {
        return $this->ctx->em->find(User::class, $id);
    }
}

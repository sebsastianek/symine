<?php

declare(strict_types=1);

namespace App\Query;

use App\Entity\CustomField;
use App\Entity\Project;
use App\Entity\ProjectCustomField;
use App\Entity\Query as QueryRecord;
use App\Security\Authorization;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Port of Redmine's ProjectQuery (app/models/project_query.rb) on top of the generic query engine.
 *
 * The public projects list (/projects) is rendered by App\Service\ProjectQueryService; this engine is
 * used for saved project queries (QueriesController), the CSV export (/projects.csv) and, through
 * ProjectAdminQuery, the administration projects list.
 */
#[Exclude]
class ProjectQuery extends AbstractQuery
{
    public function __construct(QueryContext $ctx, QueryRecord $record, ?Project $project = null)
    {
        parent::__construct($ctx, $record, $project);
        // ProjectQuery#initialize
        if (null === $record->filters) {
            $record->filters = ['status' => ['operator' => '=', 'values' => ['1']]];
        }
    }

    public function queriedTable(): string
    {
        return 'projects';
    }

    public function queriedClass(): string
    {
        return 'Project';
    }

    public static function viewPermission(): string
    {
        return 'search_project';
    }

    public function baseFromSql(): string
    {
        return 'projects';
    }

    /** Project.visible */
    public function baseConditionSql(): string
    {
        return '('.Authorization::in('projects.id', $this->ctx->auth->visibleProjectIds()).')';
    }

    public function availableDisplayTypes(): array
    {
        return ['board', 'list'];
    }

    /** Query#display_type: options[:display_type] || default_display_type (Setting.project_list_display_type) */
    public function displayType(): string
    {
        $t = $this->record->getOption('display_type');
        if (\is_string($t) && '' !== $t) {
            return $t;
        }
        $default = $this->ctx->settings->string('project_list_display_type');

        return \in_array($default, $this->availableDisplayTypes(), true) ? $default : $this->availableDisplayTypes()[0];
    }

    public function defaultColumnsNames(): array
    {
        $defaults = $this->ctx->settings->hash('project_list_defaults');
        $names = array_values(array_map(static fn ($n) => ltrim((string) $n, ':'), (array) ($defaults['column_names'] ?? [])));

        return [] === $names ? ['name', 'identifier', 'short_description'] : $names;
    }

    // ------------------------------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------------------------------

    protected function initializeAvailableFilters(): void
    {
        $l = $this->ctx->l(...);
        $this->addAvailableFilter('status', 'list', fn () => $this->projectStatusesValues());
        $this->addAvailableFilter('id', 'list', fn () => $this->projectValues(), null, 'field_project');
        $this->addAvailableFilter('name', 'text');
        $this->addAvailableFilter('description', 'text');
        $this->addAvailableFilter('parent_id', 'list_subprojects', fn () => $this->projectValues(), null, 'field_parent');
        $this->addAvailableFilter('is_public', 'list', [[$l('general_text_yes'), '1'], [$l('general_text_no'), '0']]);
        $this->addAvailableFilter('created_on', 'date_past');
        $this->addAvailableFilter('updated_on', 'date_past');
        $this->addCustomFieldsFilters($this->projectCustomFields());
    }

    /** @return list<ProjectCustomField> ProjectCustomField.sorted */
    public function projectCustomFields(): array
    {
        return $this->memo['project_custom_fields'] ??= $this->ctx->customFields->fieldsOf(ProjectCustomField::class);
    }

    /** "mine" / "bookmarks" values of the id and parent_id filters (Query#statement) */
    protected function substitutesProjectValues(string $field): bool
    {
        return \in_array($field, ['id', 'parent_id'], true);
    }

    protected function sqlForSpecificField(string $field, string $operator, array $value): ?string
    {
        if ('is_public' === $field && \in_array($operator, ['=', '!'], true)) {
            $bools = array_map(fn ($v) => '1' === (string) $v ? $this->ctx->quotedTrue() : $this->ctx->quotedFalse(), array_values(array_filter($value, static fn ($v) => '' !== (string) $v)));
            if ([] === $bools) {
                return '=' === $operator ? '1=0' : '1=1';
            }

            return 'projects.is_public '.('!' === $operator ? 'NOT ' : '').'IN ('.implode(',', array_unique($bools)).')';
        }

        return null;
    }

    // ------------------------------------------------------------------------------------------
    // Columns
    // ------------------------------------------------------------------------------------------

    protected function buildAvailableColumns(): array
    {
        $l = $this->ctx->l(...);
        $cols = [
            new QueryColumn('name', $l('field_name'), ['projects.name']),
            new QueryColumn('status', $l('field_status'), ['projects.status']),
            new QueryColumn('short_description', $l('field_description'), ['projects.description']),
            new QueryColumn('homepage', $l('field_homepage'), ['projects.homepage']),
            new QueryColumn('identifier', $l('field_identifier'), ['projects.identifier']),
            new QueryColumn('parent_id', $l('field_parent'), ['projects.lft ASC'], null, false, 'desc'),
            new QueryColumn('is_public', $l('field_is_public'), ['projects.is_public'], 'projects.is_public'),
            new QueryColumn('created_on', $l('field_created_on'), ['projects.created_on'], null, false, 'desc'),
            new QueryColumn('updated_on', $l('field_updated_on'), ['projects.updated_on'], null, false, 'desc'),
            new QueryColumn('last_activity_date', $l('field_last_activity_date')),
        ];
        foreach ($this->projectCustomFields() as $cf) {
            if ($this->isCustomFieldVisible($cf)) {
                $cols[] = $this->customFieldColumn($cf);
            }
        }

        return $cols;
    }

    /** QueryCustomFieldColumn for a project custom field (order / group statements of the field format) */
    protected function customFieldColumn(CustomField $cf): QueryColumn
    {
        $alias = 'cf_'.$cf->id;
        $sortable = null;
        $group = null;
        $join = "LEFT OUTER JOIN custom_values $alias ON $alias.customized_type = 'Project' AND $alias.customized_id = projects.id AND $alias.custom_field_id = {$cf->id}"
            .' AND ('.$this->customFieldVisibilityCondition($cf).") AND $alias.value <> ''"
            ." AND $alias.id = (SELECT max({$alias}_2.id) FROM custom_values {$alias}_2 WHERE {$alias}_2.customized_type = $alias.customized_type"
            ." AND {$alias}_2.customized_id = $alias.customized_id AND {$alias}_2.custom_field_id = $alias.custom_field_id)";
        if (!$cf->isMultiple()) {
            if (\in_array($cf->fieldFormat, ['int', 'float', 'progressbar'], true)) {
                $sortable = ["CAST(CASE $alias.value WHEN '' THEN '0' ELSE $alias.value END AS decimal(30,3))"];
            } elseif (\in_array($cf->fieldFormat, ['user', 'version', 'enumeration'], true)) {
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
                'date', 'list', 'bool', 'user', 'version', 'enumeration' => "COALESCE($alias.value, '')",
                default => null,
            };
        }
        $fullWidth = 'text' === $cf->fieldFormat && '1' === (string) $cf->getFormatOption('full_width_layout');

        return new QueryColumn($alias, $cf->name, $sortable, $group, \in_array($cf->fieldFormat, ['int', 'float'], true), null,
            !$fullWidth, false, QueryColumn::CUSTOM_FIELD, $cf, joins: $join);
    }

    // ------------------------------------------------------------------------------------------
    // Results
    // ------------------------------------------------------------------------------------------

    /** @return list<string> results_scope order: group_by_sort_order, sort_clause, projects.lft ASC */
    private function orderOption(): array
    {
        $order = array_values(array_filter(array_merge($this->groupBySortOrder(), $this->sortClause())));
        $order[] = 'projects.lft ASC';

        return $order;
    }

    public function resultCount(): int
    {
        return $this->count();
    }

    /** @return list<int> */
    public function resultIds(?int $offset = null, ?int $limit = null, ?array $order = null): array
    {
        $order ??= $this->orderOption();
        $joins = $this->joinsForOrderStatement(implode(',', $order));
        $rows = $this->fetch('projects.id AS id', $joins, null, $order, $limit, null === $limit ? null : ($offset ?? 0));

        return array_values(array_unique(array_map(static fn ($r) => (int) $r['id'], $rows)));
    }

    /**
     * ProjectQuery#results_scope (+ custom values / last activity date preloading).
     *
     * @return list<Project>
     */
    public function results(?int $offset = null, ?int $limit = null, ?array $order = null): array
    {
        $ids = $this->resultIds($offset, $limit, $order);
        if ([] === $ids) {
            return [];
        }
        $byId = [];
        foreach ($this->ctx->em->createQuery('SELECT p FROM App\Entity\Project p WHERE p.id IN (:ids)')->setParameter('ids', $ids)->getResult() as $p) {
            $byId[$p->id] = $p;
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }
        if ($this->hasCustomFieldColumn() || $this->isGroupedByCustomField()) {
            $this->customValues += $this->ctx->customFields->valuesFor('Project', $ids);
        }

        return $out;
    }

    private function isGroupedByCustomField(): bool
    {
        return QueryColumn::CUSTOM_FIELD === $this->groupByColumn()?->kind;
    }

    /** @var array<int, array<int, mixed>> project id => custom field id => value */
    private array $customValues = [];
    /** @var array<int, \DateTimeImmutable>|null */
    private ?array $lastActivityDates = null;

    /** @param array<int, \DateTimeImmutable> $dates Project.load_last_activity_date */
    public function setLastActivityDates(array $dates): void
    {
        $this->lastActivityDates = $dates;
    }

    /** QueryColumn#value_object */
    public function columnValue(QueryColumn $column, Project $p): mixed
    {
        if (QueryColumn::CUSTOM_FIELD === $column->kind && null !== $column->customField) {
            if (!isset($this->customValues[$p->id])) {
                $this->customValues[$p->id] = $this->ctx->customFields->values('Project', $p->id);
            }

            return $this->customValues[$p->id][$column->customField->id] ?? null;
        }

        return match ($column->name) {
            'name' => $p->name,
            'status' => $p->status,
            'short_description' => self::shortDescription($p->description),
            'homepage' => $p->homepage,
            'identifier' => $p->identifier,
            'parent_id' => $p->parent?->id,
            'is_public' => $p->isPublic,
            'created_on' => $p->createdOn,
            'updated_on' => $p->updatedOn,
            'last_activity_date' => $this->lastActivityDates[$p->id] ?? null,
            default => null,
        };
    }

    /** Project#short_description */
    public static function shortDescription(?string $description, int $length = 255): ?string
    {
        if (null === $description) {
            return null;
        }
        if (preg_match('/^(.{'.$length.'}[^\n\r]*).*$/msu', $description, $m)) {
            $description = (string) preg_replace('/^(.{'.$length.'}[^\n\r]*).*$/msu', '$1...', $description);
        }

        return trim($description);
    }

    /** Value of the group of a project (QueryColumn#group_value) */
    public function groupValue(Project $p): mixed
    {
        $column = $this->groupByColumn();

        return null === $column ? null : $this->columnValue($column, $p);
    }

    /**
     * Query#result_count_by_group, keyed by the normalized group value (see groupKey()).
     *
     * @return array<string, int>|null
     */
    public function resultCountByGroup(): ?array
    {
        $column = $this->groupByColumn();
        if (null === $column || null === $column->groupStatement) {
            return null;
        }
        $joins = $this->joinsForOrderStatement((string) $column->groupStatement.','.$column->name);
        $rows = $this->fetch($column->groupStatement.' AS g, COUNT(DISTINCT projects.id) AS c', $joins, $column->groupStatement);
        $out = [];
        foreach ($rows as $r) {
            $key = 'is_public' === $column->name ? self::groupKey((bool) $r['g']) : self::groupKey($r['g']);
            $out[$key] = ($out[$key] ?? 0) + (int) $r['c'];
        }

        return $out;
    }

    public static function groupKey(mixed $value): string
    {
        return match (true) {
            true === $value => '1',
            false === $value => '0',
            null === $value => '',
            \is_array($value) => (string) ($value[0] ?? ''),
            default => (string) $value,
        };
    }
}

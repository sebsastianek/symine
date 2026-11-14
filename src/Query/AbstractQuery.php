<?php

declare(strict_types=1);

namespace App\Query;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use App\Entity\CustomField;
use App\Entity\Group;
use App\Entity\GroupBuiltin;
use App\Entity\IssueCustomField;
use App\Entity\IssueStatus;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\ProjectCustomField;
use App\Entity\Query as QueryRecord;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\Version;

/**
 * Port of Redmine's Query model (app/models/query.rb): filters, operators, columns, sorting,
 * grouping, totals and SQL generation. Subclasses (IssueQuery, ...) define the queried table,
 * the available filters/columns and the base scope.
 *
 * The persisted part (filters, column_names, sort_criteria, group_by, options) lives in the wrapped
 * App\Entity\Query record, using Redmine's serialized structure, so saving a query is just persisting
 * the record.
 */
#[Exclude]
abstract class AbstractQuery
{
    public const array OPERATORS = [
        '=' => 'label_equals',
        '!' => 'label_not_equals',
        'o' => 'label_open_issues',
        'c' => 'label_closed_issues',
        '!*' => 'label_none',
        '*' => 'label_any',
        '>=' => 'label_greater_or_equal',
        '<=' => 'label_less_or_equal',
        '><' => 'label_between',
        '<t+' => 'label_in_less_than',
        '>t+' => 'label_in_more_than',
        '><t+' => 'label_in_the_next_days',
        't+' => 'label_in',
        'nd' => 'label_tomorrow',
        't' => 'label_today',
        'ld' => 'label_yesterday',
        'nw' => 'label_next_week',
        'w' => 'label_this_week',
        'lw' => 'label_last_week',
        'l2w' => ['label_last_n_weeks', ['count' => 2]],
        'nm' => 'label_next_month',
        'm' => 'label_this_month',
        'lm' => 'label_last_month',
        'y' => 'label_this_year',
        '>t-' => 'label_less_than_ago',
        '<t-' => 'label_more_than_ago',
        '><t-' => 'label_in_the_past_days',
        't-' => 'label_ago',
        '~' => 'label_contains',
        '!~' => 'label_not_contains',
        '*~' => 'label_contains_any_of',
        '^' => 'label_starts_with',
        '$' => 'label_ends_with',
        '=p' => 'label_any_issues_in_project',
        '=!p' => 'label_any_issues_not_in_project',
        '!p' => 'label_no_issues_in_project',
        '*o' => 'label_any_open_issues',
        '!o' => 'label_no_open_issues',
        'ev' => 'label_has_been',
        '!ev' => 'label_has_never_been',
        'cf' => 'label_changed_from',
    ];

    public const array OPERATORS_BY_FILTER_TYPE = [
        'list' => ['=', '!'],
        'list_with_history' => ['=', '!', 'ev', '!ev', 'cf'],
        'list_status' => ['o', '=', '!', 'ev', '!ev', 'cf', 'c', '*'],
        'list_optional' => ['=', '!', '!*', '*'],
        'list_optional_with_history' => ['=', '!', 'ev', '!ev', 'cf', '!*', '*'],
        'list_subprojects' => ['*', '!*', '=', '!'],
        'date' => ['=', '>=', '<=', '><', '<t+', '>t+', '><t+', 't+', 'nd', 't', 'ld', 'nw', 'w', 'lw', 'l2w', 'nm', 'm', 'lm', 'y', '>t-', '<t-', '><t-', 't-', '!*', '*'],
        'date_past' => ['=', '>=', '<=', '><', '>t-', '<t-', '><t-', 't-', 't', 'ld', 'w', 'lw', 'l2w', 'm', 'lm', 'y', '!*', '*'],
        'string' => ['~', '*~', '=', '!~', '!', '^', '$', '!*', '*'],
        'text' => ['~', '*~', '!~', '^', '$', '!*', '*'],
        'search' => ['~', '*~', '!~'],
        'integer' => ['=', '>=', '<=', '><', '!*', '*'],
        'float' => ['=', '>=', '<=', '><', '!*', '*'],
        'hour' => ['=', '>=', '<=', '><', '!*', '*'],
        'relation' => ['=', '!', '=p', '=!p', '!p', '*o', '!o', '!*', '*'],
        'tree' => ['=', '~', '!*', '*'],
    ];

    /** Operators that do not need a value */
    public const array VALUELESS_OPERATORS = ['o', 'c', '!*', '*', 'nd', 't', 'ld', 'nw', 'w', 'lw', 'l2w', 'nm', 'm', 'lm', 'y', '*o', '!o'];

    public const array TOKENIZED_FILTER_TYPES = ['string', 'text', 'search'];
    public const array TOKENIZED_FILTER_OPERATORS = ['~', '*~', '!~', '^', '$'];

    /** @var array<string, QueryFilter>|null */
    protected ?array $availableFilters = null;
    /** @var list<QueryColumn>|null */
    protected ?array $availableColumns = null;
    /** @var list<string> */
    public array $errors = [];
    private ?bool $validated = null;
    /** @var array<string, mixed> memoized values */
    protected array $memo = [];

    public function __construct(
        protected readonly QueryContext $ctx,
        public readonly QueryRecord $record,
        public ?Project $project = null,
    ) {
    }

    // ------------------------------------------------------------------------------------------
    // To be implemented by subclasses
    // ------------------------------------------------------------------------------------------

    /** Table of the queried objects, e.g. "issues" */
    abstract public function queriedTable(): string;

    /** Class name used in polymorphic columns (custom_values.customized_type, ...), e.g. "Issue" */
    abstract public function queriedClass(): string;

    /** Permission required to view the queries (Query.view_permission) */
    abstract public static function viewPermission(): string;

    abstract protected function initializeAvailableFilters(): void;

    /** @return list<QueryColumn> */
    abstract protected function buildAvailableColumns(): array;

    /** SQL "FROM ... JOIN ..." part of the base scope (without FROM keyword) */
    abstract public function baseFromSql(): string;

    /** SQL condition always applied (visibility) */
    abstract public function baseConditionSql(): string;

    /** @return list<string> */
    public function defaultColumnsNames(): array
    {
        return [];
    }

    /** @return list<string> */
    public function defaultTotalableNames(): array
    {
        return [];
    }

    /** @return list<array{0: string, 1: string}> */
    public function defaultSortCriteria(): array
    {
        return [];
    }

    /** @return list<string> */
    public function availableDisplayTypes(): array
    {
        return ['list'];
    }

    /** Additional joins required for the given ORDER BY / GROUP BY SQL */
    protected function joinsForOrderStatement(string $orderOptions): string
    {
        $joins = [];
        if (preg_match_all('/cf_(\d+)/', $orderOptions, $m)) {
            foreach (array_unique($m[0]) as $name) {
                $column = $this->findColumn($name);
                if ($column?->joins) {
                    $joins[] = $column->joins;
                }
            }
        }

        return implode(' ', $joins);
    }

    // ------------------------------------------------------------------------------------------
    // Record attributes (Redmine structure)
    // ------------------------------------------------------------------------------------------

    public function isNew(): bool
    {
        return null === $this->record->id;
    }

    public function name(): string
    {
        return $this->record->name;
    }

    public function type(): string
    {
        return $this->record::typeName();
    }

    /** @return array<string, array{operator: string, values: list<string>}> */
    public function getFilters(): array
    {
        $filters = \is_array($this->record->filters) ? $this->record->filters : [];
        $out = [];
        foreach ($filters as $field => $f) {
            if (!\is_array($f)) {
                continue;
            }
            $op = (string) ($f['operator'] ?? $f[':operator'] ?? '');
            $values = $f['values'] ?? $f[':values'] ?? [''];
            $out[(string) $field] = ['operator' => $op, 'values' => array_map(static fn ($v) => (string) $v, \is_array($values) ? array_values($values) : [$values])];
        }

        return $out;
    }

    /** @param array<string, array{operator: string, values: list<string>}> $filters */
    public function setFilters(array $filters): void
    {
        $this->record->filters = $filters;
        $this->validated = null;
    }

    public function hasFilter(string $field): bool
    {
        return isset($this->getFilters()[$field]);
    }

    public function operatorFor(string $field): ?string
    {
        return $this->getFilters()[$field]['operator'] ?? null;
    }

    /** @return list<string>|null */
    public function valuesFor(string $field): ?array
    {
        return $this->getFilters()[$field]['values'] ?? null;
    }

    public function valueFor(string $field, int $index = 0): ?string
    {
        return ($this->valuesFor($field) ?? [])[$index] ?? null;
    }

    public function typeFor(string $field): ?string
    {
        return ($this->availableFilters()[$field] ?? null)?->type;
    }

    public function labelFor(string $field): string
    {
        return ($this->availableFilters()[$field] ?? null)?->name ?? $field;
    }

    /** @param list<string>|null $values */
    public function addFilter(string $field, ?string $operator, ?array $values = null): void
    {
        if (!isset($this->availableFilters()[$field])) {
            return;
        }
        $filters = $this->getFilters();
        $filters[$field] = ['operator' => (string) $operator, 'values' => null === $values ? [''] : array_map('strval', array_values($values))];
        $this->setFilters($filters);
    }

    public function addShortFilter(string $field, mixed $expression): void
    {
        if (null === $expression || \is_array($expression) || !isset($this->availableFilters()[$field])) {
            return;
        }
        $expression = (string) $expression;
        $type = $this->availableFilters()[$field]->type;
        $operators = self::OPERATORS_BY_FILTER_TYPE[$type] ?? [];
        sort($operators, \SORT_STRING);
        foreach (array_reverse($operators) as $op) {
            if (str_starts_with($expression, $op)) {
                $values = substr($expression, \strlen($op));
                $this->addFilter($field, $op, '' !== $values ? explode('|', $values) : ['']);

                return;
            }
        }
        $this->addFilter($field, '=', explode('|', $expression));
    }

    /**
     * @param list<string>              $fields
     * @param array<string, string>     $operators
     * @param array<string, list<string>|string>|null $values
     */
    public function addFilters(array $fields, array $operators, ?array $values): void
    {
        if ([] === $fields || [] === $operators) {
            return;
        }
        foreach ($fields as $field) {
            $field = (string) $field;
            $v = $values[$field] ?? null;
            if (null !== $v && !\is_array($v)) {
                continue; // values must be an array
            }
            $this->addFilter($field, isset($operators[$field]) ? (string) $operators[$field] : null, $v);
        }
    }

    /** @return list<string>|null */
    public function getColumnNames(): ?array
    {
        $c = $this->record->columnNames;
        if (!\is_array($c) || [] === $c) {
            return null;
        }

        return array_values(array_map(static fn ($n) => ltrim((string) $n, ':'), $c));
    }

    /** @param list<string>|null $names */
    public function setColumnNames(?array $names): void
    {
        if (null !== $names) {
            $names = array_values(array_filter(array_map('strval', $names), static fn ($n) => '' !== $n));
            if (false !== ($i = array_search('all_inline', $names, true))) {
                unset($names[$i]);
                $inline = array_map(static fn (QueryColumn $c) => $c->name, $this->availableInlineColumns());
                $names = array_values(array_unique(array_merge($inline, array_values($names))));
            }
            $names = array_values(array_unique($names));
            if ($names === $this->defaultColumnsNames()) {
                $names = null;
            }
        }
        $this->record->columnNames = $names;
    }

    public function hasDefaultColumns(): bool
    {
        return null === $this->getColumnNames();
    }

    public function getGroupBy(): ?string
    {
        return $this->record->groupBy;
    }

    public function setGroupBy(?string $groupBy): void
    {
        $this->record->groupBy = '' === $groupBy ? null : $groupBy;
    }

    /** @return list<string> */
    public function totalableNames(): array
    {
        $names = $this->record->getOption('totalable_names');
        if (null === $names) {
            return $this->defaultTotalableNames();
        }

        return array_values(array_map(static fn ($n) => ltrim((string) $n, ':'), (array) $names));
    }

    /** @param list<string>|null $names */
    public function setTotalableNames(?array $names): void
    {
        if (null !== $names) {
            $names = array_values(array_filter(array_map('strval', $names), static fn ($n) => '' !== $n));
        }
        $this->record->setOption('totalable_names', $names);
    }

    public function sortCriteria(): SortCriteria
    {
        $c = $this->record->sortCriteria;
        if (!\is_array($c) || [] === $c) {
            $c = $this->defaultSortCriteria();
        }

        return new SortCriteria($c);
    }

    public function setSortCriteria(string|array|null $arg): void
    {
        $this->record->sortCriteria = (new SortCriteria($arg))->toArray();
    }

    public function sortCriteriaKey(int $i): ?string
    {
        return $this->sortCriteria()->keyAt($i);
    }

    public function sortCriteriaOrder(int $i): ?string
    {
        return $this->sortCriteria()->orderAt($i);
    }

    public function displayType(): string
    {
        $t = $this->record->getOption('display_type');

        return \is_string($t) && '' !== $t ? $t : $this->availableDisplayTypes()[0];
    }

    public function setDisplayType(?string $type): void
    {
        if (null === $type || !\in_array($type, $this->availableDisplayTypes(), true)) {
            $type = $this->availableDisplayTypes()[0];
        }
        $this->record->setOption('display_type', $type);
    }

    public function isPrivate(): bool
    {
        return QueryRecord::VISIBILITY_PRIVATE === (int) $this->record->visibility;
    }

    public function isPublic(): bool
    {
        return !$this->isPrivate();
    }

    public function isGlobal(): bool
    {
        return null === $this->record->project;
    }

    // ------------------------------------------------------------------------------------------
    // Building from params
    // ------------------------------------------------------------------------------------------

    /**
     * Query#build_from_params: params use Redmine's names (f[], op[], v[], c[], t[], sort, group_by,
     * display_type, query[...]) or short filters (status_id=o, assigned_to_id=me, ...).
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $defaults
     */
    public function buildFromParams(array $params, array $defaults = []): static
    {
        $fields = $params['fields'] ?? $params['f'] ?? null;
        if (null !== $fields && '' !== $fields) {
            $this->setFilters([]);
            $this->addFilters(
                array_values((array) $fields),
                (array) ($params['operators'] ?? $params['op'] ?? []),
                isset($params['values']) || isset($params['v']) ? (array) ($params['values'] ?? $params['v']) : null,
            );
        } else {
            foreach (array_keys($this->availableFilters()) as $field) {
                $key = str_replace('.', '_', $field); // PHP converts dots in query keys
                $value = $params[$field] ?? $params[$key] ?? null;
                if (null !== $value) {
                    $this->addShortFilter($field, $value);
                }
            }
        }
        $q = \is_array($params['query'] ?? null) ? $params['query'] : $defaults;
        if (\array_key_exists('group_by', $params) || isset($q['group_by'])) {
            $this->setGroupBy((string) ($params['group_by'] ?? $q['group_by'] ?? ''));
        }
        if (isset($params['c']) || isset($q['column_names'])) {
            $this->setColumnNames(array_values((array) ($params['c'] ?? $q['column_names'])));
        }
        if (isset($params['t']) || isset($q['totalable_names'])) {
            $this->setTotalableNames(array_values((array) ($params['t'] ?? $q['totalable_names'])));
        }
        if (isset($params['sort']) || isset($q['sort_criteria'])) {
            $sort = $params['sort'] ?? $q['sort_criteria'];
            $this->setSortCriteria(\is_array($sort) ? array_values($sort) : (string) $sort);
        }
        $this->setDisplayType(isset($params['display_type']) ? (string) $params['display_type'] : (isset($q['display_type']) ? (string) $q['display_type'] : $this->record->getOption('display_type')));

        return $this;
    }

    /** Query#as_params */
    public function asParams(): array
    {
        if (!$this->isNew()) {
            return ['query_id' => $this->record->id];
        }
        $params = [];
        foreach ($this->getFilters() as $field => $f) {
            $params['f'][] = $field;
            $params['op'][$field] = $f['operator'];
            $params['v'][$field] = $f['values'];
        }
        $params['c'] = $this->getColumnNames();
        if ($this->getGroupBy()) {
            $params['group_by'] = $this->getGroupBy();
        }
        if ([] !== $this->totalableNames()) {
            $params['t'] = $this->totalableNames();
        }
        $params['sort'] = $this->sortCriteria()->toParam();
        $params['set_filter'] = 1;

        return $params;
    }

    // ------------------------------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------------------------------

    public function isValid(bool $forSave = false): bool
    {
        $this->errors = [];
        if ($forSave) {
            if ('' === trim($this->record->name)) {
                $this->errors[] = $this->ctx->l('field_name').' '.$this->ctx->l('activerecord.errors.messages.blank');
            } elseif (mb_strlen($this->record->name) > 255) {
                $this->errors[] = $this->ctx->l('field_name').' '.$this->ctx->l('activerecord.errors.messages.too_long', ['count' => 255]);
            }
            if (null !== $this->record->description && mb_strlen($this->record->description) > 255) {
                $this->errors[] = $this->ctx->l('field_description').' '.$this->ctx->l('activerecord.errors.messages.too_long', ['count' => 255]);
            }
            if (QueryRecord::VISIBILITY_ROLES === (int) $this->record->visibility && 0 === $this->record->roles->count()) {
                $this->errors[] = $this->ctx->l('label_role_plural').' '.$this->ctx->l('activerecord.errors.messages.blank');
            }
        }
        $this->validateFilters();
        $this->validated = [] === $this->errors;

        return $this->validated;
    }

    private function validateFilters(): void
    {
        foreach ($this->getFilters() as $field => $f) {
            $values = $f['values'];
            $op = $f['operator'];
            $type = $this->typeFor($field);
            $present = static fn ($v) => null !== $v && '' !== trim((string) $v);
            switch ($type) {
                case 'integer':
                    foreach ($values as $v) {
                        if ($present($v) && !preg_match('/\A[+-]?\d+(,[+-]?\d+)*\z/', $v)) {
                            $this->addFilterError($field, 'invalid');
                            break;
                        }
                    }
                    break;
                case 'float':
                    foreach ($values as $v) {
                        if ($present($v) && !preg_match('/\A[+-]?\d+(\.\d*)?\z/', $v)) {
                            $this->addFilterError($field, 'invalid');
                            break;
                        }
                    }
                    break;
                case 'hour':
                    if ('><' === $op) {
                        foreach ($values as $v) {
                            if (!$present($v) || null === self::toHours($v)) {
                                $this->addFilterError($field, 'invalid');
                                break;
                            }
                        }
                    } elseif (\in_array($op, ['=', '>=', '<='], true)) {
                        foreach ($values as $v) {
                            if ($present($v) && null === self::toHours($v)) {
                                $this->addFilterError($field, 'invalid');
                                break;
                            }
                        }
                    }
                    break;
                case 'date':
                case 'date_past':
                    if (\in_array($op, ['=', '>=', '<=', '><'], true)) {
                        foreach ($values as $v) {
                            if ($present($v) && (!preg_match('/\A\d{4}-\d{2}-\d{2}(T\d{2}((:)?\d{2}){0,2}(Z|\d{2}:?\d{2})?)?\z/', $v) || null === $this->parseDate($v))) {
                                $this->addFilterError($field, 'invalid');
                                break;
                            }
                        }
                    } elseif (\in_array($op, ['>t-', '<t-', 't-', '>t+', '<t+', 't+', '><t+', '><t-'], true)) {
                        foreach ($values as $v) {
                            if ($present($v) && !preg_match('/^\d+$/', $v)) {
                                $this->addFilterError($field, 'invalid');
                                break;
                            }
                        }
                    }
                    break;
            }
            if (!(isset($values[0]) && $present($values[0])) && !\in_array($op, self::VALUELESS_OPERATORS, true)) {
                $this->addFilterError($field, 'blank');
            }
        }
    }

    private function addFilterError(string $field, string $message): void
    {
        $this->errors[] = $this->labelFor($field).' '.$this->ctx->l('activerecord.errors.messages.'.$message);
    }

    /** @return list<string> Query#filter_warnings */
    public function filterWarnings(): array
    {
        $out = [];
        foreach ($this->getFilters() as $field => $f) {
            if (!\in_array($this->typeFor($field), self::TOKENIZED_FILTER_TYPES, true) || !\in_array($f['operator'], self::TOKENIZED_FILTER_OPERATORS, true)) {
                continue;
            }
            $t = new Tokenizer($f['values'][0] ?? null);
            if (!$t->isTruncated()) {
                continue;
            }
            $tokens = implode($this->ctx->l('support.array.words_connector'), array_map(static fn ($s) => '"'.$s.'"', $t->tokens()));
            $out[] = $this->labelFor($field).': '.$this->ctx->l('notice_search_tokens_truncated', ['max' => Tokenizer::MAX_TOKENS, 'tokens' => $tokens]);
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // Visibility / permissions on the saved query
    // ------------------------------------------------------------------------------------------

    /** Query#visible? */
    public function isVisibleBy(?User $user = null): bool
    {
        $user ??= $this->ctx->user();
        if ($user->admin) {
            return true;
        }
        if (null !== $this->record->project && !$this->ctx->auth->allowedTo(static::viewPermission(), $this->record->project, $user)) {
            return false;
        }

        return match ((int) $this->record->visibility) {
            QueryRecord::VISIBILITY_PUBLIC => true,
            QueryRecord::VISIBILITY_ROLES => $this->rolesIntersect($user),
            default => $this->record->userId === $user->id,
        };
    }

    private function rolesIntersect(User $user): bool
    {
        $ids = array_map(static fn (Role $r) => $r->id, $this->record->roles->toArray());
        if (null !== $this->record->project) {
            foreach ($this->ctx->auth->rolesForProject($this->record->project, $user) as $r) {
                if (\in_array($r->id, $ids, true)) {
                    return true;
                }
            }

            return false;
        }
        foreach ($this->ctx->auth->membershipsOf($user) as $m) {
            foreach ($m->getRoles() as $r) {
                if (\in_array($r->id, $ids, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Query#editable_by? */
    public function isEditableBy(?User $user = null): bool
    {
        $user ??= $this->ctx->user();
        if (!$user->isLogged()) {
            return false;
        }
        if ($user->admin || ($this->isPrivate() && $this->record->userId === $user->id)) {
            return true;
        }

        return $this->isPublic() && !$this->isGlobal() && $this->ctx->auth->allowedTo('manage_public_queries', $this->record->project, $user);
    }

    // ------------------------------------------------------------------------------------------
    // Available filters
    // ------------------------------------------------------------------------------------------

    /**
     * @param list<array>|\Closure|null $values
     */
    protected function addAvailableFilter(string $field, string $type, array|\Closure|null $values = null, ?string $name = null, ?string $label = null, ?bool $remote = null, ?CustomField $customField = null, ?CustomField $through = null): void
    {
        $name ??= $this->ctx->l($label ?? preg_replace('/_id$/', '', 'field_'.$field));
        $this->availableFilters[$field] = new QueryFilter($field, $type, $name, $values, $remote, $customField, $through);
    }

    protected function deleteAvailableFilter(string $field): void
    {
        unset($this->availableFilters[$field]);
    }

    /** @return array<string, QueryFilter> */
    public function availableFilters(): array
    {
        if (null === $this->availableFilters) {
            $this->availableFilters = [];
            $this->initializeAvailableFilters();
        }

        return $this->availableFilters;
    }

    /** Query#available_filters_as_json */
    public function availableFiltersAsJson(): array
    {
        $json = [];
        foreach ($this->availableFilters() as $field => $filter) {
            $options = ['type' => $filter->type, 'name' => $filter->name];
            if ($filter->remote) {
                $options['remote'] = true;
            }
            if ($this->hasFilter($field) || !$filter->remote) {
                $values = $filter->values();
                if (null !== $values && null !== ($current = $this->valuesFor($field))) {
                    $known = array_map(static fn ($v) => (string) ($v[1] ?? ''), $values);
                    $missing = array_values(array_diff(array_filter($current, static fn ($v) => '' !== $v), $known));
                    if ([] !== $missing) {
                        $values = array_merge($values, $this->findFilterValues($field, $missing));
                    }
                }
                $options['values'] = $values;
            }
            $json[$field] = $options;
        }

        return $json;
    }

    /** find_<field>_filter_values hook: labels for values that are not in the filter's value list */
    protected function findFilterValues(string $field, array $missing): array
    {
        return [];
    }

    public static function operatorsLabels(QueryContext $ctx): array
    {
        $out = [];
        foreach (self::OPERATORS as $op => $label) {
            $out[$op] = \is_array($label) ? $ctx->l($label[0], $label[1]) : $ctx->l($label);
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // Filter values helpers
    // ------------------------------------------------------------------------------------------

    /** @return list<Project> visible projects */
    public function allProjects(): array
    {
        return $this->memo['all_projects'] ??= $this->ctx->projects->visibleProjects();
    }

    /** @return list<array{0: string, 1: string}> project tree for selects */
    public function allProjectsValues(): array
    {
        if (isset($this->memo['all_projects_values'])) {
            return $this->memo['all_projects_values'];
        }
        $projects = $this->allProjects();
        usort($projects, static fn (Project $a, Project $b) => ($a->lft ?? 0) <=> ($b->lft ?? 0));
        $values = [];
        $stack = [];
        foreach ($projects as $p) {
            while ([] !== $stack && !(($p->lft ?? 0) > end($stack)->lft && ($p->rgt ?? 0) < end($stack)->rgt)) {
                array_pop($stack);
            }
            $level = \count($stack);
            $values[] = [($level > 0 ? str_repeat('--', $level).' ' : '').$p->name, (string) $p->id];
            $stack[] = $p;
        }

        return $this->memo['all_projects_values'] = $values;
    }

    public function projectValues(): array
    {
        $values = [];
        $user = $this->ctx->user();
        if ($user->isLogged()) {
            if ([] !== $this->ctx->auth->membershipsOf($user)) {
                $values[] = ['<< '.mb_strtolower($this->ctx->l('label_my_projects')).' >>', 'mine'];
            }
            if ([] !== $this->bookmarkedProjectIds()) {
                $values[] = ['<< '.mb_strtolower($this->ctx->l('label_my_bookmarks')).' >>', 'bookmarks'];
            }
        }

        return array_merge($values, $this->allProjectsValues());
    }

    /** @return list<string> */
    protected function bookmarkedProjectIds(): array
    {
        $user = $this->ctx->user();
        if (!$user->isLogged()) {
            return [];
        }
        $ids = $user->getPref()->get('bookmarked_project_ids');
        if (\is_string($ids)) {
            $ids = explode(',', $ids);
        }

        return array_values(array_filter(array_map('strval', (array) $ids), static fn ($s) => '' !== $s));
    }

    public function subprojectValues(): array
    {
        if (null === $this->project) {
            return [];
        }
        $visible = $this->ctx->auth->visibleProjectIds();
        $rows = $this->ctx->conn()->fetchAllAssociative('SELECT id, name FROM projects WHERE lft > ? AND rgt < ? ORDER BY lft', [$this->project->lft ?? 0, $this->project->rgt ?? 0]);
        $out = [];
        foreach ($rows as $r) {
            if (\in_array((int) $r['id'], $visible, true)) {
                $out[] = [(string) $r['name'], (string) $r['id']];
            }
        }

        return $out;
    }

    /** @return list<Principal> Query#principals */
    public function principals(): array
    {
        if (isset($this->memo['principals'])) {
            return $this->memo['principals'];
        }
        if (null !== $this->project) {
            $ids = [$this->project->id];
            if (($this->project->rgt ?? 0) - ($this->project->lft ?? 0) > 1) {
                $visible = $this->ctx->auth->visibleProjectIds();
                foreach ($this->ctx->conn()->fetchFirstColumn('SELECT id FROM projects WHERE lft > ? AND rgt < ?', [$this->project->lft, $this->project->rgt]) as $id) {
                    if (\in_array((int) $id, $visible, true)) {
                        $ids[] = (int) $id;
                    }
                }
            }
        } else {
            $ids = array_map(static fn (Project $p) => $p->id, $this->allProjects());
        }
        $principals = [];
        if ([] !== $ids) {
            $principals = $this->ctx->em->createQuery(
                'SELECT DISTINCT pr FROM App\Entity\Principal pr JOIN pr.memberships m WHERE IDENTITY(m.project) IN (:ids) AND pr.status = 1'
            )->setParameter('ids', $ids)->getResult();
        }
        $principals = array_values(array_filter($principals, fn ($p) => !$p instanceof GroupBuiltin && $this->isPrincipalVisible($p)));
        usort($principals, self::comparePrincipals(...));

        return $this->memo['principals'] = $principals;
    }

    /** Principal.visible (simplified: users_visibility of the roles) */
    protected function isPrincipalVisible(Principal $p): bool
    {
        return true;
    }

    public static function comparePrincipals(Principal $a, Principal $b): int
    {
        if ($a::class === $b::class || ($a instanceof User && $b instanceof User) || ($a instanceof Group && $b instanceof Group)) {
            return strcasecmp($a->getName(), $b->getName());
        }

        return $a instanceof Group ? 1 : -1; // groups after users
    }

    /** sort_by {|p| [p.status, p]} */
    protected function sortByStatus(array $principals): array
    {
        usort($principals, static fn (Principal $a, Principal $b) => [$a->status, 0] <=> [$b->status, self::comparePrincipals($a, $b)]);

        return $principals;
    }

    protected function principalOption(Principal $p): array
    {
        $statusLabel = match ($p->status) {
            Principal::STATUS_ACTIVE => 'status_active',
            Principal::STATUS_REGISTERED => 'status_registered',
            Principal::STATUS_LOCKED => 'status_locked',
            default => 'status_anonymous',
        };

        return [$p->getName(), (string) $p->id, $this->ctx->l($statusLabel)];
    }

    /** @return list<User> */
    public function users(): array
    {
        return array_values(array_filter($this->principals(), static fn ($p) => $p instanceof User));
    }

    public function authorValues(): array
    {
        $values = [];
        if ($this->ctx->user()->isLogged()) {
            $values[] = ['<< '.$this->ctx->l('label_me').' >>', 'me'];
        }
        foreach ($this->sortByStatus($this->users()) as $u) {
            $values[] = $this->principalOption($u);
        }
        $anonymous = $this->ctx->currentUser->anonymous();
        $values[] = [$this->ctx->l('label_user_anonymous'), (string) $anonymous->id];

        return $values;
    }

    public function assignedToValues(): array
    {
        $values = [];
        if ($this->ctx->user()->isLogged()) {
            $values[] = ['<< '.$this->ctx->l('label_me').' >>', 'me'];
        }
        $list = $this->ctx->settings->bool('issue_group_assignment') ? $this->principals() : $this->users();
        foreach ($this->sortByStatus($list) as $p) {
            $values[] = $this->principalOption($p);
        }

        return $values;
    }

    public function fixedVersionValues(): array
    {
        if (null !== $this->project) {
            $versions = $this->ctx->projects->sharedVersions($this->project);
        } else {
            $versions = $this->visibleVersions();
        }

        return array_map(fn (Version $v) => [$v->project->name.' - '.$v->name, (string) $v->id, $this->ctx->l('version_status_'.$v->status)], $this->sortVersionsByStatus($versions));
    }

    /** @return list<Version> Version.visible */
    public function visibleVersions(): array
    {
        $ids = $this->ctx->auth->visibleProjectIds();
        $allowed = array_filter($ids, fn ($id) => true);
        if ([] === $allowed) {
            return [];
        }
        $cond = $this->ctx->auth->allowedToCondition('view_issues', 'IDENTITY(v.project)');

        return $this->ctx->em->createQuery("SELECT v, p FROM App\Entity\Version v JOIN v.project p WHERE $cond")->getResult();
    }

    /** @param list<Version> $versions Version.sort_by_status */
    public function sortVersionsByStatus(array $versions): array
    {
        $sorted = $this->ctx->projects->sortVersions($versions);
        $pos = array_flip(array_map(static fn (Version $v) => spl_object_id($v), $sorted));
        usort($versions, static function (Version $a, Version $b) use ($pos) {
            if ($a->status === $b->status) {
                return $pos[spl_object_id($a)] <=> $pos[spl_object_id($b)];
            }

            return strcmp($b->status, $a->status);
        });

        return $versions;
    }

    public function issueStatusesValues(): array
    {
        if (null !== $this->project) {
            $trackerIds = array_map(static fn ($t) => $t->id, $this->trackers(false));
            if ([] === $trackerIds) {
                return [];
            }
            $ids = $this->ctx->conn()->fetchFirstColumn(
                "SELECT old_status_id FROM workflows WHERE type = 'WorkflowTransition' AND tracker_id IN (".implode(',', $trackerIds).') AND old_status_id <> new_status_id'
                .' UNION SELECT new_status_id FROM workflows WHERE type = \'WorkflowTransition\' AND tracker_id IN ('.implode(',', $trackerIds).') AND old_status_id <> new_status_id'
            );
            $statuses = [] === $ids ? [] : $this->ctx->em->getRepository(IssueStatus::class)->findBy(['id' => array_map('intval', $ids)], ['position' => 'ASC']);
        } else {
            $statuses = $this->ctx->em->getRepository(IssueStatus::class)->findBy([], ['position' => 'ASC']);
        }

        return array_map(static fn (IssueStatus $s) => [$s->name, (string) $s->id], $statuses);
    }

    public function watcherValues(): array
    {
        $values = [['<< '.$this->ctx->l('label_me').' >>', 'me']];
        if ($this->ctx->auth->allowedTo('view_issue_watchers', $this->project, null, true)) {
            foreach ($this->sortByStatus($this->principals()) as $p) {
                $values[] = $this->principalOption($p);
            }
        }

        return $values;
    }

    public function projectStatusesValues(): array
    {
        return [
            [$this->ctx->l('project_status_active'), (string) Project::STATUS_ACTIVE],
            [$this->ctx->l('project_status_closed'), (string) Project::STATUS_CLOSED],
        ];
    }

    /**
     * Trackers of the query (Query#trackers): all trackers or the project's rolled up trackers, visible by the user.
     *
     * @return list<\App\Entity\Tracker>
     */
    public function trackers(bool $onlyVisible = true): array
    {
        $key = 'trackers'.($onlyVisible ? '_v' : '');
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $conn = $this->ctx->conn();
        if (null !== $this->project) {
            $ids = $conn->fetchFirstColumn(
                "SELECT DISTINCT t.id FROM trackers t JOIN projects_trackers pt ON pt.tracker_id = t.id JOIN projects p ON p.id = pt.project_id
                 JOIN enabled_modules em ON em.project_id = p.id AND em.name = 'issue_tracking'
                 WHERE p.status <> 9 AND p.lft >= ? AND p.rgt <= ?",
                [$this->project->lft ?? 0, $this->project->rgt ?? 0]
            );
        } else {
            $ids = $conn->fetchFirstColumn('SELECT id FROM trackers');
        }
        $ids = array_map('intval', $ids);
        if ($onlyVisible && !$this->ctx->user()->admin) {
            $cond = $this->ctx->auth->allowedToCondition('view_issues', 'p.id', null, static function (Role $role) {
                if (!$role->permissionsAllTrackers('view_issues')) {
                    $t = $role->permissionsTrackerIds('view_issues');

                    return [] !== $t ? 't.id IN ('.implode(',', array_map('intval', $t)).')' : '1=0';
                }

                return null;
            });
            $visible = array_map('intval', $conn->fetchFirstColumn("SELECT DISTINCT t.id FROM trackers t JOIN projects_trackers pt ON pt.tracker_id = t.id JOIN projects p ON p.id = pt.project_id WHERE $cond"));
            $ids = array_values(array_intersect($ids, $visible));
        }
        $trackers = [] === $ids ? [] : $this->ctx->em->getRepository(\App\Entity\Tracker::class)->findBy(['id' => $ids], ['position' => 'ASC']);

        return $this->memo[$key] = $trackers;
    }

    /** @return list<string> Tracker.disabled_core_fields(trackers) */
    public function disabledCoreFields(): array
    {
        $trackers = $this->trackers();
        if ([] === $trackers) {
            return [];
        }
        $fields = null;
        foreach ($trackers as $t) {
            $d = $t->getDisabledCoreFields();
            $fields = null === $fields ? $d : array_values(array_intersect($fields, $d));
        }

        return $fields ?? [];
    }

    /** @return list<IssueCustomField> Query#issue_custom_fields (project rolled up fields or all) */
    public function issueCustomFields(): array
    {
        if (isset($this->memo['issue_custom_fields'])) {
            return $this->memo['issue_custom_fields'];
        }
        $all = $this->ctx->customFields->fieldsOf(IssueCustomField::class);
        if (null !== $this->project) {
            $ids = array_map('intval', $this->ctx->conn()->fetchFirstColumn(
                'SELECT DISTINCT cfp.custom_field_id FROM custom_fields_projects cfp JOIN projects p ON p.id = cfp.project_id WHERE p.lft >= ? AND p.rgt <= ?',
                [$this->project->lft ?? 0, $this->project->rgt ?? 0]
            ));
            $all = array_values(array_filter($all, static fn (IssueCustomField $f) => $f->isForAll || \in_array($f->id, $ids, true)));
        }

        return $this->memo['issue_custom_fields'] = $all;
    }

    /** CustomField.visible scope for the current user */
    public function isCustomFieldVisible(CustomField $field): bool
    {
        $user = $this->ctx->user();
        if ($field->visible || $user->admin) {
            return true;
        }
        $roleIds = array_map(static fn (Role $r) => $r->id, $field->roles->toArray());
        foreach ($this->ctx->auth->membershipsOf($user) as $m) {
            foreach ($m->getRoles() as $r) {
                if (\in_array($r->id, $roleIds, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    // ------------------------------------------------------------------------------------------
    // Custom field filters
    // ------------------------------------------------------------------------------------------

    /** CustomField#query_filter_options: [type, values|null] */
    protected function customFieldFilterOptions(CustomField $field): array
    {
        return match ($field->fieldFormat) {
            'string', 'link' => ['string', null],
            'text' => ['text', null],
            'int', 'progressbar' => ['integer', null],
            'float' => ['float', null],
            'date' => ['date', null],
            'list', 'bool', 'enumeration', 'user', 'version' => ['list_optional', fn () => $this->customFieldFilterValues($field)],
            default => ['string', null],
        };
    }

    protected function customFieldFilterValues(CustomField $field): array
    {
        switch ($field->fieldFormat) {
            case 'list':
                return array_map(static fn ($v) => [$v, $v], $field->getPossibleValues());
            case 'bool':
                return [[$this->ctx->l('general_text_Yes'), '1'], [$this->ctx->l('general_text_No'), '0']];
            case 'enumeration':
                $out = [];
                foreach ($field->enumerations as $e) {
                    if ($e->active) {
                        $out[] = [$e->name, (string) $e->id];
                    }
                }

                return $out;
            case 'user':
                $types = ['User'];
                $values = [];
                foreach ($this->sortByStatus(array_values(array_filter($this->principals(), static fn ($p) => $p instanceof User))) as $p) {
                    $values[] = $this->principalOption($p);
                }
                if ($this->ctx->user()->isLogged()) {
                    array_unshift($values, ['<< '.$this->ctx->l('label_me').' >>', 'me']);
                }

                return $values;
            case 'version':
                $versions = null !== $this->project ? $this->ctx->projects->sharedVersions($this->project)
                    : $this->ctx->em->createQuery("SELECT v, p FROM App\Entity\Version v JOIN v.project p WHERE v.sharing = 'system'")->getResult();

                return array_map(fn (Version $v) => [$v->project->name.' - '.$v->name, (string) $v->id, $this->ctx->l('version_status_'.$v->status)], $this->sortVersionsByStatus($versions));
        }

        return [];
    }

    protected function addCustomFieldFilter(CustomField $field, ?string $assoc = null): void
    {
        [$type, $values] = $this->customFieldFilterOptions($field);
        $id = 'cf_'.$field->id;
        $name = $field->name;
        if (null !== $assoc) {
            $id = $assoc.'.'.$id;
            $name = $this->ctx->l('label_attribute_of_'.$assoc, ['name' => $name]);
        }
        $this->addAvailableFilter($id, $type, $values, $name, null, null, $field);
    }

    /** @param list<CustomField> $fields */
    protected function addCustomFieldsFilters(array $fields, ?string $assoc = null): void
    {
        foreach ($fields as $field) {
            if (!$field->isFilter || !$this->isCustomFieldVisible($field)) {
                continue;
            }
            $this->addCustomFieldFilter($field, $assoc);
            if (null === $assoc) {
                $this->addChainedCustomFieldFilters($field);
                if ('version' === $field->fieldFormat) {
                    $this->addAvailableFilter('cf_'.$field->id.'.due_date', 'date', null,
                        $this->ctx->l('label_attribute_of_object', ['name' => $this->ctx->l('field_effective_date'), 'object_name' => $field->name]), null, null, $field);
                    $this->addAvailableFilter('cf_'.$field->id.'.status', 'list',
                        array_map(fn ($s) => [$this->ctx->l('version_status_'.$s), $s], Version::STATUSES),
                        $this->ctx->l('label_attribute_of_object', ['name' => $this->ctx->l('field_status'), 'object_name' => $field->name]), null, null, $field);
                }
            }
        }
    }

    protected function addChainedCustomFieldFilters(CustomField $field): void
    {
        $class = match ($field->fieldFormat) {
            'user' => 'UserCustomField',
            'version' => 'VersionCustomField',
            default => null,
        };
        if (null === $class) {
            return;
        }
        $chained = $this->ctx->em->createQuery('SELECT f FROM App\Entity\CustomField f WHERE f.isFilter = true AND f INSTANCE OF App\Entity\\'.$class.' ORDER BY f.position')->getResult();
        foreach ($chained as $c) {
            [$type, $values] = $this->customFieldFilterOptions($c);
            $this->addAvailableFilter('cf_'.$field->id.'.cf_'.$c->id, $type, $values,
                $this->ctx->l('label_attribute_of_object', ['name' => $c->name, 'object_name' => $field->name]), null, null, $c, $field);
        }
    }

    /**
     * @param array<string, string> $associations assoc name => CustomField class (e.g. 'project' => ProjectCustomField::class)
     */
    protected function addAssociationsCustomFieldsFilters(array $associations): void
    {
        $fields = $this->ctx->em->createQuery('SELECT f FROM App\Entity\CustomField f WHERE f.isFilter = true ORDER BY f.position')->getResult();
        $fields = array_values(array_filter($fields, fn (CustomField $f) => $this->isCustomFieldVisible($f)));
        foreach ($associations as $assoc => $classes) {
            foreach ($fields as $field) {
                foreach ((array) $classes as $class) {
                    if ($field instanceof $class) {
                        $this->addCustomFieldFilter($field, $assoc);
                    }
                }
            }
        }
    }

    // ------------------------------------------------------------------------------------------
    // Columns
    // ------------------------------------------------------------------------------------------

    /** @return list<QueryColumn> */
    public function availableColumns(): array
    {
        return $this->availableColumns ??= $this->buildAvailableColumns();
    }

    public function findColumn(string $name): ?QueryColumn
    {
        foreach ($this->availableColumns() as $c) {
            if ($c->name === $name) {
                return $c;
            }
        }

        return null;
    }

    /** @return list<QueryColumn> */
    public function groupableColumns(): array
    {
        return array_values(array_filter($this->availableColumns(), static fn (QueryColumn $c) => $c->isGroupable()));
    }

    /** @return array<string, list<string>|null> */
    public function sortableColumns(): array
    {
        $out = [];
        foreach ($this->availableColumns() as $c) {
            $out[$c->name] = $c->sortable;
        }

        return $out;
    }

    /** @return list<QueryColumn> */
    public function columns(): array
    {
        $available = $this->availableColumns();
        if ([] === $available) {
            return [];
        }
        $names = $this->hasDefaultColumns() ? $this->defaultColumnsNames() : $this->getColumnNames();
        $cols = [];
        foreach ($available as $c) {
            if ($c->frozen) {
                $cols[$c->name] = $c;
            }
        }
        foreach ($names as $name) {
            if (null !== ($c = $this->findColumn($name)) && !isset($cols[$name])) {
                $cols[$name] = $c;
            }
        }

        return array_values($cols);
    }

    /** @return list<QueryColumn> */
    public function inlineColumns(): array
    {
        return array_values(array_filter($this->columns(), static fn (QueryColumn $c) => $c->inline));
    }

    /** @return list<QueryColumn> */
    public function blockColumns(): array
    {
        return array_values(array_filter($this->columns(), static fn (QueryColumn $c) => !$c->inline));
    }

    /** @return list<QueryColumn> */
    public function availableInlineColumns(): array
    {
        return array_values(array_filter($this->availableColumns(), static fn (QueryColumn $c) => $c->inline));
    }

    /** @return list<QueryColumn> */
    public function availableBlockColumns(): array
    {
        return array_values(array_filter($this->availableColumns(), static fn (QueryColumn $c) => !$c->inline));
    }

    /** @return list<QueryColumn> */
    public function availableTotalableColumns(): array
    {
        return array_values(array_filter($this->availableColumns(), static fn (QueryColumn $c) => $c->totalable));
    }

    /** @return list<QueryColumn> */
    public function totalableColumns(): array
    {
        $names = $this->totalableNames();

        return array_values(array_filter($this->availableTotalableColumns(), static fn (QueryColumn $c) => \in_array($c->name, $names, true)));
    }

    public function hasColumn(string $name): bool
    {
        foreach ($this->columns() as $c) {
            if ($c->name === $name) {
                return true;
            }
        }

        return false;
    }

    public function hasCustomFieldColumn(): bool
    {
        foreach ($this->columns() as $c) {
            if ($c->isCustomField()) {
                return true;
            }
        }

        return false;
    }

    /** available inline columns not selected (for the columns selector) */
    public function availableInlineColumnsOptions(): array
    {
        $selected = array_map(static fn (QueryColumn $c) => $c->name, $this->columns());

        return array_values(array_filter($this->availableInlineColumns(), static fn (QueryColumn $c) => !$c->frozen && !\in_array($c->name, $selected, true)));
    }

    public function selectedInlineColumnsOptions(): array
    {
        return array_values(array_filter($this->inlineColumns(), static fn (QueryColumn $c) => !$c->frozen));
    }

    public function cssClasses(): ?string
    {
        $c = $this->sortCriteria()->toArray()[0] ?? null;
        if (null === $c) {
            return null;
        }

        return 'sort-by-'.str_replace('_', '-', $c[0]).' sort-'.$c[1];
    }

    // ------------------------------------------------------------------------------------------
    // Sorting & grouping
    // ------------------------------------------------------------------------------------------

    /** @return list<string> */
    public function sortClause(): array
    {
        return $this->sortCriteria()->sortClause($this->sortableColumns());
    }

    public function groupByColumn(): ?QueryColumn
    {
        $g = $this->getGroupBy();
        if (null === $g || '' === $g) {
            return null;
        }
        foreach ($this->groupableColumns() as $c) {
            if ($c->name === $g) {
                return $c;
            }
        }

        return null;
    }

    public function isGrouped(): bool
    {
        return null !== $this->groupByColumn();
    }

    public function groupByStatement(): ?string
    {
        return $this->groupByColumn()?->groupStatement;
    }

    /** @return list<string> */
    public function groupBySortOrder(): array
    {
        $column = $this->groupByColumn();
        if (null === $column) {
            return [];
        }
        $order = strtoupper($this->sortCriteria()->orderFor($column->name) ?? $column->defaultOrder ?? 'asc');
        $sortable = $column->sortable ?? [];
        if (QueryColumn::TIMESTAMP === $column->kind) {
            $sortable = [(string) $column->groupStatement];
        }

        return array_map(static fn ($s) => "$s $order", $sortable);
    }

    // ------------------------------------------------------------------------------------------
    // SQL generation
    // ------------------------------------------------------------------------------------------

    /** Query#project_statement */
    public function projectStatement(): ?string
    {
        if (null === $this->project) {
            return null;
        }
        $p = $this->project;
        $subprojectIds = array_map('intval', $this->ctx->conn()->fetchFirstColumn('SELECT id FROM projects WHERE lft > ? AND rgt < ? AND status <> ?', [$p->lft ?? 0, $p->rgt ?? 0, Project::STATUS_ARCHIVED]));
        if ([] !== $subprojectIds) {
            if ($this->hasFilter('subproject_id')) {
                switch ($this->operatorFor('subproject_id')) {
                    case '=':
                        $ids = array_merge([$p->id], array_map('intval', $this->valuesFor('subproject_id') ?? []));

                        return 'projects.id IN ('.implode(',', $ids).')';
                    case '!':
                        $ids = array_values(array_diff(array_merge([$p->id], $subprojectIds), array_map('intval', $this->valuesFor('subproject_id') ?? [])));

                        return [] === $ids ? '1=0' : 'projects.id IN ('.implode(',', $ids).')';
                    case '!*':
                        return 'projects.id = '.(int) $p->id;
                    default:
                        return 'projects.lft >= '.(int) $p->lft.' AND projects.rgt <= '.(int) $p->rgt;
                }
            }
            if ($this->ctx->settings->displaySubprojectsIssues()) {
                return 'projects.lft >= '.(int) $p->lft.' AND projects.rgt <= '.(int) $p->rgt;
            }

            return 'projects.id = '.(int) $p->id;
        }

        return 'projects.id = '.(int) $p->id;
    }

    /** Query#statement: the WHERE clause built from filters + project */
    public function statement(): ?string
    {
        $clauses = [];
        if ($this->isValidCached()) {
            foreach ($this->getFilters() as $field => $f) {
                if ('subproject_id' === $field) {
                    continue;
                }
                $v = $f['values'];
                if ([] === $v) {
                    continue;
                }
                $operator = $f['operator'];
                $v = $this->substituteSpecialValues($field, $v);
                if (preg_match('/^cf_(\d+)\.cf_(\d+)$/', $field, $m)) {
                    $clauses[] = $this->sqlForChainedCustomField($field, $operator, $v, (int) $m[1], (int) $m[2]);
                } elseif (preg_match('/cf_(\d+)$/', $field, $m)) {
                    $clauses[] = $this->sqlForCustomField($field, $operator, $v, (int) $m[1]);
                } elseif (preg_match('/^cf_(\d+)\.(.+)$/', $field, $m)) {
                    $clauses[] = $this->sqlForCustomFieldAttribute($field, $operator, $v, (int) $m[1], $m[2]);
                } elseif (null !== ($sql = $this->sqlForSpecificField($field, $operator, $v))) {
                    $clauses[] = $sql;
                } else {
                    $clauses[] = '('.$this->sqlForField($field, $operator, $v, $this->queriedTable(), $field).')';
                }
            }
        }
        $c = $this->groupByColumn();
        if (null !== $c && QueryColumn::CUSTOM_FIELD === $c->kind && null !== $c->customField) {
            $clauses[] = $this->customFieldVisibilityCondition($c->customField);
        }
        $clauses[] = $this->projectStatement();
        $clauses = array_values(array_filter($clauses, static fn ($c) => null !== $c && '' !== trim($c)));

        return [] === $clauses ? null : implode(' AND ', $clauses);
    }

    private function isValidCached(): bool
    {
        return $this->validated ?? $this->isValid();
    }

    /** "me", "mine", "bookmarks" substitution */
    protected function substituteSpecialValues(string $field, array $v): array
    {
        $user = $this->ctx->user();
        if (\in_array($field, ['assigned_to_id', 'author_id', 'user_id', 'watcher_id', 'updated_by', 'last_updated_by'], true)) {
            if (false !== ($i = array_search('me', $v, true))) {
                unset($v[$i]);
                $v = array_values($v);
                if ($user->isLogged()) {
                    $v[] = (string) $user->id;
                    if (\in_array($field, ['assigned_to_id', 'watcher_id'], true)) {
                        foreach ($user->getGroupIds() as $gid) {
                            $v[] = (string) $gid;
                        }
                    }
                } else {
                    $v[] = '0';
                }
            }
        }
        if ('project_id' === $field || $this->substitutesProjectValues($field)) {
            if (false !== ($i = array_search('mine', $v, true))) {
                unset($v[$i]);
                $v = array_values($v);
                foreach (array_keys($this->ctx->auth->membershipsOf($user)) as $pid) {
                    $v[] = (string) $pid;
                }
            }
            if (false !== ($i = array_search('bookmarks', $v, true))) {
                unset($v[$i]);
                $v = array_merge(array_values($v), $this->bookmarkedProjectIds());
            }
        }

        return $v;
    }

    protected function substitutesProjectValues(string $field): bool
    {
        return false;
    }

    /** sql_for_<field>_field hook. Returns null when there is no specific statement. */
    protected function sqlForSpecificField(string $field, string $operator, array $value): ?string
    {
        return null;
    }

    /** visibility_by_project_condition of a custom field */
    public function customFieldVisibilityCondition(CustomField $field, ?string $projectKey = null, ?string $idColumn = null): string
    {
        $user = $this->ctx->user();
        $idColumn ??= (string) $field->id;
        if ($field->visible || $user->admin) {
            $sql = '1=1';
        } elseif (!$user->isLogged()) {
            $sql = '1=0';
        } else {
            $projectKey ??= $field instanceof ProjectCustomField ? 'projects.id' : $this->queriedTable().'.project_id';
            $sql = "$projectKey IN (SELECT DISTINCT m.project_id FROM members m INNER JOIN member_roles mr ON mr.member_id = m.id"
                .' INNER JOIN custom_fields_roles cfr ON cfr.role_id = mr.role_id WHERE m.user_id = '.(int) $user->id." AND cfr.custom_field_id = $idColumn)";
        }
        if ($field instanceof IssueCustomField) {
            $tracker = "issues.tracker_id IN (SELECT tracker_id FROM custom_fields_trackers WHERE custom_field_id = $idColumn)";
            $project = "EXISTS (SELECT 1 FROM custom_fields ifa WHERE ifa.is_for_all = ".$this->ctx->quotedTrue()." AND ifa.id = $idColumn)"
                ." OR issues.project_id IN (SELECT project_id FROM custom_fields_projects WHERE custom_field_id = $idColumn)";

            return "(($sql) AND ($tracker) AND ($project) AND (".$this->ctx->issueVisibleSql().'))';
        }

        return $sql;
    }

    /** customized_class for association custom fields: [table, customized_type, key column] */
    protected function associationCustomizedClass(string $assoc): array
    {
        throw new QueryError('Unknown '.$this->queriedClass().' association '.$assoc);
    }

    protected function sqlForCustomField(string $field, string $operator, array $value, int $customFieldId): ?string
    {
        $filter = $this->availableFilters()[$field] ?? null;
        if (null === $filter || null === $filter->customField) {
            return null;
        }
        $cf = $filter->customField;
        if ('user' === $cf->fieldFormat && false !== ($i = array_search('me', $value, true))) {
            unset($value[$i]);
            $value = array_values($value);
            $value[] = (string) $this->ctx->user()->id;
        }
        $notIn = '';
        if ('!' === $operator) {
            $operator = '=';
            $notIn = 'NOT';
        }
        $customizedKey = 'id';
        $table = $this->queriedTable();
        $type = $this->queriedClass();
        if (preg_match('/^(.+)\.cf_/', $field, $m)) {
            [$table, $type, $customizedKey] = $this->associationCustomizedClass($m[1]);
        }
        $where = $this->sqlForField($field, $operator, $value, 'custom_values', 'value', true);
        if (preg_match('/[<>]/', $operator)) {
            $where = "($where) AND custom_values.value <> ''";
        }

        return "$notIn EXISTS (SELECT ct.id FROM $table ct LEFT OUTER JOIN custom_values ON custom_values.customized_type='$type'"
            .' AND custom_values.customized_id=ct.id AND custom_values.custom_field_id='.$customFieldId
            .' WHERE '.$this->queriedTable().".$customizedKey = ct.id AND  ($where) AND (".$this->customFieldVisibilityCondition($cf).'))';
    }

    protected function sqlForChainedCustomField(string $field, string $operator, array $value, int $customFieldId, int $chainedId): string
    {
        $notIn = '';
        if ('!' === $operator) {
            $operator = '=';
            $notIn = 'NOT';
        }
        $filter = $this->availableFilters()[$field] ?? null;
        $target = 'version' === $filter?->through?->fieldFormat ? 'Version' : 'Principal';

        return $this->queriedTable().".id $notIn IN (SELECT customized_id FROM custom_values WHERE customized_type='".$this->queriedClass()."' AND custom_field_id=$customFieldId"
            ." AND CAST(CASE value WHEN '' THEN '0' ELSE value END AS decimal(30,0)) IN ("
            ." SELECT customized_id FROM custom_values WHERE customized_type='$target' AND custom_field_id=$chainedId"
            .' AND '.$this->sqlForField($field, $operator, $value, 'custom_values', 'value', true).'))';
    }

    protected function sqlForCustomFieldAttribute(string $field, string $operator, array $value, int $customFieldId, string $attribute): string
    {
        if ('due_date' === $attribute) {
            $attribute = 'effective_date';
        }
        $notIn = '';
        if ('!' === $operator) {
            $operator = '=';
            $notIn = 'NOT';
        }
        $filter = $this->availableFilters()[$field] ?? null;
        $targetTable = 'version' === $filter?->customField?->fieldFormat ? 'versions' : 'users';

        return $this->queriedTable().".id $notIn IN (SELECT customized_id FROM custom_values WHERE customized_type='".$this->queriedClass()."' AND custom_field_id=$customFieldId"
            ." AND CAST(CASE value WHEN '' THEN '0' ELSE value END AS decimal(30,0)) IN ("
            ." SELECT id FROM $targetTable WHERE ".$this->sqlForField($field, $operator, $value, $targetTable, $attribute).'))';
    }

    /** Query#sql_for_field */
    public function sqlForField(string $field, string $operator, array $value, string $dbTable, string $dbField, bool $isCustomFilter = false): string
    {
        $col = "$dbTable.$dbField";
        $type = $this->typeFor($field);
        $isDate = \in_array($type, ['date', 'date_past'], true);
        $castCol = "CAST(CASE $col WHEN '' THEN '0' ELSE $col END AS decimal(30,3))";
        $first = (string) ($value[0] ?? '');
        switch ($operator) {
            case '=':
                if ([] === $value) {
                    return '1=0';
                }
                if ($isDate) {
                    $d = $this->parseDate($first);

                    return $this->dateClause($dbTable, $dbField, $d, $d, $isCustomFilter);
                }
                if ('integer' === $type) {
                    preg_match_all('/[+-]?\d+/', $first, $m);
                    $ints = implode(',', array_map('intval', $m[0]));
                    if ('' === $ints) {
                        return '1=0';
                    }

                    return $isCustomFilter ? "($col <> '' AND $castCol IN ($ints))" : "$col IN ($ints)";
                }
                if (\in_array($type, ['float', 'hour'], true)) {
                    $f = (float) $first;
                    $lo = self::num($f - 1e-5);
                    $hi = self::num($f + 1e-5);

                    return $isCustomFilter ? "($col <> '' AND $castCol BETWEEN $lo AND $hi)" : "$col BETWEEN $lo AND $hi";
                }

                return "$col IN (".$this->ctx->quoteList($value).')';
            case '!':
                if ([] === $value) {
                    return '1=1';
                }

                return "($col IS NULL OR $col NOT IN (".$this->ctx->quoteList($value).'))';
            case '!*':
                $sql = "$col IS NULL";
                if ($isCustomFilter || \in_array($type, ['text', 'string'], true)) {
                    $sql .= " OR $col = ''";
                }

                return $sql;
            case '*':
                $sql = "$col IS NOT NULL";
                if ($isCustomFilter || \in_array($type, ['text', 'string'], true)) {
                    $sql .= " AND $col <> ''";
                }

                return $sql;
            case '>=':
                if ($isDate) {
                    return $this->dateClause($dbTable, $dbField, $this->parseDate($first), null, $isCustomFilter);
                }

                return $isCustomFilter ? "($col <> '' AND $castCol >= ".self::num((float) $first).')' : "$col >= ".self::num((float) $first);
            case '<=':
                if ($isDate) {
                    return $this->dateClause($dbTable, $dbField, null, $this->parseDate($first), $isCustomFilter);
                }

                return $isCustomFilter ? "($col <> '' AND $castCol <= ".self::num((float) $first).')' : "$col <= ".self::num((float) $first);
            case '><':
                if ($isDate) {
                    return $this->dateClause($dbTable, $dbField, $this->parseDate($first), $this->parseDate((string) ($value[1] ?? '')), $isCustomFilter);
                }
                $a = self::num((float) $first);
                $b = self::num((float) ($value[1] ?? 0));

                return $isCustomFilter ? "($col <> '' AND $castCol BETWEEN $a AND $b)" : "$col BETWEEN $a AND $b";
            case 'o':
                return 'status_id' === $field ? $this->queriedTable().'.status_id IN (SELECT id FROM issue_statuses WHERE is_closed='.$this->ctx->quotedFalse().')' : '';
            case 'c':
                return 'status_id' === $field ? $this->queriedTable().'.status_id IN (SELECT id FROM issue_statuses WHERE is_closed='.$this->ctx->quotedTrue().')' : '';
            case '><t-':
                return $this->relativeDateClause($dbTable, $dbField, -(int) $first, 0, $isCustomFilter);
            case '>t-':
                return $this->relativeDateClause($dbTable, $dbField, -(int) $first, null, $isCustomFilter);
            case '<t-':
                return $this->relativeDateClause($dbTable, $dbField, null, -(int) $first, $isCustomFilter);
            case 't-':
                return $this->relativeDateClause($dbTable, $dbField, -(int) $first, -(int) $first, $isCustomFilter);
            case '><t+':
                return $this->relativeDateClause($dbTable, $dbField, 0, (int) $first, $isCustomFilter);
            case '>t+':
                return $this->relativeDateClause($dbTable, $dbField, (int) $first, null, $isCustomFilter);
            case '<t+':
                return $this->relativeDateClause($dbTable, $dbField, null, (int) $first, $isCustomFilter);
            case 't+':
                return $this->relativeDateClause($dbTable, $dbField, (int) $first, (int) $first, $isCustomFilter);
            case 't':
                return $this->relativeDateClause($dbTable, $dbField, 0, 0, $isCustomFilter);
            case 'ld':
                return $this->relativeDateClause($dbTable, $dbField, -1, -1, $isCustomFilter);
            case 'nd':
                return $this->relativeDateClause($dbTable, $dbField, 1, 1, $isCustomFilter);
            case 'w':
                $daysAgo = $this->daysSinceStartOfWeek();

                return $this->relativeDateClause($dbTable, $dbField, -$daysAgo, -$daysAgo + 6, $isCustomFilter);
            case 'lw':
                $daysAgo = $this->daysSinceStartOfWeek();

                return $this->relativeDateClause($dbTable, $dbField, -$daysAgo - 7, -$daysAgo - 1, $isCustomFilter);
            case 'l2w':
                $daysAgo = $this->daysSinceStartOfWeek();

                return $this->relativeDateClause($dbTable, $dbField, -$daysAgo - 14, -$daysAgo - 1, $isCustomFilter);
            case 'nw':
                $from = -$this->daysSinceStartOfWeek() + 7;

                return $this->relativeDateClause($dbTable, $dbField, $from, $from + 6, $isCustomFilter);
            case 'm':
                $d = $this->ctx->i18n->today();

                return $this->dateClause($dbTable, $dbField, $d->modify('first day of this month'), $d->modify('last day of this month'), $isCustomFilter);
            case 'lm':
                $d = $this->ctx->i18n->today()->modify('first day of last month');

                return $this->dateClause($dbTable, $dbField, $d, $d->modify('last day of this month'), $isCustomFilter);
            case 'nm':
                $d = $this->ctx->i18n->today()->modify('first day of next month');

                return $this->dateClause($dbTable, $dbField, $d, $d->modify('last day of this month'), $isCustomFilter);
            case 'y':
                $d = $this->ctx->i18n->today();

                return $this->dateClause($dbTable, $dbField, $d->setDate((int) $d->format('Y'), 1, 1), $d->setDate((int) $d->format('Y'), 12, 31), $isCustomFilter);
            case '~':
                return $this->sqlContains($col, $first);
            case '!~':
                $sql = $this->sqlContains($col, $first, match: false);
                if ($isCustomFilter) {
                    $sql .= " OR $col IS NULL";
                }

                return $sql;
            case '*~':
                return $this->sqlContains($col, $first, allWords: false);
            case '^':
                return $this->sqlContains($col, $first, startsWith: true);
            case '$':
                return $this->sqlContains($col, $first, endsWith: true);
            case 'ev':
            case '!ev':
            case 'cf':
                return $this->sqlForHistory($field, $operator, $value, $dbTable, $dbField);
            default:
                throw new QueryError('Unknown query operator '.$operator);
        }
    }

    /** "has been", "has never been", "changed from" operators (only for issues) */
    protected function sqlForHistory(string $field, string $operator, array $value, string $dbTable, string $dbField): string
    {
        return '1=0';
    }

    private function daysSinceStartOfWeek(): int
    {
        $first = (int) $this->ctx->l('general_first_day_of_week');
        $dow = (int) $this->ctx->i18n->today()->format('N');

        return $dow >= $first ? $dow - $first : $dow + 7 - $first;
    }

    private static function num(float $f): string
    {
        $s = rtrim(rtrim(\sprintf('%.6F', $f), '0'), '.');

        return '' === $s || '-0' === $s ? '0' : $s;
    }

    /** Query#sql_contains / tokenized_like_conditions */
    public function sqlContains(string $dbField, ?string $value, bool $match = true, bool $allWords = true, bool $startsWith = false, bool $endsWith = false): string
    {
        $tokens = (new Tokenizer($value))->tokens();
        if ([] === $tokens) {
            $tokens = [(string) $value];
        }
        if ($startsWith) {
            [$prefix, $suffix, $opr] = ['', '%', ' OR '];
        } elseif ($endsWith) {
            [$prefix, $suffix, $opr] = ['%', '', ' OR '];
        } else {
            [$prefix, $suffix] = ['%', '%'];
            $opr = $allWords ? ' AND ' : ' OR ';
        }
        $parts = [];
        foreach ($tokens as $token) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $token);
            $parts[] = $this->ctx->like($dbField, $this->ctx->quote($prefix.$escaped.$suffix), $match);
        }

        return implode($opr, $parts);
    }

    /** Returns a Date (DateTimeImmutable at midnight, flagged as date) or Time from a filter value */
    protected function parseDate(?string $arg): ?array
    {
        $arg = (string) $arg;
        if ('' === $arg) {
            return null;
        }
        try {
            if (preg_match('/\A\d{4}-\d{2}-\d{2}T/', $arg)) {
                return ['time', new \DateTimeImmutable($arg, $this->ctx->i18n->userTimezone())];
            }
            if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $arg, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }

            return ['date', new \DateTimeImmutable(\sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]))];
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Query#date_clause. $from/$to are parseDate() results or DateTimeImmutable dates.
     */
    public function dateClause(string $table, string $field, array|\DateTimeImmutable|null $from, array|\DateTimeImmutable|null $to, bool $isCustomFilter): string
    {
        $tz = $this->ctx->i18n->userTimezone();
        $utc = new \DateTimeZone('UTC');
        $s = [];
        if (null !== $from) {
            [$kind, $d] = $from instanceof \DateTimeImmutable ? ['date', $from] : $from;
            if ('date' === $kind) {
                $t = new \DateTimeImmutable($d->format('Y-m-d').' 00:00:00', $tz);
                $t = $t->modify('-1 day')->setTime(23, 59, 59, 999999);
            } else {
                $t = $d->modify('-1 second');
            }
            $s[] = "$table.$field > '".$this->quotedTime($t->setTimezone($utc), $isCustomFilter)."'";
        }
        if (null !== $to) {
            [$kind, $d] = $to instanceof \DateTimeImmutable ? ['date', $to] : $to;
            $t = 'date' === $kind ? (new \DateTimeImmutable($d->format('Y-m-d').' 00:00:00', $tz))->setTime(23, 59, 59, 999999) : $d;
            $s[] = "$table.$field <= '".$this->quotedTime($t->setTimezone($utc), $isCustomFilter)."'";
        }

        return implode(' AND ', $s);
    }

    private function quotedTime(\DateTimeImmutable $t, bool $isCustomFilter): string
    {
        return $isCustomFilter ? $t->format('Y-m-d H:i:s') : $t->format('Y-m-d H:i:s.u');
    }

    public function relativeDateClause(string $table, string $field, ?int $daysFrom, ?int $daysTo, bool $isCustomFilter): string
    {
        $today = $this->ctx->i18n->today();
        $from = null !== $daysFrom ? new \DateTimeImmutable($today->modify(($daysFrom >= 0 ? '+' : '').$daysFrom.' days')->format('Y-m-d')) : null;
        $to = null !== $daysTo ? new \DateTimeImmutable($today->modify(($daysTo >= 0 ? '+' : '').$daysTo.' days')->format('Y-m-d')) : null;

        return $this->dateClause($table, $field, $from, $to, $isCustomFilter);
    }

    /** String#to_hours: "1.5", "1,5", "1:30", "1h30", "1h", "30m" */
    public static function toHours(?string $s): ?float
    {
        $s = trim((string) $s);
        if ('' === $s) {
            return null;
        }
        if (preg_match('/^(\d+([.,]\d+)?)h?$/', $s, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }
        if (preg_match('/^(\d+):(\d+)$/', $s, $m)) {
            return (int) $m[1] + (int) $m[2] / 60;
        }
        if (preg_match('/^((\d+)\s*(h|hours?))?\s*((\d+)\s*(m|min)?)?$/i', $s, $m) && ('' !== ($m[1] ?? '') || '' !== ($m[4] ?? ''))) {
            return (int) ($m[2] ?? 0) + (int) ($m[5] ?? 0) / 60;
        }
        $s = str_replace(',', '.', $s);

        return is_numeric($s) ? (float) $s : null;
    }

    // ------------------------------------------------------------------------------------------
    // Execution helpers
    // ------------------------------------------------------------------------------------------

    /** Full WHERE of the base scope (visibility + statement) */
    public function whereSql(): string
    {
        $parts = [$this->baseConditionSql()];
        if (null !== ($st = $this->statement())) {
            $parts[] = '('.$st.')';
        }

        return implode(' AND ', $parts);
    }

    /** Executes a SELECT on the base scope. */
    protected function fetch(string $select, string $extraJoins = '', ?string $groupBy = null, array $order = [], ?int $limit = null, ?int $offset = null, ?string $extraWhere = null): array
    {
        $sql = 'SELECT '.$select.' FROM '.$this->baseFromSql().($extraJoins ? ' '.$extraJoins : '').' WHERE '.$this->whereSql();
        if (null !== $extraWhere) {
            $sql .= ' AND ('.$extraWhere.')';
        }
        if (null !== $groupBy) {
            $sql .= ' GROUP BY '.$groupBy;
        }
        if ([] !== $order) {
            $sql .= ' ORDER BY '.implode(', ', $order);
        }
        if (null !== $limit) {
            $sql .= ' LIMIT '.(int) $limit;
            if (null !== $offset) {
                $sql .= ' OFFSET '.(int) $offset;
            }
        }
        try {
            return $this->ctx->conn()->fetchAllAssociative($sql);
        } catch (\Doctrine\DBAL\Exception $e) {
            throw new StatementInvalid($e->getMessage(), 0, $e);
        }
    }

    public function count(): int
    {
        $rows = $this->fetch('COUNT(DISTINCT '.$this->queriedTable().'.id) AS c');

        return (int) ($rows[0]['c'] ?? 0);
    }
}

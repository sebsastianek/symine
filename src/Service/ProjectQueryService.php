<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectCustomField;
use App\Entity\ProjectQuery;
use App\Entity\Query;
use App\Entity\User;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Port of Redmine's ProjectQuery (projects list): filters, columns, sort, grouping, display type.
 *
 * A query state is a plain array:
 * {id, name, description, filters: {field: {operator, values}}, columns: list<string>, sort: list<[col, dir]>,
 *  group_by, display_type, valid, errors}
 */
final class ProjectQueryService
{
    public const array COLUMNS = [
        'name' => ['sortable' => 'p.name', 'caption' => 'field_name'],
        'status' => ['sortable' => 'p.status', 'caption' => 'field_status'],
        'short_description' => ['sortable' => 'p.description', 'caption' => 'field_description'],
        'homepage' => ['sortable' => 'p.homepage', 'caption' => 'field_homepage'],
        'identifier' => ['sortable' => 'p.identifier', 'caption' => 'field_identifier'],
        'parent_id' => ['sortable' => 'p.lft', 'caption' => 'field_parent', 'default_order' => 'desc'],
        'is_public' => ['sortable' => 'p.isPublic', 'caption' => 'field_is_public', 'groupable' => true],
        'created_on' => ['sortable' => 'p.createdOn', 'caption' => 'field_created_on', 'default_order' => 'desc'],
        'updated_on' => ['sortable' => 'p.updatedOn', 'caption' => 'field_updated_on', 'default_order' => 'desc'],
        'last_activity_date' => ['sortable' => null, 'caption' => 'field_last_activity_date'],
    ];

    public const array OPERATOR_LABELS = [
        '=' => 'label_equals', '!' => 'label_not_equals', 'o' => 'label_open_issues', 'c' => 'label_closed_issues',
        '!*' => 'label_none', '*' => 'label_any', '>=' => 'label_greater_or_equal', '<=' => 'label_less_or_equal',
        '><' => 'label_between', '<t+' => 'label_in_less_than', '>t+' => 'label_in_more_than', '><t+' => 'label_in_the_next_days',
        't+' => 'label_in', 'nd' => 'label_tomorrow', 't' => 'label_today', 'ld' => 'label_yesterday', 'nw' => 'label_next_week',
        'w' => 'label_this_week', 'lw' => 'label_last_week', 'l2w' => 'label_last_n_weeks', 'nm' => 'label_next_month',
        'm' => 'label_this_month', 'lm' => 'label_last_month', 'y' => 'label_this_year', '>t-' => 'label_less_than_ago',
        '<t-' => 'label_more_than_ago', '><t-' => 'label_in_the_past_days', 't-' => 'label_ago', '~' => 'label_contains',
        '!~' => 'label_not_contains', '*~' => 'label_contains_any_of', '^' => 'label_starts_with', '$' => 'label_ends_with',
        '=p' => 'label_any_issues_in_project', '=!p' => 'label_any_issues_not_in_project', '!p' => 'label_no_issues_in_project',
        '*o' => 'label_any_open_issues', '!o' => 'label_no_open_issues', 'ev' => 'label_has_been', '!ev' => 'label_has_never_been',
        'cf' => 'label_changed_from',
    ];

    /** @return array<string, string> */
    public function operatorLabels(): array
    {
        $out = [];
        foreach (self::OPERATOR_LABELS as $op => $key) {
            $out[$op] = 'l2w' === $op ? $this->i18n->l($key, ['count' => 2]) : $this->i18n->l($key);
        }

        return $out;
    }

    public const array OPERATORS_BY_TYPE = [
        'list' => ['=', '!'],
        'list_subprojects' => ['*', '!*', '=', '!'],
        'text' => ['~', '*~', '!~', '^', '$', '!*', '*'],
        'string' => ['~', '*~', '=', '!~', '!', '^', '$', '!*', '*'],
        'date_past' => ['=', '>=', '<=', '><', '>t-', '<t-', '><t-', 't-', 't', 'ld', 'w', 'lw', 'l2w', 'm', 'lm', 'y', '!*', '*'],
        'integer' => ['=', '>=', '<=', '><', '!*', '*'],
        'float' => ['=', '>=', '<=', '><', '!*', '*'],
        'list_optional' => ['=', '!', '!*', '*'],
        'date' => ['=', '>=', '<=', '><', '<t+', '>t+', '><t+', 't+', 'nd', 't', 'ld', 'nw', 'w', 'lw', 'l2w', 'nm', 'm', 'lm', 'y', '>t-', '<t-', '><t-', 't-', '!*', '*'],
    ];

    private ?array $filtersCache = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly CustomFieldService $customFields,
        private readonly ProjectManager $manager,
    ) {
    }

    /** @return list<ProjectCustomField> */
    public function visibleCustomFields(): array
    {
        $user = $this->currentUser->get();

        return array_values(array_filter($this->customFields->fieldsOf(ProjectCustomField::class), static fn ($f) => $f->visible || $user->admin));
    }

    /** @return array<string, array{type: string, name: string, values?: list<array{0: string, 1: string}>|null, remote?: bool}> */
    public function availableFilters(): array
    {
        if (null !== $this->filtersCache) {
            return $this->filtersCache;
        }
        $filters = [
            'status' => ['type' => 'list', 'name' => $this->i18n->l('field_status'), 'values' => $this->filterValues('status')],
            'id' => ['type' => 'list', 'name' => $this->i18n->l('field_project'), 'values' => $this->filterValues('id')],
            'name' => ['type' => 'text', 'name' => $this->i18n->l('field_name'), 'values' => null],
            'description' => ['type' => 'text', 'name' => $this->i18n->l('field_description'), 'values' => null],
            'parent_id' => ['type' => 'list_subprojects', 'name' => $this->i18n->l('field_parent'), 'values' => $this->filterValues('parent_id')],
            'is_public' => ['type' => 'list', 'name' => $this->i18n->l('field_is_public'), 'values' => [[$this->i18n->l('general_text_yes'), '1'], [$this->i18n->l('general_text_no'), '0']]],
            'created_on' => ['type' => 'date_past', 'name' => $this->i18n->l('field_created_on'), 'values' => null],
            'updated_on' => ['type' => 'date_past', 'name' => $this->i18n->l('field_updated_on'), 'values' => null],
        ];
        foreach ($this->visibleCustomFields() as $f) {
            if (!$f->isFilter) {
                continue;
            }
            $type = match ($f->fieldFormat) {
                'list', 'bool', 'enumeration', 'user', 'version' => 'list_optional',
                'date' => 'date',
                'int' => 'integer',
                'float' => 'float',
                'text' => 'text',
                default => 'string',
            };
            $values = 'list_optional' === $type ? array_map(static fn ($o) => [$o[1], $o[0]], $this->customFields->possibleValuesOptions($f)) : null;
            $filters['cf_'.$f->id] = ['type' => $type, 'name' => $f->name, 'values' => $values];
        }

        return $this->filtersCache = $filters;
    }

    /** Values of the remote filters (status, id, parent_id) for /queries/filter */
    public function filterValues(string $name): array
    {
        if ('status' === $name) {
            return [[$this->i18n->l('project_status_active'), '1'], [$this->i18n->l('project_status_closed'), '5']];
        }
        $values = [];
        if ('id' === $name && $this->currentUser->get()->isLogged()) {
            $values[] = ['<< '.$this->i18n->l('label_my_projects').' >>', 'mine'];
            $values[] = ['<< '.$this->i18n->l('label_my_bookmarks').' >>', 'bookmarks'];
        }
        $projects = $this->em->createQuery('SELECT p FROM App\Entity\Project p WHERE '.\App\Security\Authorization::in('p.id', $this->auth->visibleProjectIds()).' ORDER BY p.lft')->getResult();
        $levels = [];
        foreach ($projects as $p) {
            $level = 0;
            foreach ($p->getAncestors() as $a) {
                if (\in_array($a, $projects, true)) {
                    ++$level;
                }
            }
            $values[] = [str_repeat('  ', $level).($level > 0 ? '» ' : '').$p->name, (string) $p->id];
            $levels[] = $level;
        }

        return $values;
    }

    /** @return array<string, string> column name => caption */
    public function availableColumns(): array
    {
        $out = [];
        foreach (self::COLUMNS as $name => $def) {
            $out[$name] = $this->i18n->l($def['caption']);
        }
        foreach ($this->visibleCustomFields() as $f) {
            $out['cf_'.$f->id] = $f->name;
        }

        return $out;
    }

    /** @return list<string> */
    public function defaultColumns(): array
    {
        $defaults = $this->settings->hash('project_list_defaults');
        $cols = array_map('strval', (array) ($defaults['column_names'] ?? ['name', 'identifier', 'short_description']));

        return [] === $cols ? ['name', 'identifier', 'short_description'] : $cols;
    }

    /** @return list<ProjectQuery> queries visible by the user (sidebar) */
    public function visibleQueries(): array
    {
        $user = $this->currentUser->get();
        $all = $this->em->createQuery("SELECT q FROM App\Entity\ProjectQuery q WHERE q INSTANCE OF App\Entity\ProjectQuery ORDER BY q.name")->getResult();

        return array_values(array_filter($all, fn (Query $q) => $this->isQueryVisible($q, $user)));
    }

    public function isQueryVisible(Query $q, User $user): bool
    {
        if ($user->admin) {
            return true;
        }
        if (Query::VISIBILITY_PUBLIC === $q->visibility) {
            return true;
        }
        if (Query::VISIBILITY_ROLES === $q->visibility) {
            $roleIds = array_map(static fn ($r) => $r->id, $q->roles->toArray());
            foreach ($this->auth->allRoles($user) as $r) {
                if (\in_array($r->id, $roleIds, true)) {
                    return true;
                }
            }
        }

        return $user->isLogged() && $q->userId === $user->id;
    }

    /** Builds the query state from the request (Redmine retrieve_query) */
    public function fromRequest(Request $request, bool $api = false): array
    {
        $q = $request->query;
        $state = $this->newState();
        if (!$api && !$q->has('query_id') && !$q->has('set_filter') && !$q->has('without_default')) {
            $default = $this->defaultQuery();
            if (null !== $default) {
                return $this->fromEntity($default) + ['from_default' => true];
            }
        }
        if ($q->has('query_id') && '' !== (string) $q->get('query_id')) {
            $entity = $this->em->find(ProjectQuery::class, (int) $q->get('query_id'));
            if ($entity instanceof ProjectQuery && $entity::class === ProjectQuery::class && $this->isQueryVisible($entity, $this->currentUser->get())) {
                $state = $this->fromEntity($entity);
                if ($q->has('sort')) {
                    $state['sort'] = $this->parseSort((string) $q->get('sort'), $state);
                }

                return $state;
            }
        }
        if ($q->has('set_filter') || $q->has('f') || $api) {
            $fields = $q->all()['f'] ?? null;
            if (\is_array($fields)) {
                $ops = (array) ($q->all()['op'] ?? []);
                $vals = (array) ($q->all()['v'] ?? []);
                $filters = [];
                foreach ($fields as $field) {
                    $field = (string) $field;
                    if ('' === $field) {
                        continue;
                    }
                    $filters[$field] = ['operator' => (string) ($ops[$field] ?? '='), 'values' => array_map('strval', (array) ($vals[$field] ?? ['']))];
                }
                $state['filters'] = $filters;
            } elseif ($q->has('set_filter') || $api) {
                // filters given as field=value (API) e.g. status=1, parent_id=2, is_public=1
                $filters = $api ? [] : $state['filters'];
                foreach (array_keys($this->availableFilters()) as $field) {
                    if ($q->has($field)) {
                        $raw = $q->all()[$field];
                        $filters[$field] = $this->parseShortFilter(\is_array($raw) ? implode('|', $raw) : (string) $raw);
                    }
                }
                if ($api && !$q->has('status')) {
                    // ProjectQuery default filter: active projects (status=1)
                    $filters = ['status' => ['operator' => '=', 'values' => ['1']]] + $filters;
                }
                $state['filters'] = $filters;
            }
            $cols = $q->all()['c'] ?? null;
            if (\is_array($cols)) {
                $cols = array_values(array_filter(array_map('strval', $cols), fn ($c) => isset($this->availableColumns()[$c])));
                if ([] !== $cols) {
                    $state['columns'] = $cols;
                }
            }
            $group = (string) $q->get('group_by', '');
            $state['group_by'] = '' !== $group && $this->isGroupable($group) ? $group : null;
        }
        // Query#build_from_params: display_type is taken from the params even without set_filter
        if ($q->has('display_type') && \in_array($q->get('display_type'), ['board', 'list'], true)) {
            $state['display_type'] = (string) $q->get('display_type');
        }
        if ($q->has('sort')) {
            $state['sort'] = $this->parseSort((string) $q->get('sort'), $state);
        }
        $this->validateState($state);

        return $state;
    }

    private function newState(): array
    {
        $display = $this->settings->string('project_list_display_type');

        return [
            'id' => null, 'name' => null, 'description' => null,
            'filters' => ['status' => ['operator' => '=', 'values' => ['1']]],
            'columns' => $this->defaultColumns(), 'sort' => [], 'group_by' => null,
            'display_type' => \in_array($display, ['board', 'list'], true) ? $display : 'board',
            'valid' => true, 'errors' => [],
        ];
    }

    /** ProjectQuery.default (id) */
    public function defaultQueryId(): ?int
    {
        return $this->defaultQuery()?->id;
    }

    private function defaultQuery(): ?ProjectQuery
    {
        $user = $this->currentUser->get();
        if ($user->isLogged() && ($id = $user->getPref()->get('default_project_query'))) {
            $q = $this->em->find(ProjectQuery::class, (int) $id);
            if ($q instanceof ProjectQuery && $this->isQueryVisible($q, $user)) {
                return $q;
            }
        }
        $id = $this->settings->string('default_project_query');
        if ('' !== $id) {
            $q = $this->em->find(ProjectQuery::class, (int) $id);
            if ($q instanceof ProjectQuery && Query::VISIBILITY_PUBLIC === $q->visibility) {
                return $q;
            }
        }

        return null;
    }

    private function fromEntity(ProjectQuery $q): array
    {
        $state = $this->newState();
        $state['id'] = $q->id;
        $state['name'] = $q->name;
        $state['description'] = $q->description;
        $filters = [];
        foreach ((array) $q->filters as $field => $f) {
            $filters[(string) $field] = ['operator' => (string) ($f['operator'] ?? '='), 'values' => array_map('strval', (array) ($f['values'] ?? []))];
        }
        $state['filters'] = $filters;
        if (\is_array($q->columnNames) && [] !== $q->columnNames) {
            $state['columns'] = array_map('strval', $q->columnNames);
        }
        if (\is_array($q->sortCriteria)) {
            $state['sort'] = array_values(array_filter(array_map(static fn ($c) => \is_array($c) ? [(string) $c[0], (string) ($c[1] ?? 'asc')] : null, $q->sortCriteria)));
        }
        $state['group_by'] = $q->groupBy ?: null;
        $display = $q->getOption('display_type');
        if (\in_array($display, ['board', 'list'], true)) {
            $state['display_type'] = $display;
        }
        $this->validateState($state);

        return $state;
    }

    /** "1", "!1", "*", "!*", "~foo", ">=2020-01-01" short syntax (API) */
    private function parseShortFilter(string $value): array
    {
        foreach (['!*', '*', '!~', '~', '>=', '<=', '!', '^', '$'] as $op) {
            if (str_starts_with($value, $op)) {
                $rest = substr($value, \strlen($op));

                return ['operator' => $op, 'values' => '' === $rest ? [''] : explode('|', $rest)];
            }
        }

        return ['operator' => '=', 'values' => explode('|', $value)];
    }

    private function parseSort(string $sort, array $state): array
    {
        $out = [];
        foreach (explode(',', $sort) as $part) {
            [$col, $dir] = array_pad(explode(':', trim($part)), 2, null);
            if (null === $col || '' === $col || !$this->isSortable($col)) {
                continue;
            }
            $dir = 'desc' === $dir ? 'desc' : 'asc';
            $out[] = [$col, $dir];
        }

        return \array_slice($out, 0, 3);
    }

    public function isSortable(string $col): bool
    {
        return (isset(self::COLUMNS[$col]) && null !== self::COLUMNS[$col]['sortable']) || str_starts_with($col, 'cf_');
    }

    public function isGroupable(string $col): bool
    {
        if ('is_public' === $col) {
            return true;
        }
        if (str_starts_with($col, 'cf_')) {
            foreach ($this->visibleCustomFields() as $f) {
                if ('cf_'.$f->id === $col && \in_array($f->fieldFormat, ['list', 'bool', 'date', 'int', 'float', 'string', 'enumeration', 'user', 'version'], true) && !$f->isMultiple()) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, string> groupable columns */
    public function groupableColumns(): array
    {
        return array_filter($this->availableColumns(), fn ($caption, $name) => $this->isGroupable($name), \ARRAY_FILTER_USE_BOTH);
    }

    private function validateState(array &$state): void
    {
        $available = $this->availableFilters();
        foreach ($state['filters'] as $field => $f) {
            if (!isset($available[$field])) {
                unset($state['filters'][$field]);
                continue;
            }
            $op = $f['operator'];
            $values = array_values(array_filter($f['values'], static fn ($v) => '' !== $v));
            if (!\in_array($op, ['!*', '*', 't', 'ld', 'w', 'lw', 'l2w', 'm', 'lm', 'y', 'nd', 'nw', 'nm'], true) && [] === $values) {
                $state['errors'][] = $available[$field]['name'].' '.$this->i18n->l('activerecord.errors.messages.blank');
            }
            if (\in_array($available[$field]['type'], ['date', 'date_past'], true) && \in_array($op, ['=', '>=', '<=', '><'], true)) {
                foreach ($values as $v) {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                        $state['errors'][] = $available[$field]['name'].' '.$this->i18n->l('activerecord.errors.messages.not_a_date');
                    }
                }
            }
        }
        $state['valid'] = [] === $state['errors'];
    }

    // --- execution ----------------------------------------------------------------------------

    /** @return array{0: string, 1: array<string, mixed>} DQL where + params */
    private function statement(array $state): array
    {
        $user = $this->currentUser->get();
        $params = [];
        $parts = [Authorization::in('p.id', $this->auth->visibleProjectIds($user))];
        $i = 0;
        foreach ($state['filters'] as $field => $f) {
            ++$i;
            $op = $f['operator'];
            $values = array_values(array_filter($f['values'], static fn ($v) => '' !== $v));
            $sql = null;
            switch ($field) {
                case 'status':
                    $sql = $this->listSql('p.status', $op, array_map('intval', $values), $params, $i);
                    break;
                case 'is_public':
                    // boolean literals (an array parameter of PHP booleans binds false as '' and never matches)
                    $bools = array_values(array_unique(array_map(static fn ($v) => '1' === $v ? 'true' : 'false', $values)));
                    $sql = match (true) {
                        [] === $bools => '=' === $op ? '1=0' : null,
                        2 === \count($bools) => '=' === $op ? null : '1=0',
                        '=' === $op => 'p.isPublic = '.$bools[0],
                        '!' === $op => 'p.isPublic <> '.$bools[0],
                        default => null,
                    };
                    break;
                case 'id':
                    $ids = [];
                    foreach ($values as $v) {
                        if ('mine' === $v) {
                            array_push($ids, ...array_keys($this->auth->membershipsOf($user)));
                            $ids[] = 0;
                        } elseif ('bookmarks' === $v) {
                            array_push($ids, ...$this->manager->bookmarkedProjectIds($user));
                            $ids[] = 0;
                        } else {
                            $ids[] = (int) $v;
                        }
                    }
                    $sql = $this->listSql('p.id', $op, $ids, $params, $i);
                    break;
                case 'parent_id':
                    $sql = match ($op) {
                        '*' => 'p.parent IS NOT NULL',
                        '!*' => 'p.parent IS NULL',
                        '=' => [] === $values ? '1=0' : 'IDENTITY(p.parent) IN ('.implode(',', array_map('intval', $values)).')',
                        '!' => [] === $values ? '1=1' : '(p.parent IS NULL OR IDENTITY(p.parent) NOT IN ('.implode(',', array_map('intval', $values)).'))',
                        default => null,
                    };
                    break;
                case 'name':
                case 'description':
                    $sql = $this->textSql('p.'.$field, $op, $values, $params, $i);
                    break;
                case 'created_on':
                case 'updated_on':
                    $sql = $this->dateSql('created_on' === $field ? 'p.createdOn' : 'p.updatedOn', $op, $values, $params, $i, true);
                    break;
                default:
                    if (str_starts_with($field, 'cf_')) {
                        $sql = $this->customFieldSql((int) substr($field, 3), $op, $values, $params, $i);
                    }
            }
            if (null !== $sql) {
                $parts[] = '('.$sql.')';
            }
        }

        return [implode(' AND ', $parts), $params];
    }

    private function listSql(string $expr, string $op, array $values, array &$params, int $i): ?string
    {
        if ([] === $values) {
            return '=' === $op ? '1=0' : null;
        }
        $params['l'.$i] = $values;

        return match ($op) {
            '=' => "$expr IN (:l$i)",
            '!' => "$expr NOT IN (:l$i)",
            default => null,
        };
    }

    private function textSql(string $expr, string $op, array $values, array &$params, int $i): ?string
    {
        $v = (string) ($values[0] ?? '');
        $like = static fn (string $s) => '%'.addcslashes(mb_strtolower($s), '%_\\').'%';
        switch ($op) {
            case '!*':
                return "($expr IS NULL OR $expr = '')";
            case '*':
                return "($expr IS NOT NULL AND $expr <> '')";
            case '=':
                $params['t'.$i] = $v;

                return "$expr = :t$i";
            case '!':
                $params['t'.$i] = $v;

                return "($expr IS NULL OR $expr <> :t$i)";
            case '^':
                $params['t'.$i] = addcslashes(mb_strtolower($v), '%_\\').'%';

                return "LOWER($expr) LIKE :t$i";
            case '$':
                $params['t'.$i] = '%'.addcslashes(mb_strtolower($v), '%_\\');

                return "LOWER($expr) LIKE :t$i";
            case '~':
            case '!~':
            case '*~':
                $tokens = array_values(array_filter(preg_split('/\s+/', trim($v)) ?: []));
                if ([] === $tokens) {
                    return null;
                }
                $conds = [];
                foreach ($tokens as $k => $t) {
                    $params["t{$i}_$k"] = $like($t);
                    $conds[] = '!~' === $op ? "($expr IS NULL OR LOWER($expr) NOT LIKE :t{$i}_$k)" : "LOWER($expr) LIKE :t{$i}_$k";
                }

                return implode('*~' === $op ? ' OR ' : ' AND ', $conds);
        }

        return null;
    }

    private function dateSql(string $expr, string $op, array $values, array &$params, int $i, bool $isTime): ?string
    {
        $today = $this->i18n->today();
        $range = function (?\DateTimeImmutable $from, ?\DateTimeImmutable $to) use ($expr, &$params, $i, $isTime): string {
            $c = [];
            if (null !== $from) {
                $params['df'.$i] = $isTime ? $from->setTime(0, 0) : $from->format('Y-m-d');
                $c[] = "$expr >= :df$i";
            }
            if (null !== $to) {
                $params['dt'.$i] = $isTime ? $to->setTime(23, 59, 59) : $to->format('Y-m-d');
                $c[] = "$expr <= :dt$i";
            }

            return [] === $c ? '1=1' : implode(' AND ', $c);
        };
        $d = static fn (?string $s) => null !== $s && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? new \DateTimeImmutable($s) : null;
        $n = (int) ($values[0] ?? 0);
        $weekStart = $this->i18n->startOfWeek();
        $startOfWeek = $today->modify('-'.((((int) $today->format('w')) - $weekStart + 7) % 7).' days');

        return match ($op) {
            '=' => $range($d($values[0] ?? null), $d($values[0] ?? null)),
            '>=' => $range($d($values[0] ?? null), null),
            '<=' => $range(null, $d($values[0] ?? null)),
            '><' => $range($d($values[0] ?? null), $d($values[1] ?? null)),
            '>t-' => $range($today->modify("-$n days"), $today),
            '<t-' => $range(null, $today->modify("-$n days")),
            '><t-' => $range($today->modify("-$n days"), $today),
            't-' => $range($today->modify("-$n days"), $today->modify("-$n days")),
            '>t+' => $range($today->modify("+$n days"), null),
            '<t+' => $range($today, $today->modify("+$n days")),
            '><t+' => $range($today, $today->modify("+$n days")),
            't+' => $range($today->modify("+$n days"), $today->modify("+$n days")),
            't' => $range($today, $today),
            'ld' => $range($today->modify('-1 day'), $today->modify('-1 day')),
            'nd' => $range($today->modify('+1 day'), $today->modify('+1 day')),
            'w' => $range($startOfWeek, $startOfWeek->modify('+6 days')),
            'lw' => $range($startOfWeek->modify('-7 days'), $startOfWeek->modify('-1 day')),
            'l2w' => $range($startOfWeek->modify('-14 days'), $startOfWeek->modify('-1 day')),
            'nw' => $range($startOfWeek->modify('+7 days'), $startOfWeek->modify('+13 days')),
            'm' => $range($today->modify('first day of this month'), $today->modify('last day of this month')),
            'lm' => $range($today->modify('first day of last month'), $today->modify('last day of last month')),
            'nm' => $range($today->modify('first day of next month'), $today->modify('last day of next month')),
            'y' => $range($today->setDate((int) $today->format('Y'), 1, 1), $today->setDate((int) $today->format('Y'), 12, 31)),
            '!*' => "$expr IS NULL",
            '*' => "$expr IS NOT NULL",
            default => null,
        };
    }

    private function customFieldSql(int $fieldId, string $op, array $values, array &$params, int $i): ?string
    {
        $sub = "SELECT cv$i.customizedId FROM App\Entity\CustomValue cv$i WHERE cv$i.customizedType = 'Project' AND IDENTITY(cv$i.customField) = $fieldId";
        switch ($op) {
            case '*':
                return "p.id IN ($sub AND cv$i.value IS NOT NULL AND cv$i.value <> '')";
            case '!*':
                return "p.id NOT IN ($sub AND cv$i.value IS NOT NULL AND cv$i.value <> '')";
            case '=':
                $params['cf'.$i] = $values;

                return "p.id IN ($sub AND cv$i.value IN (:cf$i))";
            case '!':
                $params['cf'.$i] = $values;

                return "p.id NOT IN ($sub AND cv$i.value IN (:cf$i))";
            case '~':
                $params['cf'.$i] = '%'.mb_strtolower((string) ($values[0] ?? '')).'%';

                return "p.id IN ($sub AND LOWER(cv$i.value) LIKE :cf$i)";
            case '!~':
                $params['cf'.$i] = '%'.mb_strtolower((string) ($values[0] ?? '')).'%';

                return "p.id NOT IN ($sub AND LOWER(cv$i.value) LIKE :cf$i)";
            case '^':
                $params['cf'.$i] = mb_strtolower((string) ($values[0] ?? '')).'%';

                return "p.id IN ($sub AND LOWER(cv$i.value) LIKE :cf$i)";
            case '$':
                $params['cf'.$i] = '%'.mb_strtolower((string) ($values[0] ?? ''));

                return "p.id IN ($sub AND LOWER(cv$i.value) LIKE :cf$i)";
        }

        return null;
    }

    public function count(array $state): int
    {
        [$where, $params] = $this->statement($state);

        return (int) $this->em->createQuery("SELECT COUNT(p.id) FROM App\Entity\Project p WHERE $where")->setParameters($params)->getSingleScalarResult();
    }

    /** @return list<Project> */
    public function results(array $state, ?int $offset = null, ?int $limit = null, ?string $orderOverride = null): array
    {
        [$where, $params] = $this->statement($state);
        $orders = [];
        if (null !== $state['group_by'] && 'is_public' === $state['group_by']) {
            $orders[] = 'p.isPublic DESC';
        }
        if (null !== $orderOverride) {
            $orders[] = $orderOverride;
        }
        $cfSorts = [];
        foreach ($state['sort'] as [$col, $dir]) {
            if (isset(self::COLUMNS[$col]['sortable'])) {
                $orders[] = self::COLUMNS[$col]['sortable'].' '.strtoupper($dir);
            } elseif (str_starts_with($col, 'cf_')) {
                $cfSorts[] = [(int) substr($col, 3), $dir];
            }
        }
        $orders[] = 'p.lft ASC';
        $qb = $this->em->createQuery("SELECT p FROM App\Entity\Project p WHERE $where ORDER BY ".implode(', ', $orders))->setParameters($params);
        if ([] !== $cfSorts) {
            // custom field sorting done in PHP
            $all = $qb->getResult();
            $values = $this->customFields->valuesFor('Project', array_map(static fn (Project $p) => (int) $p->id, $all));
            usort($all, static function (Project $a, Project $b) use ($cfSorts, $values) {
                foreach ($cfSorts as [$fid, $dir]) {
                    $c = strnatcasecmp((string) ($values[$a->id][$fid] ?? ''), (string) ($values[$b->id][$fid] ?? ''));
                    if (0 !== $c) {
                        return 'desc' === $dir ? -$c : $c;
                    }
                }

                return $a->lft <=> $b->lft;
            });

            return \array_slice($all, $offset ?? 0, $limit);
        }
        if (null !== $offset) {
            $qb->setFirstResult($offset);
        }
        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getResult();
    }

    /** Query string parameters reproducing the state (links, sort headers) */
    public function toParams(array $state): array
    {
        $p = ['set_filter' => '1', 'f' => [], 'op' => [], 'v' => []];
        foreach ($state['filters'] as $field => $f) {
            $p['f'][] = $field;
            $p['op'][$field] = $f['operator'];
            $p['v'][$field] = $f['values'];
        }
        $p['f'][] = '';
        $p['display_type'] = $state['display_type'];
        if ('list' === $state['display_type']) {
            $p['c'] = $state['columns'];
        }
        if ($state['group_by']) {
            $p['group_by'] = $state['group_by'];
        }

        return $p;
    }
}

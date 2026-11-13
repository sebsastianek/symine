<?php

declare(strict_types=1);

namespace App\Query;

use App\Entity\CustomField;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\UserCustomField;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Port of Redmine's UserQuery (app/models/user_query.rb): the admin user list (/users).
 *
 * The queried class is Principal (custom values are stored with customized_type = 'Principal'),
 * the base scope is User.logged.
 */
#[Exclude]
class UserQuery extends AbstractQuery
{
    /** @var array<int, string>|null auth source names by id */
    private ?array $authSourceNames = null;
    /** @var array<int, string|null> default email address by user id (filled by results()) */
    private array $mails = [];
    /** @var array<int, array<int, mixed>> custom values by user id (filled by results()) */
    private array $customValues = [];

    public function __construct(QueryContext $ctx, \App\Entity\Query $record, ?Project $project = null)
    {
        parent::__construct($ctx, $record, $project);
        // UserQuery#initialize: self.filters ||= {'status' => {operator: "=", values: [User::STATUS_ACTIVE]}}
        if (null === $record->filters) {
            $record->filters = ['status' => ['operator' => '=', 'values' => [(string) Principal::STATUS_ACTIVE]]];
        }
    }

    public function queriedTable(): string
    {
        return 'users';
    }

    public function queriedClass(): string
    {
        // must be Principal (not User) for custom field filters to work
        return 'Principal';
    }

    public static function viewPermission(): string
    {
        // UserQuery.visible: admins only (no project permission involved)
        return 'admin';
    }

    /** User.logged.includes(:email_address) */
    public function baseFromSql(): string
    {
        return 'users LEFT OUTER JOIN email_addresses ON email_addresses.user_id = users.id AND email_addresses.is_default = '.$this->ctx->quotedTrue();
    }

    public function baseConditionSql(): string
    {
        return "(users.type IN ('User', 'AnonymousUser') AND users.status <> ".Principal::STATUS_ANONYMOUS.')';
    }

    /** UserQuery.visible scope: all user queries for admins, none otherwise (hook used by QueryFactory::visibleQueries) */
    public static function recordVisibleInScope(\App\Entity\Query $record, User $user): bool
    {
        return $user->admin;
    }

    /** UserQuery#visible? */
    public function isVisibleBy(?User $user = null): bool
    {
        return ($user ?? $this->ctx->user())->admin;
    }

    /** UserQuery#editable_by? */
    public function isEditableBy(?User $user = null): bool
    {
        return ($user ?? $this->ctx->user())->admin;
    }

    // ------------------------------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------------------------------

    protected function initializeAvailableFilters(): void
    {
        $l = $this->ctx->l(...);
        $this->addAvailableFilter('status', 'list_optional', fn () => $this->userStatusesValues());
        $this->addAvailableFilter('auth_source_id', 'list_optional', fn () => $this->authSourcesValues());
        $this->addAvailableFilter('is_member_of_group', 'list_optional', fn () => array_map(
            static fn (array $g) => [(string) $g['lastname'], (string) $g['id']],
            $this->ctx->conn()->fetchAllAssociative("SELECT id, lastname FROM users WHERE type = 'Group' ORDER BY lastname, id")
        ));
        if ($this->twofaEnabled()) {
            $this->addAvailableFilter('twofa_scheme', 'list_optional', fn () => array_map(
                fn (string $s) => [$l('twofa__'.$s.'__name'), $s], self::twofaSchemes()
            ));
        }
        $this->addAvailableFilter('name', 'text', null, null, 'field_name_or_email_or_login');
        $this->addAvailableFilter('login', 'string');
        $this->addAvailableFilter('firstname', 'string');
        $this->addAvailableFilter('lastname', 'string');
        $this->addAvailableFilter('mail', 'string');
        $this->addAvailableFilter('created_on', 'date_past');
        $this->addAvailableFilter('last_login_on', 'date_past');
        $this->addAvailableFilter('admin', 'list', [[$l('general_text_yes'), '1'], [$l('general_text_no'), '0']]);
        $this->addCustomFieldsFilters($this->userCustomFields());
    }

    /** @return list<string> Redmine::Twofa.available_schemes */
    public static function twofaSchemes(): array
    {
        return ['totp'];
    }

    public function twofaEnabled(): bool
    {
        // Setting.twofa?
        return $this->ctx->settings->int('twofa') > 0;
    }

    /** @return list<array{0: string, 1: string}> */
    public function userStatusesValues(): array
    {
        return [
            [$this->ctx->l('status_active'), (string) Principal::STATUS_ACTIVE],
            [$this->ctx->l('status_registered'), (string) Principal::STATUS_REGISTERED],
            [$this->ctx->l('status_locked'), (string) Principal::STATUS_LOCKED],
        ];
    }

    /** @return list<array{0: string, 1: string}> */
    public function authSourcesValues(): array
    {
        return array_map(static fn (array $r) => [(string) $r['name'], (string) $r['id']],
            $this->ctx->conn()->fetchAllAssociative('SELECT id, name FROM auth_sources ORDER BY name ASC'));
    }

    /** @return list<UserCustomField> UserCustomField.sorted */
    public function userCustomFields(): array
    {
        return $this->memo['user_custom_fields'] ??= $this->ctx->em->getRepository(UserCustomField::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
    }

    protected function sqlForSpecificField(string $field, string $operator, array $value): ?string
    {
        return match ($field) {
            'admin' => $this->sqlForAdminField($operator, $value),
            'is_member_of_group' => $this->sqlForIsMemberOfGroupField($field, $operator, $value),
            'mail' => $this->sqlForMailField($operator, $value),
            'name' => $this->sqlForNameField($operator, $value),
            default => null,
        };
    }

    private function sqlForAdminField(string $operator, array $value): ?string
    {
        $first = $value[0] ?? null;
        if (null === $first) {
            return null;
        }
        $trueValue = '=' === $operator ? '1' : '0';
        $val = (string) $first === $trueValue ? $this->ctx->quotedTrue() : $this->ctx->quotedFalse();

        return "(users.admin = $val)";
    }

    private function sqlForIsMemberOfGroupField(string $field, string $operator, array $value): string
    {
        if ('*' === $operator || '!*' === $operator) {
            $value = array_map('strval', $this->ctx->conn()->fetchFirstColumn("SELECT id FROM users WHERE type = 'Group'"));
        }
        $e = str_starts_with($operator, '!') ? 'NOT EXISTS' : 'EXISTS';

        return "($e (SELECT 1 FROM groups_users WHERE users.id = groups_users.user_id AND ".$this->sqlForField($field, '=', $value, 'groups_users', 'group_id').'))';
    }

    private function sqlForMailField(string $operator, array $value): string
    {
        $match = true;
        if ('!*' === $operator) {
            $match = false;
            $operator = '*';
        }

        return ($match ? 'EXISTS' : 'NOT EXISTS').' (SELECT 1 FROM email_addresses ea_filter WHERE ea_filter.user_id = users.id AND '
            .$this->sqlForField('mail', $operator, $value, 'ea_filter', 'address').')';
    }

    private function sqlForNameField(string $operator, array $value): string
    {
        switch ($operator) {
            case '*':
                return '1=1';
            case '!*':
                return '1=0';
        }
        $match = !str_starts_with($operator, '!');
        $matchingOperator = (string) preg_replace('/^!/', '', $operator);
        $conditions = [];
        foreach (['login', 'firstname', 'lastname'] as $f) {
            $conditions[] = $this->sqlForField('name', $operator, $value, 'users', $f);
        }
        $conditions[] = ($match ? 'EXISTS' : 'NOT EXISTS').' (SELECT 1 FROM email_addresses ea_name WHERE ea_name.user_id = users.id AND '
            .$this->sqlForField('name', $matchingOperator, $value, 'ea_name', 'address').')';

        return '('.implode($match ? ' OR ' : ' AND ', array_map(static fn ($s) => "($s)", $conditions)).')';
    }

    // ------------------------------------------------------------------------------------------
    // Columns
    // ------------------------------------------------------------------------------------------

    protected function buildAvailableColumns(): array
    {
        $l = $this->ctx->l(...);
        $cols = [
            new QueryColumn('login', $l('field_login'), ['users.login']),
            new QueryColumn('firstname', $l('field_firstname'), ['users.firstname']),
            new QueryColumn('lastname', $l('field_lastname'), ['users.lastname']),
            new QueryColumn('mail', $l('field_mail'), ['email_addresses.address']),
            new QueryColumn('admin', $l('field_admin'), ['users.admin']),
            new QueryColumn('created_on', $l('field_created_on'), ['users.created_on']),
            new QueryColumn('updated_on', $l('field_updated_on'), ['users.updated_on']),
            new QueryColumn('last_login_on', $l('field_last_login_on'), ['users.last_login_on']),
            new QueryColumn('passwd_changed_on', $l('field_passwd_changed_on'), ['users.passwd_changed_on']),
            new QueryColumn('status', $l('field_status'), ['users.status']),
            new QueryColumn('auth_source.name', $l('field_auth_source'), ['auth_sources.name'], kind: QueryColumn::ASSOCIATION, association: 'auth_source', attribute: 'name'),
        ];
        if ($this->twofaEnabled()) {
            $cols[] = new QueryColumn('twofa_scheme', $l('field_twofa_scheme'), ['users.twofa_scheme']);
        }
        foreach ($this->userCustomFields() as $cf) {
            if ($this->isCustomFieldVisible($cf)) {
                $cols[] = $this->customFieldColumn($cf);
            }
        }

        return $cols;
    }

    /** QueryCustomFieldColumn for a user custom field */
    private function customFieldColumn(CustomField $cf): QueryColumn
    {
        $alias = 'cf_'.$cf->id;
        $sortable = null;
        $group = null;
        $join = "LEFT OUTER JOIN custom_values $alias ON $alias.customized_type = 'Principal' AND $alias.customized_id = users.id AND $alias.custom_field_id = {$cf->id}"
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
        if (str_starts_with($this->ctx->settings->string('user_format'), 'lastname')) {
            return ['login', 'lastname', 'firstname', 'mail', 'admin', 'created_on', 'last_login_on'];
        }

        return ['login', 'firstname', 'lastname', 'mail', 'admin', 'created_on', 'last_login_on'];
    }

    public function defaultSortCriteria(): array
    {
        return [['login', 'asc']];
    }

    protected function joinsForOrderStatement(string $orderOptions): string
    {
        $joins = [parent::joinsForOrderStatement($orderOptions)];
        if (str_contains($orderOptions, 'auth_source')) {
            $joins[] = 'LEFT OUTER JOIN auth_sources ON auth_sources.id = users.auth_source_id';
        }

        return implode(' ', array_filter($joins));
    }

    // ------------------------------------------------------------------------------------------
    // Results
    // ------------------------------------------------------------------------------------------

    /** @return list<string> results_scope order */
    private function orderOption(): array
    {
        $order = array_values(array_filter(array_merge($this->groupBySortOrder(), $this->sortClause())));
        $order[] = 'users.id ASC';

        return $order;
    }

    public function userCount(): int
    {
        return $this->count();
    }

    /** @return list<User> */
    public function results(?int $offset = null, ?int $limit = null): array
    {
        $order = $this->orderOption();
        $joins = $this->joinsForOrderStatement(implode(',', $order));
        $rows = $this->fetch('users.id AS id, email_addresses.address AS mail', $joins, null, $order, $limit, null === $limit ? null : ($offset ?? 0));
        foreach ($rows as $r) {
            $this->mails[(int) $r['id']] = null !== $r['mail'] ? (string) $r['mail'] : null;
        }
        $ids = array_values(array_unique(array_map(static fn ($r) => (int) $r['id'], $rows)));
        if ([] === $ids) {
            return [];
        }
        $byId = [];
        foreach ($this->ctx->em->createQuery('SELECT u FROM App\Entity\User u WHERE u.id IN (:ids)')->setParameter('ids', $ids)->getResult() as $u) {
            $byId[$u->id] = $u;
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }
        if ($this->hasCustomFieldColumn() || $this->isGrouped()) {
            $this->customValues += $this->ctx->customFields->valuesFor('Principal', $ids);
        }

        return $out;
    }

    /** User#mail (default email address) */
    public function mailOf(User $user): ?string
    {
        return \array_key_exists((int) $user->id, $this->mails) ? $this->mails[(int) $user->id] : $user->getMail();
    }

    public function authSourceName(User $user): ?string
    {
        if (null === $user->authSourceId) {
            return null;
        }
        if (null === $this->authSourceNames) {
            $this->authSourceNames = [];
            foreach ($this->ctx->conn()->fetchAllAssociative('SELECT id, name FROM auth_sources') as $r) {
                $this->authSourceNames[(int) $r['id']] = (string) $r['name'];
            }
        }

        return $this->authSourceNames[$user->authSourceId] ?? null;
    }

    /**
     * Raw value of a column for a user (QueryColumn#value_object). Custom field columns return
     * ['custom_field' => CustomField, 'value' => mixed].
     */
    public function columnValue(QueryColumn $column, User $user): mixed
    {
        if (QueryColumn::CUSTOM_FIELD === $column->kind && null !== $column->customField) {
            $id = (int) $user->id;
            if (!isset($this->customValues[$id])) {
                $this->customValues[$id] = $this->ctx->customFields->values('Principal', $id);
            }
            return ['custom_field' => $column->customField, 'value' => $this->customValues[$id][$column->customField->id] ?? null];
        }

        return match ($column->name) {
            'login' => $user->login,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'mail' => $this->mailOf($user),
            'admin' => $user->admin,
            'created_on' => $user->createdOn,
            'updated_on' => $user->updatedOn,
            'last_login_on' => $user->lastLoginOn,
            'passwd_changed_on' => $user->passwdChangedOn,
            'status' => $user->status,
            'auth_source.name' => $this->authSourceName($user),
            'twofa_scheme' => $user->twofaScheme,
            default => null,
        };
    }

    // ------------------------------------------------------------------------------------------
    // Grouping & totals (custom field columns only)
    // ------------------------------------------------------------------------------------------

    public function groupKey(User $user): string
    {
        $column = $this->groupByColumn();
        if (null === $column) {
            return '';
        }
        $v = $this->columnValue($column, $user);
        $v = \is_array($v) ? $v['value'] : $v;
        if (\is_array($v)) {
            $v = $v[0] ?? null;
        }

        return self::normalizeGroupKey($v);
    }

    private static function normalizeGroupKey(mixed $k): string
    {
        if (null === $k) {
            return '';
        }
        $s = (string) $k;
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
        $rows = $this->fetch("$st AS g, COUNT(DISTINCT users.id) AS c", $this->joinsForOrderStatement($st), $st);
        $out = [];
        foreach ($rows as $r) {
            $k = self::normalizeGroupKey($r['g']);
            $out[$k] = ($out[$k] ?? 0) + (int) $r['c'];
        }

        return $out;
    }

    /** @return list<array{0: QueryColumn, 1: float|int}> */
    public function totals(): array
    {
        return array_map(fn (QueryColumn $c) => [$c, $this->totalWith($c, null)[''] ?? 0], $this->totalableColumns());
    }

    /** @return array<string, float|int> */
    public function totalByGroupFor(QueryColumn $column): array
    {
        $g = $this->groupByColumn();

        return null === $g ? [] : $this->totalWith($column, $g);
    }

    /** @return array<string, float|int> */
    private function totalWith(QueryColumn $column, ?QueryColumn $group): array
    {
        if (!$column->isCustomField() || null === $column->customField) {
            return [];
        }
        $groupSql = $group?->groupStatement;
        $select = null !== $groupSql ? "$groupSql AS g, " : "'' AS g, ";
        $joins = null !== $groupSql ? $this->joinsForOrderStatement($groupSql) : '';
        $cfId = (int) $column->customField->id;
        $joins .= " INNER JOIN custom_values cv_total ON cv_total.customized_type = 'Principal' AND cv_total.customized_id = users.id AND cv_total.custom_field_id = $cfId AND cv_total.value <> ''";
        $rows = $this->fetch($select.'SUM(CAST(cv_total.value AS decimal(30,3))) AS t', $joins, $groupSql);
        $cast = 'int' === $column->customField->fieldFormat ? static fn ($t) => (int) $t : static fn ($t) => round((float) $t, 2);
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
}

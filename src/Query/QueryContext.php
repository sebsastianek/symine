<?php

declare(strict_types=1);

namespace App\Query;

use App\Entity\Role;
use App\Entity\User;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Service\ProjectService;
use App\Service\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Dependencies and SQL helpers shared by all queries (IssueQuery, TimeEntryQuery, ...).
 * Queries build raw SQL against Redmine's table names, so conditions can be ported 1:1 from Redmine.
 */
final class QueryContext
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(
        public readonly EntityManagerInterface $em,
        public readonly Authorization $auth,
        public readonly CurrentUser $currentUser,
        public readonly Settings $settings,
        public readonly Localizer $i18n,
        public readonly ProjectService $projects,
        public readonly IssueService $issues,
        public readonly CustomFieldService $customFields,
        public readonly ?\App\Service\Activity\SearchService $search = null,
        public readonly ?\App\Doctrine\BatchLoader $batch = null,
    ) {
    }

    public function conn(): Connection
    {
        return $this->em->getConnection();
    }

    public function user(): User
    {
        return $this->currentUser->get();
    }

    /** @param array<string, mixed> $params */
    public function l(string $key, array $params = []): string
    {
        return $this->i18n->l($key, $params);
    }

    public function quote(string|int|float|null $value): string
    {
        return $this->conn()->quote((string) $value);
    }

    /** @param list<string|int> $values */
    public function quoteList(array $values): string
    {
        return implode(',', array_map(fn ($v) => $this->quote((string) $v), $values));
    }

    public function isSqlite(): bool
    {
        return $this->conn()->getDatabasePlatform() instanceof SQLitePlatform;
    }

    public function isMysql(): bool
    {
        return $this->conn()->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    public function isPostgres(): bool
    {
        return $this->conn()->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    public function quotedTrue(): string
    {
        return $this->isPostgres() ? 'TRUE' : '1';
    }

    public function quotedFalse(): string
    {
        return $this->isPostgres() ? 'FALSE' : '0';
    }

    /** Redmine::Database.like */
    public function like(string $left, string $right, bool $match = true): string
    {
        $neg = $match ? '' : 'NOT ';
        if ($this->isPostgres()) {
            return "$left {$neg}ILIKE $right";
        }
        if ($this->isMysql()) {
            return "$left {$neg}LIKE $right";
        }

        return "$left {$neg}LIKE $right ESCAPE '\\'";
    }

    /** Redmine::Database.timestamp_to_date: null when not supported (SQLite) */
    public function timestampToDate(string $column): ?string
    {
        $tz = $this->i18n->userTimezone()->getName();
        if ($this->isPostgres()) {
            return "($column::timestamptz AT TIME ZONE '".str_replace("'", '', $tz)."')::date";
        }
        if ($this->isMysql()) {
            return "DATE(CONVERT_TZ($column,'UTC', '".str_replace("'", '', $tz)."'))";
        }

        return null;
    }

    /**
     * Converts a DQL condition on an entity alias to SQL on the given table name/alias.
     * Used to reuse DQL visibility conditions (e.g. IssueService::visibleCondition) in raw SQL queries.
     */
    public function dqlConditionToSql(string $entityClass, string $dqlAlias, string $dqlCondition, string $sqlAlias): string
    {
        $key = $entityClass.'|'.$dqlCondition.'|'.$sqlAlias;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        if (preg_match('/^\s*\(?\s*1\s*=\s*[01]\s*\)?\s*$/', $dqlCondition)) {
            return $this->cache[$key] = trim($dqlCondition);
        }
        $sql = $this->em->createQuery("SELECT $dqlAlias.id FROM $entityClass $dqlAlias WHERE $dqlCondition")->getSQL();
        if (\is_array($sql)) {
            $sql = $sql[0];
        }
        if (!preg_match('/ FROM (\w+) (\w+)\b/', $sql, $m)) {
            throw new \LogicException('Cannot convert DQL condition: '.$sql);
        }
        $alias = $m[2];
        $where = substr($sql, strpos($sql, ' WHERE ') + 7);
        $where = preg_replace('/\b'.preg_quote($alias, '/').'\./', $sqlAlias.'.', $where) ?? $where;

        return $this->cache[$key] = '('.$where.')';
    }

    /** Issue.visible_condition(user) as SQL on the "issues" table (or the given alias) */
    public function issueVisibleSql(?User $user = null, string $alias = 'issues', bool $skipPreCondition = false): string
    {
        $dql = $this->issues->visibleCondition($user ?? $this->user(), 'i', $skipPreCondition);

        return $this->dqlConditionToSql('App\Entity\Issue', 'i', $dql, $alias);
    }

    /** TimeEntry.visible_condition(user) as SQL ("time_entries" table) */
    public function timeEntryVisibleSql(?User $user = null, string $table = 'time_entries', string $projectExpr = 'time_entries.project_id'): string
    {
        $user ??= $this->user();

        return $this->auth->allowedToCondition('view_time_entries', $projectExpr, $user, static function (Role $role, User $u) use ($table) {
            if ('all' === $role->timeEntriesVisibility) {
                return null;
            }
            if ('own' === $role->timeEntriesVisibility && $u->id && $u->isLogged()) {
                return "$table.user_id = ".(int) $u->id;
            }

            return '1=0';
        });
    }

    /** Journal.visible_notes_condition(user, skip_pre_condition: true) */
    public function journalVisibleNotesSql(string $table = 'journals', string $projectExpr = 'issues.project_id', ?User $user = null): string
    {
        $user ??= $this->user();
        $private = $this->auth->allowedToCondition('view_private_notes', $projectExpr, $user, null, false, true);

        return "($table.private_notes = ".$this->quotedFalse()." OR $table.user_id = ".(int) $user->id." OR ($private))";
    }

    /** SQL fragment ordering users by the configured name format (User.fields_for_order_statement) */
    public function userOrderFields(string $table = 'users'): array
    {
        $format = $this->settings->string('user_format') ?: 'firstname_lastname';
        $fields = match ($format) {
            'firstname' => ['firstname', 'id'],
            'lastname_firstname', 'lastnamefirstname', 'lastname_comma_firstname' => ['lastname', 'firstname', 'id'],
            'lastname' => ['lastname', 'id'],
            'username' => ['login', 'id'],
            default => ['firstname', 'lastname', 'id'],
        };

        return array_map(static fn ($f) => "$table.$f", $fields);
    }

    /** Version.fields_for_order_statement */
    public static function versionOrderFields(string $table = 'versions'): array
    {
        return ["(CASE WHEN $table.effective_date IS NULL THEN 1 ELSE 0 END)", "$table.effective_date", "$table.name", "$table.id"];
    }
}

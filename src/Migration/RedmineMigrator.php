<?php

declare(strict_types=1);

namespace App\Migration;

use App\Doctrine\RedmineSerializedType;
use App\Redmine\RubyYaml;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Copies a Redmine database (MySQL/MariaDB, PostgreSQL, SQLite, SQL Server - any DBAL driver) into the
 * Symine database (SQLite, PostgreSQL or MySQL/MariaDB). Symine uses the very same physical schema,
 * so rows are copied 1:1 with their ids. Values are normalised for the target engine:
 *  - Ruby-serialized (YAML) columns are converted to JSON,
 *  - booleans ('t'/'f', 1/0, true/false) are normalised,
 *  - datetimes are truncated to seconds (Redmine >= 7 stores microseconds),
 *  - binary data (wiki_content_versions.data, possibly zlib/"gzip" compressed) is copied byte for byte,
 *  - NOT NULL columns missing in older Redmine versions (or holding legacy NULLs) get a neutral default,
 *  - Redmine < 3.0 "users.mail" is converted into email_addresses rows.
 *
 * Rows are streamed with keyset pagination (WHERE id > ? ORDER BY id LIMIT n) and written with
 * multi-row INSERTs on a dedicated connection without query logging, so memory stays bounded
 * whatever the size of the tables.
 */
final class RedmineMigrator
{
    /** Columns holding Rails-serialized YAML in Redmine */
    public const array SERIALIZED_COLUMNS = [
        'roles' => ['permissions', 'settings'],
        'settings' => ['value'],
        'custom_fields' => ['possible_values', 'format_store'],
        'queries' => ['filters', 'column_names', 'sort_criteria', 'options'],
        'user_preferences' => ['others'],
        'repositories' => ['extra_info'],
        'imports' => ['settings'],
        'webhooks' => ['events'],
    ];

    /** Columns encrypted by Redmine::Ciphering when database_cipher_key is set in configuration.yml */
    public const array CIPHERED_COLUMNS = [
        'auth_sources' => ['account_password'],
        'repositories' => ['password'],
        'users' => ['twofa_totp_key'],
    ];

    /** STI tables: rows with an unknown "type" (e.g. from plugins) cannot be loaded by Symine */
    public const array STI_TYPES = [
        'users' => ['User', 'AnonymousUser', 'Group', 'GroupAnonymous', 'GroupNonMember'],
        'enumerations' => ['IssuePriority', 'TimeEntryActivity', 'DocumentCategory'],
        'workflows' => ['WorkflowTransition', 'WorkflowPermission'],
        'queries' => ['IssueQuery', 'ProjectQuery', 'TimeEntryQuery', 'UserQuery', 'ProjectAdminQuery'],
    ];

    /** Copy order (no FKs in the schema, but keeps output readable and parents first) */
    public const array TABLE_ORDER = [
        'settings', 'users', 'email_addresses', 'user_preferences', 'groups_users', 'tokens', 'auth_sources',
        'roles', 'roles_managed_roles', 'trackers', 'issue_statuses', 'enumerations', 'workflows',
        'custom_fields', 'custom_field_enumerations', 'custom_fields_roles', 'custom_fields_trackers',
        'projects', 'projects_trackers', 'custom_fields_projects', 'enabled_modules', 'members', 'member_roles',
        'versions', 'issue_categories', 'issues', 'issue_relations', 'journals', 'journal_details', 'watchers',
        'custom_values', 'attachments', 'time_entries', 'news', 'comments', 'documents',
        'wikis', 'wiki_pages', 'wiki_contents', 'wiki_content_versions', 'wiki_redirects', 'boards', 'messages',
        'repositories', 'changesets', 'changes', 'changeset_parents', 'changesets_issues',
        'queries', 'queries_roles', 'imports', 'import_items', 'reactions', 'webhooks', 'projects_webhooks',
        'oauth_applications', 'oauth_access_grants', 'oauth_access_tokens',
    ];

    /** Rails bookkeeping tables, never migrated, never reported */
    public const array INTERNAL_TABLES = ['schema_migrations', 'ar_internal_metadata', 'sqlite_sequence'];

    /** Redmine core tables Symine has no equivalent for (reported as notices) */
    public const array UNSUPPORTED_CORE_TABLES = [
        'open_id_authentication_associations' => 'OpenID tables (Redmine < 4) are not migrated.',
        'open_id_authentication_nonces' => 'OpenID tables (Redmine < 4) are not migrated.',
    ];

    /** @var callable(string): void */
    private $log;
    private ?Schema $targetSchema = null;
    private ?string $cipherKey = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
        $this->log = static function (string $m): void {};
    }

    /** @param callable(string): void $log */
    public function setLogger(callable $log): void
    {
        $this->log = $log;
    }

    /**
     * Redmine's "database_cipher_key" (config/configuration.yml). When given, values encrypted by
     * Redmine::Ciphering ("aes-256-cbc:...") are decrypted during the migration (Symine stores them as-is).
     */
    public function setCipherKey(?string $key): void
    {
        $this->cipherKey = null === $key || '' === $key ? null : $key;
    }

    /** Port of Redmine::Ciphering.decrypt_text; returns null when the value cannot be decrypted */
    public static function decryptRedmineText(string $value, string $key): ?string
    {
        if (!preg_match('/\Aaes-256-cbc:(.+)\z/s', $value, $m)) {
            return $value;
        }
        $parts = explode('--', $m[1]);
        if (2 !== \count($parts)) {
            return null;
        }
        [$e, $iv] = array_map(static fn ($p) => base64_decode($p, false), $parts);
        $clear = openssl_decrypt((string) $e, 'aes-256-cbc', substr(hash('sha256', $key), 0, 32), \OPENSSL_RAW_DATA, (string) $iv);

        return false === $clear ? null : $clear;
    }

    public static function connect(string $url): Connection
    {
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'mysql2' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite', 'sqlite3' => 'pdo_sqlite', 'sqlserver' => 'pdo_sqlsrv']))->parse($url);
        if ('pdo_mysql' === ($params['driver'] ?? null)) {
            $params['charset'] ??= 'utf8mb4';
        }
        if ('pdo_pgsql' === ($params['driver'] ?? null)) {
            $params['charset'] ??= 'utf8';
        }

        return DriverManager::getConnection($params);
    }

    /**
     * Parses a comma separated --only option and validates the table names.
     *
     * @return list<string>|null
     */
    public static function parseOnly(?string $only): ?array
    {
        if (null === $only || '' === trim($only)) {
            return null;
        }
        $tables = array_values(array_unique(array_filter(array_map(static fn ($t) => strtolower(trim($t)), explode(',', $only)))));
        $unknown = array_diff($tables, self::TABLE_ORDER);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('Unknown table(s): %s. Migratable tables: %s', implode(', ', $unknown), implode(', ', self::TABLE_ORDER)));
        }

        // keep the canonical order
        return array_values(array_intersect(self::TABLE_ORDER, $tables));
    }

    /**
     * @param list<string>|null $only restrict the migration to these tables (others are left untouched)
     *
     * @return array{tables: array<string, array{source: int, copied: int, skipped: int}>, warnings: list<string>, version: ?string}
     */
    public function migrate(Connection $source, bool $purge = true, int $batchSize = 500, ?array $only = null): array
    {
        $batchSize = max(1, $batchSize);
        $warnings = [];
        $report = [];

        $sourceTables = self::tableNames($source);
        $this->ensureSchema($purge);
        $target = $this->bulkConnection();
        $targetTables = self::tableNames($target);

        $version = $this->sourceVersion($source);
        ($this->log)(\sprintf('Source Redmine schema version: %s', $version ?? 'unknown'));
        array_push($warnings, ...$this->tableNotices($sourceTables));

        $tables = $only ?? self::TABLE_ORDER;
        $isSqlite = $target->getDatabasePlatform() instanceof SQLitePlatform;
        $isMysql = $target->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        if ($isSqlite) {
            $target->executeStatement('PRAGMA foreign_keys = OFF');
        }
        if ($isMysql) {
            $target->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        }

        $target->beginTransaction();
        try {
            foreach ($tables as $table) {
                if (!\in_array($table, $targetTables, true) || !$this->targetSchema()->hasTable($table)) {
                    continue;
                }
                $target->executeStatement('DELETE FROM '.$target->quoteSingleIdentifier($table));
                if (!\in_array($table, $sourceTables, true)) {
                    if ('email_addresses' === $table && \in_array('users', $sourceTables, true) && $this->sourceHasColumn($source, 'users', 'mail')) {
                        $report[$table] = $this->synthesizeEmailAddresses($source, $target, $batchSize, $warnings);
                        ($this->log)(\sprintf('%-28s %8d rows (from users.mail)', $table, $report[$table]['copied']));
                        continue;
                    }
                    $report[$table] = ['source' => 0, 'copied' => 0, 'skipped' => 0];
                    $warnings[] = "Table \"$table\" does not exist in the source database (older Redmine version?): left empty.";
                    continue;
                }
                $report[$table] = $this->copyTable($source, $target, $table, $batchSize, $warnings);
                ($this->log)(\sprintf('%-28s %8d rows', $table, $report[$table]['copied']));
            }
            $warnings = [...$warnings, ...$this->fixIntegrity($target)];
            $warnings = [...$warnings, ...$this->applyDataMigrations($target, $version)];
            $target->commit();
        } catch (\Throwable $e) {
            if ($target->isTransactionActive()) {
                $target->rollBack();
            }
            throw $e;
        } finally {
            if ($isMysql) {
                $target->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
        $this->resetSequences($target, array_values(array_intersect($tables, $targetTables)));
        if ($target !== $this->em->getConnection()) {
            $target->close();
        }
        $this->em->clear();

        return ['tables' => $report, 'warnings' => $warnings, 'version' => $version];
    }

    /**
     * Validates a source database without writing anything: tables and row counts, plugin tables,
     * unsupported STI types, undecodable YAML, columns missing/ignored and dangling references.
     *
     * @param list<string>|null $only
     *
     * @return array{tables: array<string, array{source: int, skipped: int, yaml_errors: int}>, warnings: list<string>, errors: list<string>, version: ?string}
     */
    public function dryRun(Connection $source, int $batchSize = 500, ?array $only = null): array
    {
        $batchSize = max(1, $batchSize);
        $warnings = [];
        $errors = [];
        $report = [];
        $sourceTables = self::tableNames($source);
        $version = $this->sourceVersion($source);
        array_push($warnings, ...$this->tableNotices($sourceTables));
        $schema = $this->targetSchema();

        foreach ($only ?? self::TABLE_ORDER as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }
            if (!\in_array($table, $sourceTables, true)) {
                if ('email_addresses' === $table && \in_array('users', $sourceTables, true) && $this->sourceHasColumn($source, 'users', 'mail')) {
                    $warnings[] = 'email_addresses will be created from users.mail (Redmine < 3.0).';
                } else {
                    $warnings[] = "Table \"$table\" does not exist in the source database (older Redmine version?): it will be left empty.";
                }
                $report[$table] = ['source' => 0, 'skipped' => 0, 'yaml_errors' => 0];
                continue;
            }
            [$columns, $missing, $extra] = $this->columnPlan($source, $table);
            foreach ($extra as $c) {
                $warnings[] = "Column $table.$c does not exist in Symine and will be ignored.";
            }
            foreach ($missing as $c => $default) {
                $warnings[] = "Column $table.$c is missing in the source (older Redmine version?): ".(null === $default ? 'database default will be used.' : 'filled with '.var_export($default, true).'.');
            }
            $count = (int) $source->fetchOne('SELECT COUNT(*) FROM '.$source->quoteSingleIdentifier($table));
            $skipped = 0;
            if (isset(self::STI_TYPES[$table]) && \in_array('type', $columns, true)) {
                foreach ($source->fetchAllAssociative('SELECT '.$source->quoteSingleIdentifier('type').' AS t, COUNT(*) AS n FROM '.$source->quoteSingleIdentifier($table).' GROUP BY '.$source->quoteSingleIdentifier('type')) as $r) {
                    if (!\in_array($r['t'], self::STI_TYPES[$table], true)) {
                        $skipped += (int) $r['n'];
                        $warnings[] = \sprintf('%d row(s) of %s have unsupported type "%s" (plugin?) and will be skipped.', $r['n'], $table, $r['t']);
                    }
                }
            }
            if ('custom_fields' === $table) {
                foreach ($source->fetchAllAssociative('SELECT type AS t, COUNT(*) AS n FROM custom_fields GROUP BY type') as $r) {
                    if (!\array_key_exists((string) $r['t'], \App\Entity\CustomField::TYPES)) {
                        $skipped += (int) $r['n'];
                        $warnings[] = \sprintf('%d custom field(s) of unsupported type "%s" will be skipped.', $r['n'], $r['t']);
                    }
                }
            }
            $yamlErrors = 0;
            $serialized = array_values(array_intersect(self::SERIALIZED_COLUMNS[$table] ?? [], $columns));
            if ([] !== $serialized) {
                $cols = \in_array('id', $columns, true) ? ['id', ...$serialized] : $serialized;
                foreach ($this->streamRows($source, $table, $cols, \in_array('id', $columns, true), $batchSize) as $row) {
                    foreach ($serialized as $col) {
                        $v = self::scalar($row[$col]);
                        if (null === $v || !RubyYaml::isYaml($v)) {
                            continue;
                        }
                        try {
                            RubyYaml::decode($v);
                        } catch (\Throwable $e) {
                            ++$yamlErrors;
                            if ($yamlErrors <= 20) {
                                $errors[] = \sprintf('%s.%s of #%s cannot be decoded: %s', $table, $col, $row['id'] ?? '?', $e->getMessage());
                            }
                        }
                    }
                }
            }
            $report[$table] = ['source' => $count, 'skipped' => $skipped, 'yaml_errors' => $yamlErrors];
            ($this->log)(\sprintf('%-28s %8d rows%s', $table, $count, $yamlErrors ? " ($yamlErrors YAML error(s))" : ''));
        }
        array_push($warnings, ...$this->danglingReferences($source, $sourceTables));

        return ['tables' => $report, 'warnings' => $warnings, 'errors' => $errors, 'version' => $version];
    }

    // ------------------------------------------------------------------------------------------

    private function ensureSchema(bool $update): void
    {
        if ($update) {
            $tool = new SchemaTool($this->em);
            $tool->updateSchema($this->em->getMetadataFactory()->getAllMetadata());
        }
    }

    /** Schema expected by Symine (from the ORM mapping): column types, nullability, defaults */
    private function targetSchema(): Schema
    {
        return $this->targetSchema ??= (new SchemaTool($this->em))->getSchemaFromMetadata($this->em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * A connection to the target database without the profiling/logging middlewares of the
     * application (which would keep every executed query in memory during big imports).
     */
    private function bulkConnection(): Connection
    {
        $conn = $this->em->getConnection();
        $params = $conn->getParams();
        if (isset($params['memory']) && $params['memory']) {
            return $conn; // in-memory SQLite: must stay on the same connection
        }
        try {
            $bulk = DriverManager::getConnection($params);
            $bulk->getNativeConnection();

            return $bulk;
        } catch (\Throwable) {
            return $conn;
        }
    }

    /** @return list<string> lower-cased table names */
    private static function tableNames(Connection $conn): array
    {
        return array_map('strtolower', $conn->createSchemaManager()->listTableNames());
    }

    /** @param list<string> $sourceTables @return list<string> */
    private function tableNotices(array $sourceTables): array
    {
        $out = [];
        foreach (array_diff($sourceTables, self::TABLE_ORDER, self::INTERNAL_TABLES) as $t) {
            $out[] = self::UNSUPPORTED_CORE_TABLES[$t] ?? "Table \"$t\" is not part of Redmine core (plugin?) and is not migrated.";
        }

        return array_values(array_unique($out));
    }

    public function sourceVersion(Connection $source): ?string
    {
        try {
            // versions are strings ("001" ... "20250101000000"): the longest/greatest is the latest
            $v = $source->fetchOne('SELECT version FROM schema_migrations ORDER BY LENGTH(version) DESC, version DESC');

            return false === $v || null === $v ? null : (string) $v;
        } catch (\Throwable) {
            return null;
        }
    }

    private function sourceHasColumn(Connection $source, string $table, string $column): bool
    {
        foreach ($source->createSchemaManager()->introspectTableColumnsByUnquotedName($table) as $c) {
            if (strtolower($c->getName()) === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: list<string>, 1: array<string, mixed>, 2: list<string>} copied columns, target
     *                                                                          columns missing in the source (=> forced value or null = DB default), ignored source columns
     */
    private function columnPlan(Connection $source, string $table): array
    {
        $targetColumns = $this->targetColumns($table);
        $sourceColumns = array_map(static fn ($c) => strtolower($c->getName()), $source->createSchemaManager()->introspectTableColumnsByUnquotedName($table));
        $columns = array_values(array_filter(array_keys($targetColumns), static fn ($c) => \in_array($c, $sourceColumns, true)));
        $missing = [];
        foreach ($targetColumns as $name => $col) {
            if (\in_array($name, $sourceColumns, true) || $col->getAutoincrement()) {
                continue;
            }
            $missing[$name] = $col->getNotnull() && null === $col->getDefault() ? self::neutralValue($col) : null;
        }
        $extra = array_values(array_diff($sourceColumns, $columns));

        return [$columns, $missing, $extra];
    }

    /** @return array<string, Column> */
    private function targetColumns(string $table): array
    {
        $out = [];
        foreach ($this->targetSchema()->getTable($table)->getColumns() as $col) {
            $out[strtolower(trim($col->getName(), '`"'))] = $col;
        }

        return $out;
    }

    /** Value used for a NOT NULL column without default when the source has none */
    private static function neutralValue(Column $col): mixed
    {
        $type = $col->getType();

        return match (true) {
            $type instanceof Types\BooleanType => false,
            $type instanceof Types\IntegerType, $type instanceof Types\SmallIntType, $type instanceof Types\BigIntType => 0,
            $type instanceof Types\FloatType, $type instanceof Types\DecimalType => 0,
            $type instanceof Types\DateType, $type instanceof Types\DateImmutableType => gmdate('Y-m-d'),
            $type instanceof Types\DateTimeType, $type instanceof Types\DateTimeImmutableType,
            $type instanceof Types\DateTimeTzType, $type instanceof Types\DateTimeTzImmutableType => gmdate('Y-m-d H:i:s'),
            default => '',
        };
    }

    /**
     * Streams rows (keyset pagination when the table has an id, LIMIT/OFFSET otherwise).
     *
     * @param list<string> $columns
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function streamRows(Connection $source, string $table, array $columns, bool $hasId, int $batchSize): \Generator
    {
        $select = implode(', ', array_map($source->quoteSingleIdentifier(...), $columns));
        $platform = $source->getDatabasePlatform();
        $lastId = null;
        $offset = 0;
        $order = $hasId ? '' : ' ORDER BY '.implode(', ', array_map($source->quoteSingleIdentifier(...), $columns));
        while (true) {
            if ($hasId) {
                $sql = "SELECT $select FROM ".$source->quoteSingleIdentifier($table).(null === $lastId ? '' : ' WHERE id > ?').' ORDER BY id';
                $rows = $source->fetchAllAssociative($platform->modifyLimitQuery($sql, $batchSize), null === $lastId ? [] : [$lastId]);
            } else {
                $rows = $source->fetchAllAssociative($platform->modifyLimitQuery("SELECT $select FROM ".$source->quoteSingleIdentifier($table).$order, $batchSize, $offset));
                $offset += $batchSize;
            }
            if ([] === $rows) {
                return;
            }
            foreach ($rows as $row) {
                $row = array_change_key_case($row, \CASE_LOWER);
                if ($hasId) {
                    $lastId = (int) $row['id'];
                }
                yield $row;
            }
            $n = \count($rows);
            unset($rows);
            if ($n < $batchSize) {
                return;
            }
        }
    }

    /**
     * @param list<string> $warnings
     *
     * @return array{source: int, copied: int, skipped: int}
     */
    private function copyTable(Connection $source, Connection $target, string $table, int $batchSize, array &$warnings): array
    {
        [$columns, $missing, $extra] = $this->columnPlan($source, $table);
        foreach ($extra as $c) {
            $warnings[] = "Column $table.$c does not exist in Symine and was ignored.";
        }
        $forced = array_filter($missing, static fn ($v) => null !== $v);
        foreach ($forced as $c => $v) {
            $warnings[] = "Column $table.$c is missing in the source (older Redmine version?): filled with ".var_export($v, true).'.';
        }
        $targetColumns = $this->targetColumns($table);
        $insertColumns = [...$columns, ...array_keys($forced)];
        $serialized = self::SERIALIZED_COLUMNS[$table] ?? [];
        $stiTypes = self::STI_TYPES[$table] ?? null;
        $hasId = \in_array('id', $columns, true);
        $total = (int) $source->fetchOne('SELECT COUNT(*) FROM '.$source->quoteSingleIdentifier($table));
        $copied = 0;
        $skipped = 0;
        $skippedTypes = [];
        $nullFixes = [];
        $encodingFixes = [];
        $ciphered = self::CIPHERED_COLUMNS[$table] ?? [];
        $cipherStats = ['decrypted' => 0, 'kept' => 0, 'failed' => 0];
        $isPg = $target->getDatabasePlatform() instanceof PostgreSQLPlatform;

        $kinds = [];
        foreach ($insertColumns as $col) {
            $kinds[$col] = self::kindOf($targetColumns[$col]);
        }
        $perInsert = max(1, min(200, intdiv(30000, max(1, \count($insertColumns)))));
        $buffer = [];
        $flush = function () use (&$buffer, $target, $table, $insertColumns, $kinds): void {
            if ([] === $buffer) {
                return;
            }
            $this->insertRows($target, $table, $insertColumns, $kinds, $buffer);
            $buffer = [];
        };

        foreach ($this->streamRows($source, $table, $columns, $hasId, $batchSize) as $row) {
            if (null !== $stiTypes && isset($row['type']) && !\in_array($row['type'], $stiTypes, true)) {
                $skippedTypes[$row['type']] = ($skippedTypes[$row['type']] ?? 0) + 1;
                ++$skipped;
                continue;
            }
            if ('custom_fields' === $table && isset($row['type']) && !\array_key_exists($row['type'], \App\Entity\CustomField::TYPES)) {
                $skippedTypes[$row['type']] = ($skippedTypes[$row['type']] ?? 0) + 1;
                ++$skipped;
                continue;
            }
            $values = [];
            foreach ($columns as $col) {
                $value = $row[$col];
                if (\is_resource($value)) {
                    $value = stream_get_contents($value);
                }
                if (null !== $value && \in_array($col, $serialized, true) && !('settings' === $table && self::isPlainSetting($row['name'] ?? null))) {
                    $value = $this->convertSerialized((string) $value, $table, $col, $row['id'] ?? null, $warnings);
                }
                if (\is_string($value) && \in_array($col, $ciphered, true) && str_starts_with($value, 'aes-256-cbc:')) {
                    if (null === $this->cipherKey) {
                        ++$cipherStats['kept'];
                    } elseif (null !== ($clear = self::decryptRedmineText($value, $this->cipherKey))) {
                        $value = $clear;
                        ++$cipherStats['decrypted'];
                    } else {
                        ++$cipherStats['failed'];
                    }
                }
                $column = $targetColumns[$col];
                $kind = $kinds[$col];
                if (null === $value && $column->getNotnull() && !$column->getAutoincrement()) {
                    $value = null !== $column->getDefault() ? $column->getDefault() : self::neutralValue($column);
                    $nullFixes[$col] = ($nullFixes[$col] ?? 0) + 1;
                }
                $value = self::normalizeValue($value, $kind);
                if ('string' === $kind && \is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                    $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                    $encodingFixes[$col] = ($encodingFixes[$col] ?? 0) + 1;
                }
                if ($isPg && 'string' === $kind && \is_string($value) && str_contains($value, "\0")) {
                    $value = str_replace("\0", '', $value);
                }
                $values[] = $value;
            }
            foreach ($forced as $v) {
                $values[] = $v;
            }
            $buffer[] = $values;
            ++$copied;
            if (\count($buffer) >= $perInsert) {
                $flush();
            }
            if (0 === $copied % 50000) {
                ($this->log)(\sprintf('  ... %s: %d / %d rows', $table, $copied, $total));
            }
        }
        $flush();

        foreach ($skippedTypes as $type => $n) {
            $warnings[] = \sprintf('%d row(s) of %s with unsupported type "%s" (plugin?) were skipped.', $n, $table, $type);
        }
        foreach ($nullFixes as $col => $n) {
            $warnings[] = \sprintf('%d NULL value(s) in NOT NULL column %s.%s replaced by a default value.', $n, $table, $col);
        }
        if ($cipherStats['decrypted']) {
            $warnings[] = \sprintf('%d encrypted value(s) of %s decrypted with the Redmine cipher key.', $cipherStats['decrypted'], $table);
        }
        if ($cipherStats['kept']) {
            $warnings[] = \sprintf('%d value(s) of %s.%s are encrypted by Redmine (database_cipher_key) and were copied as-is: re-run with --cipher-key or re-enter them in Symine.', $cipherStats['kept'], $table, implode('/', $ciphered));
        }
        if ($cipherStats['failed']) {
            $warnings[] = \sprintf('%d encrypted value(s) of %s could not be decrypted (wrong cipher key?) and were copied as-is.', $cipherStats['failed'], $table);
        }
        foreach ($encodingFixes as $col => $n) {
            $warnings[] = \sprintf('%d value(s) of %s.%s were not valid UTF-8 and were converted from Windows-1252.', $n, $table, $col);
        }

        return ['source' => $total, 'copied' => $copied, 'skipped' => $skipped];
    }

    /**
     * @param list<string>               $columns
     * @param array<string, string>      $kinds
     * @param list<list<mixed>>          $rows
     */
    private function insertRows(Connection $target, string $table, array $columns, array $kinds, array $rows): void
    {
        $placeholders = '('.implode(', ', array_fill(0, \count($columns), '?')).')';
        $sql = 'INSERT INTO '.$target->quoteSingleIdentifier($table).' ('.implode(', ', array_map($target->quoteSingleIdentifier(...), $columns)).') VALUES '
            .implode(', ', array_fill(0, \count($rows), $placeholders));
        $stmt = $target->prepare($sql);
        $i = 1;
        $kindList = array_values(array_map(static fn ($c) => $kinds[$c], $columns));
        foreach ($rows as $values) {
            foreach ($values as $k => $value) {
                if (null === $value) {
                    $stmt->bindValue($i++, null, ParameterType::NULL);
                    continue;
                }
                match ($kindList[$k]) {
                    'bool' => $stmt->bindValue($i++, (bool) $value, ParameterType::BOOLEAN),
                    'int' => $stmt->bindValue($i++, (int) $value, ParameterType::INTEGER),
                    'binary' => $stmt->bindValue($i++, $value, ParameterType::LARGE_OBJECT),
                    default => $stmt->bindValue($i++, \is_bool($value) ? ($value ? '1' : '0') : (string) $value, ParameterType::STRING),
                };
            }
        }
        $stmt->executeStatement();
    }

    private static function kindOf(Column $col): string
    {
        $type = $col->getType();

        return match (true) {
            $type instanceof Types\BooleanType => 'bool',
            $type instanceof Types\IntegerType, $type instanceof Types\SmallIntType, $type instanceof Types\BigIntType => 'int',
            $type instanceof Types\BlobType, $type instanceof Types\BinaryType => 'binary',
            $type instanceof Types\DateType, $type instanceof Types\DateImmutableType => 'date',
            $type instanceof Types\DateTimeType, $type instanceof Types\DateTimeImmutableType,
            $type instanceof Types\DateTimeTzType, $type instanceof Types\DateTimeTzImmutableType => 'datetime',
            $type instanceof Types\FloatType, $type instanceof Types\DecimalType => 'float',
            default => 'string',
        };
    }

    /** Normalises a source value for the given target column kind */
    public static function normalizeValue(mixed $value, string $kind): mixed
    {
        if (null === $value) {
            return null;
        }
        if (\is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return match ($kind) {
            'bool' => \in_array($value, [true, 1, '1', 't', 'true', 'TRUE', 'T', 'y', 'Y'], true),
            'int' => \is_bool($value) ? (int) $value : $value,
            'datetime' => self::normalizeDateTime($value),
            'date' => self::normalizeDate($value),
            'float' => \is_bool($value) ? (int) $value : $value,
            default => \is_bool($value) ? ($value ? '1' : '0') : $value,
        };
    }

    /** "2026-01-02 03:04:05.123456(+00)" / "2026-01-02T03:04:05Z" → "2026-01-02 03:04:05" (UTC) */
    public static function normalizeDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $s = trim((string) $value);
        if ('' === $s) {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.\d+)?\s*(Z|UTC|[+-]\d{2}(?::?\d{2})?)?$/', $s, $m)) {
            if (!isset($m[3]) || '' === $m[3] || \in_array($m[3], ['Z', 'UTC', '+00', '+0000', '+00:00'], true)) {
                return $m[1].' '.$m[2];
            }
            try {
                return (new \DateTimeImmutable($m[1].' '.$m[2].$m[3]))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                return $m[1].' '.$m[2];
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return $s.' 00:00:00';
        }

        return $s;
    }

    public static function normalizeDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $s = trim((string) $value);

        return '' === $s ? null : (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m) ? $m[1] : $s);
    }

    /** Setting#value: settings not declared "serialized" are plain strings, kept as-is even when they start with "---" */
    private static function isPlainSetting(mixed $name): bool
    {
        static $definitions = null;
        $definitions ??= require \dirname(__DIR__, 2).'/config/redmine/settings.php';

        return \is_string($name) && isset($definitions[$name]) && !$definitions[$name]['serialized'];
    }

    /** @param list<string> $warnings */
    private function convertSerialized(string $value, string $table, string $col, mixed $id, array &$warnings): ?string
    {
        if (!RubyYaml::isYaml($value)) {
            return $value;
        }
        try {
            return RedmineSerializedType::encode(RubyYaml::decode($value));
        } catch (\Throwable $e) {
            $warnings[] = \sprintf('Could not decode %s.%s of #%s (%s); value kept as-is.', $table, $col, $id ?? '?', $e->getMessage());

            return $value;
        }
    }

    /**
     * Redmine < 3.0 stored the e-mail address in users.mail.
     *
     * @param list<string> $warnings
     *
     * @return array{source: int, copied: int, skipped: int}
     */
    private function synthesizeEmailAddresses(Connection $source, Connection $target, int $batchSize, array &$warnings): array
    {
        $rows = [];
        $copied = 0;
        $now = gmdate('Y-m-d H:i:s');
        $id = 0;
        $cols = ['id', 'user_id', 'address', 'is_default', 'notify', 'created_on', 'updated_on'];
        $kinds = ['id' => 'int', 'user_id' => 'int', 'address' => 'string', 'is_default' => 'bool', 'notify' => 'bool', 'created_on' => 'datetime', 'updated_on' => 'datetime'];
        foreach ($this->streamRows($source, 'users', ['id', 'mail', 'type'], true, $batchSize) as $u) {
            if ('User' !== $u['type'] || '' === trim((string) $u['mail'])) {
                continue;
            }
            $rows[] = [++$id, (int) $u['id'], trim((string) $u['mail']), true, true, $now, $now];
            ++$copied;
            if (\count($rows) >= 200) {
                $this->insertRows($target, 'email_addresses', $cols, $kinds, $rows);
                $rows = [];
            }
        }
        if ([] !== $rows) {
            $this->insertRows($target, 'email_addresses', $cols, $kinds, $rows);
        }
        $warnings[] = "$copied e-mail address(es) created from users.mail (Redmine < 3.0 schema).";

        return ['source' => $copied, 'copied' => $copied, 'skipped' => 0];
    }

    /**
     * Redmine has no FK constraints; legacy databases may contain dangling references that would
     * break the ORM. Nullable references are cleared, mandatory user references are reassigned to
     * the anonymous user (what Redmine does when deleting users).
     *
     * @return list<string>
     */
    /**
     * Data migrations of Redmine versions newer than the source database, so that behaviour
     * matches Redmine 7 after the import (e.g. CSV export permissions introduced in 7.0).
     *
     * @return list<string>
     */
    private function applyDataMigrations(Connection $conn, ?string $sourceVersion): array
    {
        $warnings = [];
        $before = static fn (string $migration): bool => null === $sourceVersion
            || (\strlen($sourceVersion) === \strlen($migration) ? strcmp($sourceVersion, $migration) < 0 : \strlen($sourceVersion) < \strlen($migration));

        // 20260906000000_add_export_issues_and_export_time_entries_permissions
        if ($before('20260906000000')) {
            $updated = 0;
            foreach ($conn->fetchAllAssociative('SELECT id, permissions FROM roles') as $row) {
                $perms = RedmineSerializedType::decode($row['permissions']);
                $perms = \is_array($perms) ? array_values(array_map('strval', $perms)) : [];
                $new = $perms;
                if (\in_array('view_issues', $perms, true) && !\in_array('export_issues', $perms, true)) {
                    $new[] = 'export_issues';
                }
                if (\in_array('view_time_entries', $perms, true) && !\in_array('export_time_entries', $perms, true)) {
                    $new[] = 'export_time_entries';
                }
                if ($new !== $perms) {
                    $conn->executeStatement('UPDATE roles SET permissions = ? WHERE id = ?', [RedmineSerializedType::encode($new), $row['id']]);
                    ++$updated;
                }
            }
            if ($updated > 0) {
                $warnings[] = "$updated role(s) granted export_issues/export_time_entries (Redmine 7.0 data migration 20260906000000).";
            }
        }

        return $warnings;
    }

    private function fixIntegrity(Connection $conn): array
    {
        $warnings = [];
        $anonymousId = $conn->fetchOne("SELECT id FROM users WHERE type = 'AnonymousUser' ORDER BY id");
        foreach ($this->references() as [$table, $col, $targetTable, $nullable]) {
            $where = "t.$col IS NOT NULL AND t.$col <> 0 AND NOT EXISTS (SELECT 1 FROM $targetTable x WHERE x.id = t.$col)";
            $zeroWhere = "t.$col = 0";
            $count = (int) $conn->fetchOne("SELECT COUNT(*) FROM $table t WHERE $where OR $zeroWhere");
            if (0 === $count) {
                continue;
            }
            // the derived table avoids MySQL's "can't specify target table for update in FROM clause"
            $ids = "SELECT id FROM (SELECT t.id FROM $table t WHERE $where OR $zeroWhere) s";
            if ($nullable) {
                $conn->executeStatement("UPDATE $table SET $col = NULL WHERE id IN ($ids)");
                $warnings[] = "$count dangling reference(s) $table.$col → $targetTable cleared.";
            } elseif ('users' === $targetTable && $anonymousId) {
                $conn->executeStatement("UPDATE $table SET $col = ? WHERE id IN ($ids)", [$anonymousId]);
                $warnings[] = "$count dangling reference(s) $table.$col → users reassigned to Anonymous.";
            } else {
                $warnings[] = "$count dangling reference(s) $table.$col → $targetTable could not be fixed automatically.";
            }
        }

        return $warnings;
    }

    /**
     * Reports (does not fix) dangling references in the source database (dry-run).
     *
     * @param list<string> $sourceTables
     *
     * @return list<string>
     */
    private function danglingReferences(Connection $source, array $sourceTables): array
    {
        $out = [];
        foreach ($this->references() as [$table, $col, $targetTable, $nullable]) {
            if (!\in_array($table, $sourceTables, true) || !\in_array($targetTable, $sourceTables, true) || !$this->sourceHasColumn($source, $table, $col)) {
                continue;
            }
            try {
                $n = (int) $source->fetchOne("SELECT COUNT(*) FROM $table t WHERE t.$col IS NOT NULL AND t.$col <> 0 AND NOT EXISTS (SELECT 1 FROM $targetTable x WHERE x.id = t.$col)");
            } catch (\Throwable) {
                continue;
            }
            if ($n > 0) {
                $out[] = \sprintf('%d dangling reference(s) %s.%s → %s will be %s.', $n, $table, $col, $targetTable, $nullable ? 'cleared' : ('users' === $targetTable ? 'reassigned to Anonymous' : 'kept (cannot be fixed automatically)'));
            }
        }

        return $out;
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: bool}> table, column, referenced table, nullable */
    private function references(): array
    {
        $out = [];
        $done = [];
        /** @var ClassMetadata $meta */
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            foreach ($meta->getAssociationMappings() as $assoc) {
                if (!$assoc->isManyToOne() && !($assoc->isOneToOne() && $assoc->isOwningSide())) {
                    continue;
                }
                $joinColumn = $assoc->joinColumns[0];
                $table = $meta->getTableName();
                $col = $joinColumn->name;
                if (isset($done["$table.$col"])) {
                    continue;
                }
                $done["$table.$col"] = true;
                $out[] = [$table, $col, $this->em->getClassMetadata($assoc->targetEntity)->getTableName(), $joinColumn->nullable ?? true];
            }
        }

        return $out;
    }

    /** @param list<string> $tables */
    private function resetSequences(Connection $conn, array $tables): void
    {
        $platform = $conn->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            foreach ($tables as $t) {
                if (!$this->targetSchema()->hasTable($t) || !$this->targetSchema()->getTable($t)->hasColumn('id')) {
                    continue;
                }
                $seq = $conn->fetchOne('SELECT pg_get_serial_sequence(?, ?)', [$t, 'id']);
                if ($seq) {
                    $conn->executeQuery("SELECT setval('$seq', COALESCE((SELECT MAX(id) FROM ".$conn->quoteSingleIdentifier($t).'), 0) + 1, false)');
                }
            }
        } elseif ($platform instanceof AbstractMySQLPlatform) {
            // InnoDB moves AUTO_INCREMENT forward on explicit inserts, but not backwards after a purge
            foreach ($tables as $t) {
                if (!$this->targetSchema()->hasTable($t) || !$this->targetSchema()->getTable($t)->hasColumn('id')) {
                    continue;
                }
                $next = (int) $conn->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM '.$conn->quoteSingleIdentifier($t));
                $conn->executeStatement('ALTER TABLE '.$conn->quoteSingleIdentifier($t).' AUTO_INCREMENT = '.$next);
            }
        } elseif ($platform instanceof SQLitePlatform) {
            try {
                foreach ($tables as $t) {
                    if ($this->targetSchema()->hasTable($t) && $this->targetSchema()->getTable($t)->hasColumn('id')) {
                        $max = (int) $conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM '.$conn->quoteSingleIdentifier($t));
                        $conn->executeStatement('UPDATE sqlite_sequence SET seq = ? WHERE name = ?', [$max, $t]);
                    }
                }
            } catch (\Throwable) {
                // no AUTOINCREMENT table → no sqlite_sequence
            }
        }
    }

    /**
     * Copies attachment files (Redmine's files/ directory: YYYY/MM/<disk_filename>) and checks digests.
     *
     * @return array{copied: int, missing: int, bad_digest: int}
     */
    public function copyFiles(string $from, string $to, bool $verifyDigest = true): array
    {
        $copied = $missing = $bad = 0;
        foreach ($this->em->getConnection()->iterateAssociative('SELECT id, disk_directory, disk_filename, digest FROM attachments') as $a) {
            if ('' === (string) $a['disk_filename']) {
                continue;
            }
            $rel = ($a['disk_directory'] ? $a['disk_directory'].'/' : '').$a['disk_filename'];
            $src = rtrim($from, '/').'/'.$rel;
            if (!is_file($src)) {
                ++$missing;
                continue;
            }
            $dst = rtrim($to, '/').'/'.$rel;
            if (!is_dir(\dirname($dst))) {
                mkdir(\dirname($dst), 0o775, true);
            }
            if (!is_file($dst) || filesize($dst) !== filesize($src)) {
                copy($src, $dst);
            }
            if ($verifyDigest && $a['digest']) {
                $algo = 64 === \strlen((string) $a['digest']) ? 'sha256' : 'md5';
                if (hash_file($algo, $dst) !== $a['digest']) {
                    ++$bad;
                }
            }
            ++$copied;
        }

        return ['copied' => $copied, 'missing' => $missing, 'bad_digest' => $bad];
    }

    /**
     * @param list<string>|null $only
     *
     * @return array<string, array{source: int, target: int}> row counts per table, for verification
     */
    public function compareCounts(Connection $source, ?array $only = null): array
    {
        $target = $this->em->getConnection();
        $sourceTables = self::tableNames($source);
        $out = [];
        foreach ($only ?? self::TABLE_ORDER as $t) {
            if (!\in_array($t, $sourceTables, true)) {
                continue;
            }
            $out[$t] = [
                'source' => (int) $source->fetchOne('SELECT COUNT(*) FROM '.$source->quoteSingleIdentifier($t)),
                'target' => (int) $target->fetchOne('SELECT COUNT(*) FROM '.$target->quoteSingleIdentifier($t)),
            ];
        }

        return $out;
    }

    private static function scalar(mixed $v): ?string
    {
        if (\is_resource($v)) {
            $v = stream_get_contents($v);
        }

        return null === $v ? null : (string) $v;
    }
}

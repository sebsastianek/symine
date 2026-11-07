<?php

declare(strict_types=1);

namespace App\Migration;

use App\Doctrine\RedmineSerializedType;
use App\Redmine\RubyYaml;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Copies a Redmine database (MySQL/MariaDB, PostgreSQL, SQLite, SQL Server - any DBAL driver) into the
 * Symine database. Symine uses the very same physical schema, so rows are copied 1:1 with their ids;
 * only Ruby-serialized (YAML) columns are converted to JSON and booleans normalised.
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

    /** STI tables: rows with an unknown "type" (e.g. from plugins) cannot be loaded by Symine */
    private const array STI_TYPES = [
        'users' => ['User', 'AnonymousUser', 'Group', 'GroupAnonymous', 'GroupNonMember'],
        'enumerations' => ['IssuePriority', 'TimeEntryActivity', 'DocumentCategory'],
        'workflows' => ['WorkflowTransition', 'WorkflowPermission'],
        'queries' => ['IssueQuery', 'ProjectQuery', 'TimeEntryQuery', 'UserQuery', 'ProjectAdminQuery'],
    ];

    /** Copy order (no FKs in the schema, but keeps output readable and parents first) */
    private const array TABLE_ORDER = [
        'settings', 'users', 'email_addresses', 'user_preferences', 'groups_users', 'tokens', 'auth_sources',
        'roles', 'roles_managed_roles', 'trackers', 'issue_statuses', 'enumerations', 'workflows',
        'custom_fields', 'custom_field_enumerations', 'custom_fields_roles', 'custom_fields_trackers',
        'projects', 'projects_trackers', 'custom_fields_projects', 'enabled_modules', 'members', 'member_roles',
        'versions', 'issue_categories', 'issues', 'issue_relations', 'journals', 'journal_details', 'watchers',
        'custom_values', 'attachments', 'time_entries', 'news', 'comments', 'documents',
        'wikis', 'wiki_pages', 'wiki_contents', 'wiki_content_versions', 'wiki_redirects', 'boards', 'messages',
        'repositories', 'changesets', 'changes', 'changeset_parents', 'changesets_issues',
        'queries', 'queries_roles', 'imports', 'import_items', 'reactions', 'webhooks', 'projects_webhooks',
    ];

    /** @var callable(string): void */
    private $log;

    public function __construct(private readonly EntityManagerInterface $em)
    {
        $this->log = static function (string $m): void {};
    }

    /** @param callable(string): void $log */
    public function setLogger(callable $log): void
    {
        $this->log = $log;
    }

    public static function connect(string $url): Connection
    {
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite', 'sqlite3' => 'pdo_sqlite', 'sqlserver' => 'pdo_sqlsrv']))->parse($url);

        return DriverManager::getConnection($params);
    }

    /**
     * @return array{tables: array<string, array{source: int, copied: int, skipped: int}>, warnings: list<string>}
     */
    public function migrate(Connection $source, bool $purge = true, int $batchSize = 500): array
    {
        $target = $this->em->getConnection();
        $warnings = [];
        $report = [];

        $sourceTables = array_map('strtolower', $source->createSchemaManager()->listTableNames());
        $this->ensureSchema($purge);
        $targetSchema = $target->createSchemaManager();
        $targetTables = array_map('strtolower', $targetSchema->listTableNames());

        $version = $this->sourceVersion($source);
        ($this->log)(\sprintf('Source Redmine schema version: %s', $version ?? 'unknown'));

        foreach (array_diff($sourceTables, self::TABLE_ORDER, ['schema_migrations', 'ar_internal_metadata', 'sqlite_sequence', 'oauth_applications', 'oauth_access_grants', 'oauth_access_tokens']) as $t) {
            $warnings[] = "Table \"$t\" is not part of Redmine core (plugin?) and was not migrated.";
        }

        $target->beginTransaction();
        try {
            if ($target->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
                $target->executeStatement('PRAGMA foreign_keys = OFF');
            }
            foreach (self::TABLE_ORDER as $table) {
                if (!\in_array($table, $targetTables, true)) {
                    continue;
                }
                $target->executeStatement('DELETE FROM '.$target->quoteIdentifier($table));
                if (!\in_array($table, $sourceTables, true)) {
                    $report[$table] = ['source' => 0, 'copied' => 0, 'skipped' => 0];
                    continue;
                }
                $report[$table] = $this->copyTable($source, $target, $table, $batchSize, $warnings);
                ($this->log)(\sprintf('%-28s %8d rows', $table, $report[$table]['copied']));
            }
            $warnings = [...$warnings, ...$this->fixIntegrity($target)];
            $target->commit();
        } catch (\Throwable $e) {
            $target->rollBack();
            throw $e;
        }
        $this->resetSequences($target, $targetTables);

        return ['tables' => $report, 'warnings' => $warnings];
    }

    private function ensureSchema(bool $purge): void
    {
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        if ($purge) {
            $tool->updateSchema($metadata);
        }
    }

    private function sourceVersion(Connection $source): ?string
    {
        try {
            return (string) $source->fetchOne('SELECT MAX(version) FROM schema_migrations');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<string> $warnings */
    private function copyTable(Connection $source, Connection $target, string $table, int $batchSize, array &$warnings): array
    {
        $targetColumns = [];
        foreach ($target->createSchemaManager()->introspectTableColumnsByUnquotedName($table) as $col) {
            $targetColumns[strtolower($col->getName())] = $col->getType();
        }
        $sourceColumns = array_map(static fn ($c) => strtolower($c->getName()), $source->createSchemaManager()->introspectTableColumnsByUnquotedName($table));
        $columns = array_values(array_intersect($sourceColumns, array_keys($targetColumns)));
        foreach (array_diff($sourceColumns, $columns) as $extra) {
            $warnings[] = "Column $table.$extra does not exist in Symine and was ignored.";
        }
        $serialized = self::SERIALIZED_COLUMNS[$table] ?? [];
        $stiTypes = self::STI_TYPES[$table] ?? null;
        $hasId = \in_array('id', $columns, true);
        $total = (int) $source->fetchOne('SELECT COUNT(*) FROM '.$source->quoteIdentifier($table));
        $copied = 0;
        $skipped = 0;
        $selectCols = implode(', ', array_map($source->quoteIdentifier(...), $columns));
        $insertSql = 'INSERT INTO '.$target->quoteIdentifier($table).' ('.implode(', ', array_map($target->quoteIdentifier(...), $columns)).') VALUES ('.implode(', ', array_fill(0, \count($columns), '?')).')';
        $stmt = $target->prepare($insertSql);
        $offset = 0;
        $lastId = 0;
        while (true) {
            if ($hasId) {
                $rows = $source->fetchAllAssociative("SELECT $selectCols FROM ".$source->quoteIdentifier($table).' WHERE id > ? ORDER BY id '.$source->getDatabasePlatform()->modifyLimitQuery('', $batchSize), [$lastId]);
            } else {
                $rows = $source->fetchAllAssociative("SELECT $selectCols FROM ".$source->quoteIdentifier($table).' '.$source->getDatabasePlatform()->modifyLimitQuery('', $batchSize, $offset));
                $offset += $batchSize;
            }
            if ([] === $rows) {
                break;
            }
            foreach ($rows as $row) {
                $row = array_change_key_case($row, \CASE_LOWER);
                if ($hasId) {
                    $lastId = (int) $row['id'];
                }
                if (null !== $stiTypes && isset($row['type']) && !\in_array($row['type'], $stiTypes, true)) {
                    $warnings[] = \sprintf('%s #%s has unsupported type "%s" (plugin?) and was skipped.', $table, $row['id'] ?? '?', $row['type']);
                    ++$skipped;
                    continue;
                }
                if ('custom_fields' === $table && isset($row['type']) && !\array_key_exists($row['type'], \App\Entity\CustomField::TYPES)) {
                    $warnings[] = \sprintf('custom_fields #%s has unsupported type "%s" and was skipped.', $row['id'], $row['type']);
                    ++$skipped;
                    continue;
                }
                $i = 1;
                foreach ($columns as $col) {
                    $value = $row[$col];
                    if (\is_resource($value)) {
                        $value = stream_get_contents($value);
                    }
                    if (null !== $value && \in_array($col, $serialized, true)) {
                        $value = $this->convertSerialized((string) $value, $table, $col, $row['id'] ?? null, $warnings);
                    }
                    $type = $targetColumns[$col];
                    if ($type instanceof BooleanType) {
                        $stmt->bindValue($i++, null === $value ? null : \in_array($value, [true, 1, '1', 't', 'true', 'TRUE'], true), null === $value ? ParameterType::NULL : ParameterType::BOOLEAN);
                    } elseif (\in_array(Types::BLOB, [$type::class === \Doctrine\DBAL\Types\BlobType::class ? Types::BLOB : ''], true)) {
                        $stmt->bindValue($i++, $value, null === $value ? ParameterType::NULL : ParameterType::LARGE_OBJECT);
                    } else {
                        $stmt->bindValue($i++, $value, null === $value ? ParameterType::NULL : ParameterType::STRING);
                    }
                }
                $stmt->executeStatement();
                ++$copied;
            }
            if (\count($rows) < $batchSize) {
                break;
            }
        }

        return ['source' => $total, 'copied' => $copied, 'skipped' => $skipped];
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
     * Redmine has no FK constraints; legacy databases may contain dangling references that would
     * break the ORM. Nullable references are cleared, mandatory user references are reassigned to
     * the anonymous user (what Redmine does when deleting users).
     *
     * @return list<string>
     */
    private function fixIntegrity(Connection $conn): array
    {
        $warnings = [];
        $anonymousId = $conn->fetchOne("SELECT id FROM users WHERE type = 'AnonymousUser' ORDER BY id LIMIT 1");
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
                $targetMeta = $this->em->getClassMetadata($assoc->targetEntity);
                $targetTable = $targetMeta->getTableName();
                $key = "$table.$col";
                if (isset($done[$key])) {
                    continue;
                }
                $done[$key] = true;
                $where = "t.$col IS NOT NULL AND t.$col <> 0 AND NOT EXISTS (SELECT 1 FROM $targetTable x WHERE x.id = t.$col)";
                $zeroWhere = "t.$col = 0";
                $nullable = $joinColumn->nullable ?? true;
                $count = (int) $conn->fetchOne("SELECT COUNT(*) FROM $table t WHERE $where OR $zeroWhere");
                if (0 === $count) {
                    continue;
                }
                if ($nullable) {
                    $conn->executeStatement("UPDATE $table SET $col = NULL WHERE id IN (SELECT id FROM (SELECT t.id FROM $table t WHERE $where OR $zeroWhere) s)");
                    $warnings[] = "$count dangling reference(s) $key → $targetTable cleared.";
                } elseif ('users' === $targetTable && $anonymousId) {
                    $conn->executeStatement("UPDATE $table SET $col = ? WHERE id IN (SELECT id FROM (SELECT t.id FROM $table t WHERE $where OR $zeroWhere) s)", [$anonymousId]);
                    $warnings[] = "$count dangling reference(s) $key → users reassigned to Anonymous.";
                } else {
                    $warnings[] = "$count dangling reference(s) $key → $targetTable could not be fixed automatically.";
                }
            }
        }

        return $warnings;
    }

    /** @param list<string> $tables */
    private function resetSequences(Connection $conn, array $tables): void
    {
        if (!$conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }
        foreach ($tables as $t) {
            $seq = $conn->fetchOne('SELECT pg_get_serial_sequence(?, ?)', [$t, 'id']);
            if ($seq) {
                $conn->executeQuery("SELECT setval('$seq', COALESCE((SELECT MAX(id) FROM ".$conn->quoteIdentifier($t).'), 0) + 1, false)');
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

    /** @return array<string, array{source: int, target: int}> row counts per table, for verification */
    public function compareCounts(Connection $source): array
    {
        $target = $this->em->getConnection();
        $sourceTables = array_map('strtolower', $source->createSchemaManager()->listTableNames());
        $out = [];
        foreach (self::TABLE_ORDER as $t) {
            if (!\in_array($t, $sourceTables, true)) {
                continue;
            }
            $out[$t] = [
                'source' => (int) $source->fetchOne('SELECT COUNT(*) FROM '.$source->quoteIdentifier($t)),
                'target' => (int) $target->fetchOne('SELECT COUNT(*) FROM '.$target->quoteIdentifier($t)),
            ];
        }

        return $out;
    }
}

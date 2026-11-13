<?php

declare(strict_types=1);

namespace App\Migration;

use App\Doctrine\RedmineSerializedType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Semantic comparison of a Redmine source database and the migrated Symine database:
 * row counts, sampled rows column by column (YAML vs JSON compared structurally, booleans,
 * datetimes, floats and binary data normalised) and per-issue aggregates (journals, details,
 * custom values, watchers, attachments, relations, time entries).
 */
final class RedmineComparator
{
    /** Per-issue aggregates checked for every sampled issue */
    private const array ISSUE_AGGREGATES = [
        'journals' => "SELECT id, user_id, notes, private_notes FROM journals WHERE journalized_type = 'Issue' AND journalized_id = ? ORDER BY id",
        'journal_details' => "SELECT d.id, d.property, d.prop_key, d.old_value, d.value FROM journal_details d JOIN journals j ON j.id = d.journal_id WHERE j.journalized_type = 'Issue' AND j.journalized_id = ? ORDER BY d.id",
        'custom_values' => "SELECT custom_field_id, value FROM custom_values WHERE customized_type = 'Issue' AND customized_id = ? ORDER BY custom_field_id, value",
        'watchers' => "SELECT user_id FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = ? ORDER BY user_id",
        'attachments' => "SELECT id, filename, filesize, digest FROM attachments WHERE container_type = 'Issue' AND container_id = ? ORDER BY id",
        'relations' => 'SELECT id, issue_from_id, issue_to_id, relation_type, delay FROM issue_relations WHERE issue_from_id = ? ORDER BY id',
        'time_entries' => 'SELECT id, hours, spent_on, user_id FROM time_entries WHERE issue_id = ? ORDER BY id',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param list<string>|null $tables
     *
     * @return array{tables: array<string, array{source: int, target: int, skipped: int, sampled: int, mismatches: int}>, differences: list<string>, notices: list<string>}
     */
    public function compare(Connection $source, ?Connection $target = null, int $sample = 50, ?array $tables = null): array
    {
        $target ??= $this->em->getConnection();
        $schema = (new SchemaTool($this->em))->getSchemaFromMetadata($this->em->getMetadataFactory()->getAllMetadata());
        $sourceTables = array_map('strtolower', $source->createSchemaManager()->listTableNames());
        $targetTables = array_map('strtolower', $target->createSchemaManager()->listTableNames());
        $differences = [];
        $notices = [];
        $report = [];

        foreach ($tables ?? RedmineMigrator::TABLE_ORDER as $table) {
            if (!\in_array($table, $sourceTables, true) || !\in_array($table, $targetTables, true) || !$schema->hasTable($table)) {
                continue;
            }
            $columns = [];
            foreach ($schema->getTable($table)->getColumns() as $col) {
                $columns[strtolower($col->getName())] = $col;
            }
            $sourceCols = array_map(static fn ($c) => strtolower($c->getName()), $source->createSchemaManager()->introspectTableColumnsByUnquotedName($table));
            $common = array_values(array_filter(array_keys($columns), static fn ($c) => \in_array($c, $sourceCols, true)));
            $sCount = (int) $source->fetchOne('SELECT COUNT(*) FROM '.$source->quoteSingleIdentifier($table));
            $tCount = (int) $target->fetchOne('SELECT COUNT(*) FROM '.$target->quoteSingleIdentifier($table));
            $skipped = $this->expectedSkipped($source, $table, $common);
            $mismatches = 0;
            if ($sCount !== $tCount + $skipped) {
                $differences[] = \sprintf('%s: %d row(s) in Redmine, %d in Symine (%d expected to be skipped).', $table, $sCount, $tCount, $skipped);
                ++$mismatches;
            }
            $sampled = 0;
            if (\in_array('id', $common, true)) {
                $ids = $this->sampleIds($source, $table, $sample);
                $sampled = \count($ids);
                $mismatches += $this->compareRows($source, $target, $table, $common, $columns, $ids, $differences, $notices);
            } elseif ([] !== $common) {
                $sampled = $this->compareJoinTable($source, $target, $table, $common, $columns, $sample * 20, $differences);
                $mismatches += $sampled < 0 ? 1 : 0;
                $sampled = max(0, $sampled);
            }
            $report[$table] = ['source' => $sCount, 'target' => $tCount, 'skipped' => $skipped, 'sampled' => $sampled, 'mismatches' => $mismatches];
        }

        if ((null === $tables || \in_array('issues', $tables, true)) && \in_array('issues', $sourceTables, true)) {
            foreach ($this->sampleIds($source, 'issues', $sample) as $issueId) {
                foreach (self::ISSUE_AGGREGATES as $name => $sql) {
                    try {
                        $a = $this->canonicalRows($source->fetchAllAssociative($sql, [$issueId]));
                        $b = $this->canonicalRows($target->fetchAllAssociative($sql, [$issueId]));
                    } catch (\Throwable) {
                        continue; // table/column absent in an old Redmine version
                    }
                    if ($a !== $b) {
                        $differences[] = \sprintf('issue #%d: %s differ.', $issueId, $name);
                    }
                }
            }
        }

        return ['tables' => $report, 'differences' => $differences, 'notices' => $notices];
    }

    /** @param list<string> $columns */
    private function expectedSkipped(Connection $source, string $table, array $columns): int
    {
        if (!\in_array('type', $columns, true)) {
            return 0;
        }
        $allowed = RedmineMigrator::STI_TYPES[$table] ?? ('custom_fields' === $table ? array_keys(\App\Entity\CustomField::TYPES) : null);
        if (null === $allowed) {
            return 0;
        }
        $n = 0;
        foreach ($source->fetchAllAssociative('SELECT '.$source->quoteSingleIdentifier('type').' AS t, COUNT(*) AS n FROM '.$source->quoteSingleIdentifier($table).' GROUP BY '.$source->quoteSingleIdentifier('type')) as $r) {
            if (!\in_array($r['t'], $allowed, true)) {
                $n += (int) $r['n'];
            }
        }

        return $n;
    }

    /** @return list<int> first, last and evenly spread ids */
    private function sampleIds(Connection $source, string $table, int $sample): array
    {
        $p = $source->getDatabasePlatform();
        $t = $source->quoteSingleIdentifier($table);
        $third = max(1, intdiv($sample, 3));
        $ids = array_map('intval', $source->fetchFirstColumn($p->modifyLimitQuery("SELECT id FROM $t ORDER BY id", $third)));
        $ids = [...$ids, ...array_map('intval', $source->fetchFirstColumn($p->modifyLimitQuery("SELECT id FROM $t ORDER BY id DESC", $third)))];
        $count = (int) $source->fetchOne("SELECT COUNT(*) FROM $t");
        if ($count > 2 * $third) {
            $step = max(1, intdiv($count, $third));
            for ($offset = $step; $offset < $count && \count($ids) < 3 * $third + 2 * $third; $offset += $step) {
                $id = $source->fetchOne($p->modifyLimitQuery("SELECT id FROM $t ORDER BY id", 1, $offset));
                if (false !== $id) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<string>          $common
     * @param array<string, Column> $columns
     * @param list<int>             $ids
     * @param list<string>          $differences
     * @param list<string>          $notices
     */
    private function compareRows(Connection $source, Connection $target, string $table, array $common, array $columns, array $ids, array &$differences, array &$notices): int
    {
        if ([] === $ids) {
            return 0;
        }
        $mismatches = 0;
        $fetch = static function (Connection $c) use ($table, $common, $ids): array {
            $sql = 'SELECT '.implode(', ', array_map($c->quoteSingleIdentifier(...), $common)).' FROM '.$c->quoteSingleIdentifier($table).' WHERE id IN ('.implode(',', $ids).')';
            $out = [];
            foreach ($c->fetchAllAssociative($sql) as $row) {
                $row = array_change_key_case($row, \CASE_LOWER);
                $out[(int) $row['id']] = $row;
            }

            return $out;
        };
        $src = $fetch($source);
        $dst = $fetch($target);
        $serialized = RedmineMigrator::SERIALIZED_COLUMNS[$table] ?? [];
        foreach ($src as $id => $row) {
            if (!isset($dst[$id])) {
                $allowed = RedmineMigrator::STI_TYPES[$table] ?? null;
                if (null !== $allowed && isset($row['type']) && !\in_array($row['type'], $allowed, true)) {
                    continue; // skipped on purpose (plugin type)
                }
                if ('custom_fields' === $table && !\array_key_exists((string) ($row['type'] ?? ''), \App\Entity\CustomField::TYPES)) {
                    continue;
                }
                $differences[] = "$table #$id is missing in Symine.";
                ++$mismatches;
                continue;
            }
            foreach ($common as $col) {
                $a = self::normalize($row[$col], $columns[$col], \in_array($col, $serialized, true));
                $b = self::normalize($dst[$id][$col], $columns[$col], \in_array($col, $serialized, true));
                if ($a === $b) {
                    continue;
                }
                if (null !== $a && str_starts_with($a, 'aes-256-cbc:') && \in_array($col, RedmineMigrator::CIPHERED_COLUMNS[$table] ?? [], true)) {
                    $notices[] = \sprintf('%s #%d: %s was decrypted (Redmine database_cipher_key).', $table, $id, $col);
                    continue;
                }
                if ('roles' === $table && 'permissions' === $col && null !== $a && null !== $b) {
                    // Redmine 7 data migration 20260906000000 adds export permissions to existing roles
                    $pa = (array) json_decode($a, true);
                    $pb = (array) json_decode($b, true);
                    if ([] === array_diff($pa, $pb) && [] === array_diff($pb, $pa, ['export_issues', 'export_time_entries'])) {
                        $notices[] = \sprintf('roles #%d: export permissions added (Redmine 7.0 data migration).', $id);
                        continue;
                    }
                }
                if (str_ends_with($col, '_id') && null !== $a && (null === $b || $b === $this->anonymousId($target))) {
                    // dangling references are cleared / reassigned to Anonymous on purpose
                    $notices[] = \sprintf('%s #%d: %s changed from %s to %s (dangling reference fixed).', $table, $id, $col, $a, $b ?? 'NULL');
                    continue;
                }
                $differences[] = \sprintf('%s #%d: %s differs (Redmine: %s, Symine: %s).', $table, $id, $col, self::show($a), self::show($b));
                ++$mismatches;
            }
        }

        return $mismatches;
    }

    /**
     * Join tables (no id): compare the first $limit rows ordered by all columns.
     *
     * @param list<string>          $common
     * @param array<string, Column> $columns
     * @param list<string>          $differences
     *
     * @return int compared rows, -1 on mismatch
     */
    private function compareJoinTable(Connection $source, Connection $target, string $table, array $common, array $columns, int $limit, array &$differences): int
    {
        $rows = function (Connection $c) use ($table, $common, $columns, $limit): array {
            $cols = implode(', ', array_map($c->quoteSingleIdentifier(...), $common));
            $out = [];
            foreach ($c->fetchAllAssociative($c->getDatabasePlatform()->modifyLimitQuery("SELECT $cols FROM ".$c->quoteSingleIdentifier($table)." ORDER BY $cols", $limit)) as $r) {
                $r = array_change_key_case($r, \CASE_LOWER);
                $out[] = implode('|', array_map(static fn ($k) => self::normalize($r[$k], $columns[$k], false) ?? 'NULL', $common));
            }
            sort($out);

            return $out;
        };
        $a = $rows($source);
        $b = $rows($target);
        if ($a !== $b) {
            $differences[] = \sprintf('%s: rows differ (%d compared).', $table, \count($a));

            return -1;
        }

        return \count($a);
    }

    private ?string $anonymousId = null;

    private function anonymousId(Connection $target): ?string
    {
        if (null === $this->anonymousId) {
            $id = $target->fetchOne("SELECT id FROM users WHERE type = 'AnonymousUser' ORDER BY id");
            $this->anonymousId = false === $id ? '' : (string) $id;
        }

        return '' === $this->anonymousId ? null : $this->anonymousId;
    }

    /** Canonical, engine independent string form of a value */
    public static function normalize(mixed $value, ?Column $column, bool $serialized): ?string
    {
        if (\is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (null === $value) {
            return null;
        }
        if ($serialized) {
            $decoded = RedmineSerializedType::decode($value);

            return null === $decoded ? null : json_encode(self::ksortDeep($decoded), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        }
        $type = $column?->getType();

        return match (true) {
            $type instanceof Types\BooleanType => \in_array($value, [true, 1, '1', 't', 'true', 'TRUE'], true) ? '1' : '0',
            $type instanceof Types\IntegerType, $type instanceof Types\SmallIntType, $type instanceof Types\BigIntType => (string) (int) $value,
            $type instanceof Types\FloatType, $type instanceof Types\DecimalType => rtrim(rtrim(\sprintf('%.6F', (float) $value), '0'), '.'),
            $type instanceof Types\DateType, $type instanceof Types\DateImmutableType => RedmineMigrator::normalizeDate($value),
            $type instanceof Types\DateTimeType, $type instanceof Types\DateTimeImmutableType,
            $type instanceof Types\DateTimeTzType, $type instanceof Types\DateTimeTzImmutableType => RedmineMigrator::normalizeDateTime($value),
            $type instanceof Types\BlobType, $type instanceof Types\BinaryType => 'md5:'.md5((string) $value),
            default => (string) $value,
        };
    }

    private static function ksortDeep(mixed $v): mixed
    {
        if (!\is_array($v)) {
            return \is_int($v) || \is_float($v) || \is_bool($v) ? $v : (string) $v;
        }
        if (!array_is_list($v)) {
            ksort($v, \SORT_STRING);
        }

        return array_map(self::ksortDeep(...), $v);
    }

    /** @param list<array<string, mixed>> $rows */
    private function canonicalRows(array $rows): string
    {
        $out = [];
        foreach ($rows as $r) {
            $r = array_change_key_case($r, \CASE_LOWER);
            $out[] = array_map(static function ($v) {
                if (\is_resource($v)) {
                    $v = stream_get_contents($v);
                }

                return match (true) {
                    null === $v => null,
                    \is_bool($v) => $v ? '1' : '0',
                    \is_float($v), is_numeric($v) && str_contains((string) $v, '.') => rtrim(rtrim(\sprintf('%.6F', (float) $v), '0'), '.'),
                    't' === $v => '1',
                    'f' === $v => '0',
                    default => (string) $v,
                };
            }, $r);
        }

        return json_encode($out, \JSON_UNESCAPED_UNICODE) ?: '';
    }

    private static function show(?string $v): string
    {
        if (null === $v) {
            return 'NULL';
        }

        return mb_strlen($v) > 80 ? '"'.mb_substr($v, 0, 77).'..."' : '"'.$v.'"';
    }
}

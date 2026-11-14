<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * Counts the statements that change data, per table, as they go to the database (WriteLogMiddleware).
 *
 * This is what lets a service remember the result of a read for the rest of the request (QueryMemo) without
 * ever answering with something older than the last write: whoever remembers a result notes the version of the
 * tables it was read from, and reads again when that version has moved. Raw SQL and the unit of work are seen
 * alike, since both end up in the driver.
 *
 * The counters only grow, so there is nothing to reset between requests.
 */
final class WriteLog
{
    /** Statements whose target is known */
    private const string TARGET = '/^(?:INSERT\s+(?:OR\s+\w+\s+|IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE(?:\s+OR\s+\w+)?|DELETE\s+FROM|TRUNCATE(?:\s+TABLE)?)\s+(?:ONLY\s+)?(?:[`"\[]?\w+[`"\]]?\.)?[`"\[]?(\w+)/i';

    /** Statements that change nothing a result could have been read from */
    private const string HARMLESS = '/^(?:SELECT|PRAGMA|SHOW|EXPLAIN|DESCRIBE|SAVEPOINT|RELEASE|BEGIN|START|COMMIT|SET|VALUES)\b/i';

    /** Number of writes seen, whatever the table: when it has not moved, nothing has */
    public private(set) int $total = 0;

    /** Writes whose table could not be told (DDL, rollbacks...): they count for every table */
    private int $unknown = 0;

    /** @var array<string, int> */
    private array $tables = [];

    public function statement(string $sql): void
    {
        $sql = ltrim($sql, " \t\n\r(");
        if ('' === $sql || 1 === preg_match(self::HARMLESS, $sql)) {
            return;
        }
        if (1 === preg_match(self::TARGET, $sql, $m)) {
            $table = strtolower($m[1]);
            $this->tables[$table] = ($this->tables[$table] ?? 0) + 1;
            ++$this->total;

            return;
        }
        $this->everything();
    }

    /** Something changed that cannot be attributed to a table (a rollback, a schema change) */
    public function everything(): void
    {
        ++$this->unknown;
        ++$this->total;
    }

    /** Changes whenever one of the tables is written */
    public function version(string ...$tables): int
    {
        $version = $this->unknown;
        foreach ($tables as $table) {
            $version += $this->tables[$table] ?? 0;
        }

        return $version;
    }
}

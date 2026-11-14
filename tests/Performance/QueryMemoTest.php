<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use App\Doctrine\QueryMemo;
use App\Doctrine\WriteLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Reads remembered for the request (QueryMemo) are never older than the last write to their tables (WriteLog). */
final class QueryMemoTest extends TestCase
{
    public function testAResultIsReadOnceUntilOneOfItsTablesIsWritten(): void
    {
        $writes = new WriteLog();
        $memo = new QueryMemo($writes);
        $reads = 0;
        $load = static function () use (&$reads): int {
            return ++$reads;
        };

        self::assertSame(1, $memo->get('custom_fields:all', ['custom_fields'], $load));
        self::assertSame(1, $memo->get('custom_fields:all', ['custom_fields'], $load));

        $writes->statement('SELECT * FROM custom_fields');
        $writes->statement('INSERT INTO journals (notes) VALUES (?)');
        $writes->statement('UPDATE issues SET subject = ? WHERE id = ?');
        self::assertSame(1, $memo->get('custom_fields:all', ['custom_fields'], $load), 'written, but elsewhere');
        self::assertSame(1, $reads);

        $writes->statement('UPDATE custom_fields SET position = 2 WHERE id = 1');
        self::assertSame(2, $memo->get('custom_fields:all', ['custom_fields'], $load));
        self::assertSame(2, $memo->get('custom_fields:all', ['custom_fields'], $load));

        $writes->statement('DELETE FROM custom_fields WHERE id = 1');
        self::assertSame(3, $memo->get('custom_fields:all', ['custom_fields'], $load));
    }

    public function testAResultDependsOnEachOfItsTables(): void
    {
        $writes = new WriteLog();
        $memo = new QueryMemo($writes);
        $reads = 0;
        $load = static function () use (&$reads): int {
            return ++$reads;
        };
        $memo->get('watchers:Issue:1', ['watchers', 'users'], $load);
        $writes->statement('UPDATE users SET lastname = ? WHERE id = ?');
        self::assertSame(2, $memo->get('watchers:Issue:1', ['watchers', 'users'], $load));
        $writes->statement('INSERT INTO watchers (watchable_type, watchable_id, user_id) VALUES (?, ?, ?)');
        self::assertSame(3, $memo->get('watchers:Issue:1', ['watchers', 'users'], $load));
    }

    public function testWhatCannotBeAttributedToATableCountsForAllOfThem(): void
    {
        $writes = new WriteLog();
        $memo = new QueryMemo($writes);
        $reads = 0;
        $load = static function () use (&$reads): int {
            return ++$reads;
        };
        foreach (['ALTER TABLE issues ADD COLUMN x INTEGER', 'DROP TABLE hello_greetings', 'ROLLBACK', 'WITH moved AS (DELETE FROM a RETURNING *) INSERT INTO b SELECT * FROM moved'] as $i => $sql) {
            $memo->get('k', ['custom_fields'], $load);
            $writes->statement($sql);
            self::assertSame($i + 2, $memo->get('k', ['custom_fields'], $load), $sql);
        }
        // a rolled back transaction takes back what was read inside it
        $before = $memo->get('k', ['custom_fields'], $load);
        $writes->everything();
        self::assertSame($before + 1, $memo->get('k', ['custom_fields'], $load));
    }

    public function testNothingIsKeptBetweenRequestsNorAcrossAClearedEntityManager(): void
    {
        $memo = new QueryMemo(new WriteLog());
        $reads = 0;
        $load = static function () use (&$reads): int {
            return ++$reads;
        };
        $memo->get('k', ['issues'], $load);
        $memo->reset();
        self::assertSame(2, $memo->get('k', ['issues'], $load), 'kernel.reset');
        $memo->onClear();
        self::assertSame(3, $memo->get('k', ['issues'], $load), 'the entities it held are detached');
    }

    public function testAResultReadAsPartOfALargerQueryIsRememberedLikeAnyOther(): void
    {
        $writes = new WriteLog();
        $memo = new QueryMemo($writes);
        $memo->put('custom_values:Issue:1', ['custom_values'], ['1' => 'MySQL']);
        self::assertSame(['1' => 'MySQL'], $memo->get('custom_values:Issue:1', ['custom_values'], static fn () => self::fail('already known')));
        $writes->statement('UPDATE custom_values SET value = ? WHERE id = ?');
        self::assertSame([], $memo->get('custom_values:Issue:1', ['custom_values'], static fn () => []));
    }

    /** @return iterable<string, array{0: string, 1: ?string}> statement => table whose version moves (null: none) */
    public static function statements(): iterable
    {
        yield 'select' => ['SELECT t0.id FROM issues t0 WHERE t0.id = ?', null];
        yield 'select in parentheses' => ['(SELECT id FROM issues) UNION (SELECT id FROM projects)', null];
        yield 'sqlite introspection' => ["PRAGMA table_info('issues')", null];
        yield 'savepoint' => ['SAVEPOINT DOCTRINE_2', null];
        yield 'insert' => ['INSERT INTO issues (subject) VALUES (?)', 'issues'];
        yield 'insert, lower case and indented' => ["\n   insert into issues (subject) values (?)", 'issues'];
        yield 'update' => ['UPDATE issues SET lock_version = lock_version + 1 WHERE id = ?', 'issues'];
        yield 'delete' => ['DELETE FROM issues WHERE id = ?', 'issues'];
        yield 'quoted, MySQL' => ['UPDATE `issues` SET subject = ?', 'issues'];
        yield 'quoted, PostgreSQL' => ['INSERT INTO "issues" (subject) VALUES (?)', 'issues'];
        yield 'schema qualified' => ['DELETE FROM public.issues WHERE id = ?', 'issues'];
        yield 'sqlite upsert' => ['INSERT OR REPLACE INTO issues (id, subject) VALUES (?, ?)', 'issues'];
    }

    #[DataProvider('statements')]
    public function testStatementsAreAttributedToTheTableTheyWrite(string $sql, ?string $table): void
    {
        $writes = new WriteLog();
        $writes->statement($sql);
        self::assertSame(null === $table ? 0 : 1, $writes->total);
        self::assertSame(null === $table ? 0 : 1, $writes->version('issues'));
        self::assertSame(0, $writes->version('projects'), 'other tables are left alone');
    }
}

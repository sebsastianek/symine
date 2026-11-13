<?php

declare(strict_types=1);

namespace App\Command;

use App\Migration\RedmineComparator;
use App\Migration\RedmineMigrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Semantic verification of a migration (see docs/MIGRATION.md):
 *
 *   bin/console symine:redmine:compare --source="postgresql://redmine:secret@db/redmine" --sample=100
 *
 * Compares row counts, sampled rows column by column and per-issue aggregates (journals, details,
 * custom values, watchers, attachments, relations, time entries). Exit code 0 when identical.
 */
#[AsCommand(name: 'symine:redmine:compare', description: 'Compare the migrated Symine data with the source Redmine database')]
final class RedmineCompareCommand
{
    public function __construct(private readonly RedmineComparator $comparator)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Source Redmine database URL (defaults to REDMINE_DATABASE_URL)')] ?string $source = null,
        #[Option('Number of rows sampled per table (first, last and spread ids)')] int $sample = 50,
        #[Option('Comma separated list of tables to compare (default: all core tables)')] ?string $tables = null,
        #[Option('Maximum number of differences printed')] int $maxDifferences = 100,
    ): int {
        $source ??= $_SERVER['REDMINE_DATABASE_URL'] ?? $_ENV['REDMINE_DATABASE_URL'] ?? null;
        if (!$source) {
            $io->error('No source database. Use --source or REDMINE_DATABASE_URL.');

            return 1;
        }
        try {
            $only = RedmineMigrator::parseOnly($tables);
            $conn = RedmineMigrator::connect(str_replace('%kernel.project_dir%', \dirname(__DIR__, 2), $source));
            $conn->getNativeConnection();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return 1;
        }
        $io->title('Redmine ↔ Symine comparison');
        $result = $this->comparator->compare($conn, null, max(1, $sample), $only);
        $rows = [];
        foreach ($result['tables'] as $table => $t) {
            $rows[] = [$table, $t['source'], $t['target'], $t['skipped'] ?: '', $t['sampled'], $t['mismatches'] ? 'DIFF' : 'OK'];
        }
        $io->table(['Table', 'Redmine', 'Symine', 'Skipped', 'Sampled', 'Status'], $rows);
        $notices = array_values(array_unique($result['notices']));
        if ([] !== $notices) {
            $io->section(\sprintf('Expected changes (%d)', \count($notices)));
            $io->listing(\array_slice($notices, 0, $maxDifferences));
        }
        if ([] === $result['differences']) {
            $io->success('No difference found.');

            return 0;
        }
        $io->section(\sprintf('Differences (%d)', \count($result['differences'])));
        $io->listing(\array_slice($result['differences'], 0, $maxDifferences));
        $io->error('The migrated data differs from the source database.');

        return 1;
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Migration\RedmineMigrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Streamlined migration of an existing Redmine installation into Symine:
 *
 *   bin/console symine:migrate-from-redmine --source="mysql://user:pass@host/redmine" --files=/var/lib/redmine/files
 *
 * Copies every core table with ids preserved (the schemas are identical), converts Ruby YAML
 * columns to JSON, fixes dangling references, copies attachment files and verifies row counts.
 * Existing Redmine passwords keep working (legacy SHA1 hashes are upgraded on first login).
 */
#[AsCommand(name: 'symine:migrate-from-redmine', description: 'Import a Redmine database (and files) into Symine')]
final class MigrateFromRedmineCommand
{
    public function __construct(private readonly RedmineMigrator $migrator)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Source Redmine database URL (defaults to REDMINE_DATABASE_URL)')] ?string $source = null,
        #[Option('Redmine files directory (attachments) to copy')] ?string $files = null,
        #[Option('Target directory for attachments (defaults to SYMINE_FILES_PATH)')] ?string $filesTarget = null,
        #[Option('Rows per batch')] int $batch = 500,
        #[Option('Do not ask for confirmation')] bool $force = false,
    ): int {
        $source ??= $_SERVER['REDMINE_DATABASE_URL'] ?? $_ENV['REDMINE_DATABASE_URL'] ?? null;
        if (!$source) {
            $io->error('No source database. Use --source or REDMINE_DATABASE_URL.');

            return 1;
        }
        $source = str_replace('%kernel.project_dir%', \dirname(__DIR__, 2), $source);
        $files ??= ($_SERVER['REDMINE_FILES_PATH'] ?? '') ?: null;
        $filesTarget ??= str_replace('%kernel.project_dir%', \dirname(__DIR__, 2), (string) ($_SERVER['SYMINE_FILES_PATH'] ?? 'var/files'));

        $io->title('Redmine → Symine migration');
        $io->text('Source: '.preg_replace('#//([^:@/]+):[^@/]+@#', '//$1:***@', $source));
        if (!$force && !$io->confirm('All data in the Symine database will be REPLACED. Continue?', false)) {
            return 1;
        }

        $conn = RedmineMigrator::connect($source);
        $this->migrator->setLogger(static fn (string $m) => $io->writeln('  '.$m));
        $start = microtime(true);
        $result = $this->migrator->migrate($conn, true, $batch);

        if ($files) {
            $io->section('Attachments');
            $r = $this->migrator->copyFiles($files, $filesTarget);
            $io->text(\sprintf('%d file(s) copied to %s, %d missing in source, %d with a digest mismatch.', $r['copied'], $filesTarget, $r['missing'], $r['bad_digest']));
        }

        $io->section('Verification');
        $rows = [];
        $ok = true;
        foreach ($this->migrator->compareCounts($conn) as $table => $c) {
            $skipped = $result['tables'][$table]['skipped'] ?? 0;
            $match = $c['source'] === $c['target'] + $skipped;
            $ok = $ok && $match;
            $rows[] = [$table, $c['source'], $c['target'], $skipped ?: '', $match ? 'OK' : 'MISMATCH'];
        }
        $io->table(['Table', 'Redmine', 'Symine', 'Skipped', 'Status'], $rows);
        foreach (array_unique($result['warnings']) as $w) {
            $io->warning($w);
        }
        $io->text(\sprintf('Done in %.1fs.', microtime(true) - $start));
        if ($ok) {
            $io->success('Migration completed: all row counts match.');

            return 0;
        }
        $io->error('Some tables do not match, see above.');

        return 2;
    }
}

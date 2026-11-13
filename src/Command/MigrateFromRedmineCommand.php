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
 * See docs/MIGRATION.md.
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
        #[Option('Rows per batch (memory is bounded by this value)')] int $batch = 1000,
        #[Option('Only validate the source and report what would be migrated, without writing anything')] bool $dryRun = false,
        #[Option('Comma separated list of tables to (re)migrate, e.g. --only=issues,journals,journal_details')] ?string $only = null,
        #[Option('Redmine database_cipher_key (config/configuration.yml) to decrypt LDAP/SCM passwords and 2FA keys (defaults to REDMINE_CIPHER_KEY)')] ?string $cipherKey = null,
        #[Option('Do not ask for confirmation')] bool $force = false,
    ): int {
        $source ??= $_SERVER['REDMINE_DATABASE_URL'] ?? $_ENV['REDMINE_DATABASE_URL'] ?? null;
        if (!$source) {
            $io->error('No source database. Use --source or REDMINE_DATABASE_URL.');

            return 1;
        }
        try {
            $onlyTables = RedmineMigrator::parseOnly($only);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return 1;
        }
        $source = str_replace('%kernel.project_dir%', \dirname(__DIR__, 2), $source);
        $files ??= ($_SERVER['REDMINE_FILES_PATH'] ?? '') ?: null;
        $filesTarget ??= str_replace('%kernel.project_dir%', \dirname(__DIR__, 2), (string) ($_SERVER['SYMINE_FILES_PATH'] ?? 'var/files'));

        $io->title('Redmine → Symine migration'.($dryRun ? ' (dry run)' : ''));
        $io->text('Source: '.preg_replace('#//([^:@/]+):[^@/]+@#', '//$1:***@', $source));
        if (null !== $onlyTables) {
            $io->text('Tables: '.implode(', ', $onlyTables));
        }

        try {
            $conn = RedmineMigrator::connect($source);
            $conn->getNativeConnection();
        } catch (\Throwable $e) {
            $io->error('Cannot connect to the source database: '.$e->getMessage());

            return 1;
        }
        $this->migrator->setLogger(static fn (string $m) => $io->writeln('  '.$m));
        $this->migrator->setCipherKey($cipherKey ?? (($_SERVER['REDMINE_CIPHER_KEY'] ?? '') ?: null));

        if ($dryRun) {
            return $this->dryRun($io, $conn, $batch, $onlyTables);
        }

        $question = null === $onlyTables
            ? 'All data in the Symine database will be REPLACED. Continue?'
            : 'Data of the listed tables in the Symine database will be REPLACED. Continue?';
        if (!$force && !$io->confirm($question, false)) {
            return 1;
        }

        $start = microtime(true);
        $result = $this->migrator->migrate($conn, true, $batch, $onlyTables);

        if ($files && (null === $onlyTables || \in_array('attachments', $onlyTables, true))) {
            $io->section('Attachments');
            $r = $this->migrator->copyFiles($files, $filesTarget);
            $io->text(\sprintf('%d file(s) copied to %s, %d missing in source, %d with a digest mismatch.', $r['copied'], $filesTarget, $r['missing'], $r['bad_digest']));
        }

        $io->section('Verification');
        $rows = [];
        $ok = true;
        foreach ($this->migrator->compareCounts($conn, $onlyTables) as $table => $c) {
            $skipped = $result['tables'][$table]['skipped'] ?? 0;
            $match = $c['source'] === $c['target'] + $skipped;
            $ok = $ok && $match;
            $rows[] = [$table, $c['source'], $c['target'], $skipped ?: '', $match ? 'OK' : 'MISMATCH'];
        }
        $io->table(['Table', 'Redmine', 'Symine', 'Skipped', 'Status'], $rows);
        foreach (array_unique($result['warnings']) as $w) {
            $io->warning($w);
        }
        $io->text(\sprintf('Done in %.1fs (peak memory %.0f MB).', microtime(true) - $start, memory_get_peak_usage(true) / 1048576));
        if ($ok) {
            $io->success('Migration completed: all row counts match. Run symine:redmine:compare for a semantic check.');

            return 0;
        }
        $io->error('Some tables do not match, see above.');

        return 2;
    }

    /** @param list<string>|null $only */
    private function dryRun(SymfonyStyle $io, \Doctrine\DBAL\Connection $conn, int $batch, ?array $only): int
    {
        $result = $this->migrator->dryRun($conn, $batch, $only);
        $io->text('Source Redmine schema version: '.($result['version'] ?? 'unknown'));
        $rows = [];
        foreach ($result['tables'] as $table => $t) {
            $rows[] = [$table, $t['source'], $t['skipped'] ?: '', $t['yaml_errors'] ?: ''];
        }
        $io->table(['Table', 'Rows', 'Would skip', 'YAML errors'], $rows);
        foreach (array_unique($result['warnings']) as $w) {
            $io->warning($w);
        }
        foreach ($result['errors'] as $e) {
            $io->error($e);
        }
        if ([] === $result['errors']) {
            $io->success('Dry run: the source database can be migrated (nothing was written).');

            return 0;
        }
        $io->error('Dry run found problems (values that cannot be decoded are copied as-is).');

        return 2;
    }
}

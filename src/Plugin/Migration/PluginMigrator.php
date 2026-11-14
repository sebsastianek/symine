<?php

declare(strict_types=1);

namespace App\Plugin\Migration;

use App\Plugin\PluginDefinition;
use App\Plugin\PluginRegistry;
use App\Plugin\PluginSchema;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;

/**
 * Plugin migrations (Redmine::Plugin::Migrator / `rake redmine:plugins:migrate`), run with Doctrine Migrations.
 *
 * Each plugin has its own migrations namespace (<PluginNamespace>\Migrations, files in <plugin>/migrations/);
 * executed versions are recorded in the doctrine_migration_versions table (the fully qualified class name
 * identifies the plugin, like the "<version>-<plugin id>" rows of Redmine's schema_migrations).
 */
final class PluginMigrator
{
    public const string TABLE = 'doctrine_migration_versions';

    public function __construct(
        private readonly Connection $connection,
        private readonly PluginRegistry $plugins,
        private readonly ?PluginSchema $schema = null,
    ) {
    }

    /**
     * Migrates a plugin to a version: null/"latest" = all migrations, "0"/"first" = everything rolled back,
     * otherwise a version class name (short or fully qualified).
     *
     * @return list<string> versions executed (up or down)
     */
    public function migrate(PluginDefinition $plugin, ?string $version = null): array
    {
        if (!$plugin->hasMigrations()) {
            return [];
        }
        $df = $this->factory($plugin);
        $df->getMetadataStorage()->ensureInitialized();
        $target = $df->getVersionAliasResolver()->resolveVersionAlias($this->qualify($plugin, $version ?? 'latest'));
        $plan = $df->getMigrationPlanCalculator()->getPlanUntilVersion($target);
        if (0 === \count($plan)) {
            return [];
        }
        try {
            $df->getMigrator()->migrate($plan, new MigratorConfiguration());
        } finally {
            // its tables appeared or went away: what was remembered about them no longer holds
            $this->schema?->forget($plugin->id);
        }

        return array_map(static fn ($item) => (string) $item->getVersion(), $plan->getItems());
    }

    /**
     * Migrates every plugin (Redmine::Plugin.migrate).
     *
     * @return array<string, list<string>> plugin id => executed versions
     */
    public function migrateAll(): array
    {
        $out = [];
        foreach ($this->plugins->all() as $plugin) {
            $out[$plugin->id] = $this->migrate($plugin);
        }

        return $out;
    }

    /**
     * @return list<array{version: string, executed: bool, description: string}>
     */
    public function status(PluginDefinition $plugin): array
    {
        if (!$plugin->hasMigrations()) {
            return [];
        }
        $df = $this->factory($plugin);
        $df->getMetadataStorage()->ensureInitialized();
        $executed = $df->getMetadataStorage()->getExecutedMigrations();
        $out = [];
        foreach ($df->getMigrationPlanCalculator()->getMigrations()->getItems() as $m) {
            $out[] = ['version' => (string) $m->getVersion(), 'executed' => $executed->hasMigration($m->getVersion()), 'description' => $m->getMigration()->getDescription()];
        }

        return $out;
    }

    /** Last executed version of the plugin (Redmine Migrator.current_version), null if none */
    public function currentVersion(PluginDefinition $plugin): ?string
    {
        $current = null;
        foreach ($this->status($plugin) as $s) {
            if ($s['executed']) {
                $current = $s['version'];
            }
        }

        return $current;
    }

    private function qualify(PluginDefinition $plugin, string $version): string
    {
        if (\in_array($version, ['0', 'first', 'latest', 'current', 'prev', 'next'], true) || str_contains($version, '\\')) {
            return $version;
        }
        if (ctype_digit($version)) {
            $version = 'Version'.$version;
        }

        return (string) new Version($plugin->migrationNamespace().'\\'.$version);
    }

    private function factory(PluginDefinition $plugin): DependencyFactory
    {
        $config = new ConfigurationArray([
            'table_storage' => ['table_name' => self::TABLE],
            'migrations_paths' => [$plugin->migrationNamespace() => $plugin->migrationDirectory()],
            'all_or_nothing' => false,
            'transactional' => true,
        ]);

        return DependencyFactory::fromConnection($config, new ExistingConnection($this->connection));
    }
}

<?php

declare(strict_types=1);

namespace App\Command\Plugin;

use App\Plugin\Migration\PluginMigrator;
use App\Plugin\PluginNotFoundException;
use App\Plugin\PluginRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** rake redmine:plugins:migrate [NAME=plugin_id] [VERSION=x] */
#[AsCommand(name: 'symine:plugins:migrate', description: 'Runs the migrations of the plugins (all plugins, or one with its target version)')]
final class PluginsMigrateCommand extends Command
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginMigrator $migrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('plugin', InputArgument::OPTIONAL, 'Plugin id (default: all plugins)')
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Target version: latest (default), 0 to revert all, or a version (e.g. Version20261107000000)')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Only display the migration status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = $input->getArgument('plugin');
        $target = $input->getOption('target');
        if (null !== $target && null === $id) {
            $io->error('A plugin id is required with --target.');

            return Command::INVALID;
        }
        try {
            $plugins = null === $id ? array_values($this->plugins->all()) : [$this->plugins->find((string) $id)];
        } catch (PluginNotFoundException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        foreach ($plugins as $plugin) {
            if ($input->getOption('status')) {
                $rows = array_map(static fn (array $s) => [$s['version'], $s['executed'] ? 'migrated' : 'not migrated', $s['description']], $this->migrator->status($plugin));
                $io->section($plugin->id);
                [] === $rows ? $io->text('no migrations') : $io->table(['Version', 'Status', 'Description'], $rows);
                continue;
            }
            $done = $this->migrator->migrate($plugin, null === $target ? null : (string) $target);
            $io->writeln(\sprintf('<info>%s</info>: %s', $plugin->id, [] === $done ? 'up to date' : implode(', ', $done)));
        }

        return Command::SUCCESS;
    }
}

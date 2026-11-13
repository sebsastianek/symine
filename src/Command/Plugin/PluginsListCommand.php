<?php

declare(strict_types=1);

namespace App\Command\Plugin;

use App\Plugin\Migration\PluginMigrator;
use App\Plugin\PluginRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'symine:plugins:list', description: 'Lists the installed plugins')]
final class PluginsListCommand extends Command
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginMigrator $migrator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->plugins->all() as $p) {
            $pending = \count(array_filter($this->migrator->status($p), static fn (array $s) => !$s['executed']));
            $rows[] = [$p->id, $p->name, (string) $p->version, (string) $p->author, $p->isConfigurable() ? 'yes' : 'no', $pending > 0 ? $pending.' pending' : 'up to date', $p->directory];
        }
        (new SymfonyStyle($input, $output))->table(['Id', 'Name', 'Version', 'Author', 'Configurable', 'Migrations', 'Directory'], $rows);

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Command\Plugin;

use App\Plugin\Asset\PluginAssetsInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Publishes plugin assets to public/plugin_assets/<id> (Redmine mirrors them at startup) */
#[AsCommand(name: 'symine:plugins:assets', description: 'Publishes the plugins assets to public/plugin_assets')]
final class PluginsAssetsCommand extends Command
{
    public function __construct(private readonly PluginAssetsInstaller $installer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('copy', null, InputOption::VALUE_NONE, 'Copy the files instead of creating symlinks');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        foreach ($this->installer->install((bool) $input->getOption('copy')) as $id => $method) {
            $rows[] = [$id, $method];
        }
        $io->table(['Plugin', 'Method'], $rows);

        return Command::SUCCESS;
    }
}

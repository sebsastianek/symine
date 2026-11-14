<?php

declare(strict_types=1);

namespace App\Command\Theme;

use App\Theme\ThemeRegistry;
use App\Theme\ThemeSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'symine:themes:list', description: 'Lists the installed themes')]
final class ThemesListCommand extends Command
{
    public function __construct(private readonly ThemeRegistry $registry, private readonly ThemeSettings $settings)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $site = $this->settings->siteTheme();
        $rows = [];
        foreach ($this->registry->all() as $theme) {
            $rows[] = [
                $theme->id.($theme->id === $site->id ? ' *' : ''),
                $theme->name,
                $theme->isDefault() ? 'built-in' : ($theme->legacy ? 'Redmine' : 'Symine'),
                $theme->version,
                (string) $theme->parent,
                implode(', ', array_keys($theme->colorSchemes)),
                implode(', ', array_keys($theme->allOptions())),
            ];
        }
        $io->table(['Id', 'Name', 'Kind', 'Version', 'Parent', 'Colour schemes', 'Options'], $rows);
        $io->writeln('* theme of the site');

        foreach ($this->registry->errors() as $id => $errors) {
            $io->error(\sprintf('Theme "%s" cannot be used:', $id)."\n - ".implode("\n - ", $errors));
        }

        return Command::SUCCESS;
    }
}

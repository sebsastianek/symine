<?php

declare(strict_types=1);

namespace App\Command\Theme;

use App\Theme\ThemeRegistry;
use App\Theme\ThemeSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'symine:themes:use', description: 'Sets the theme of the site')]
final class ThemesUseCommand extends Command
{
    public function __construct(private readonly ThemeRegistry $registry, private readonly ThemeSettings $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Id of the theme ("default" for Redmine\'s stock interface)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('id');
        $theme = $this->registry->get($id);
        if (null === $theme) {
            $errors = $this->registry->errors()[$id] ?? null;
            $io->error(null === $errors
                ? \sprintf('There is no theme "%s". Installed: %s', $id, implode(', ', array_keys($this->registry->all())))
                : \sprintf('Theme "%s" cannot be used:', $id)."\n - ".implode("\n - ", $errors));

            return Command::FAILURE;
        }
        $this->settings->setSiteTheme($theme);
        $io->success(\sprintf('The site now uses the theme "%s".', $theme->name));

        return Command::SUCCESS;
    }
}

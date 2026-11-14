<?php

declare(strict_types=1);

namespace App\Command\Theme;

use App\Theme\ThemeRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Checks the manifests of the themes; meant for the authors of themes and for deployment pipelines */
#[AsCommand(name: 'symine:themes:lint', description: 'Checks the installed themes for errors')]
final class ThemesLintCommand extends Command
{
    public function __construct(private readonly ThemeRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'Only check this theme');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->registry->reset();
        $only = $input->getArgument('id');
        $errors = $this->registry->errors();
        $themes = $this->registry->all();

        if (null !== $only) {
            if (!isset($errors[$only]) && !isset($themes[$only])) {
                $io->error(\sprintf('There is no theme "%s" in: %s', $only, implode(', ', $this->registry->paths())));

                return Command::FAILURE;
            }
            $errors = array_intersect_key($errors, [$only => true]);
            $themes = array_intersect_key($themes, [$only => true]);
        }

        foreach ($themes as $theme) {
            if (!$theme->isDefault()) {
                $io->writeln(\sprintf('<info>OK</info>  %s (%s)', $theme->id, $theme->legacy ? 'Redmine theme' : 'theme.yaml'));
            }
        }
        foreach ($errors as $id => $messages) {
            $io->writeln(\sprintf('<error>ERR</error> %s', $id));
            foreach ($messages as $message) {
                $io->writeln('     - '.$message);
            }
        }

        return [] === $errors ? Command::SUCCESS : Command::FAILURE;
    }
}

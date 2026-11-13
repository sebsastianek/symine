<?php

declare(strict_types=1);

namespace App\Command\Admin;

use App\Service\Admin\Installer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the database schema (Redmine's schema, via Doctrine's schema tool), the default
 * administrator (admin/admin, must change password), the anonymous user, the builtin groups
 * and roles and optionally the default configuration data.
 */
#[AsCommand(name: 'symine:install', description: 'Create the database schema and the initial data')]
final class InstallCommand
{
    public function __construct(private readonly Installer $installer)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Option('Language of the default data')] string $lang = 'en',
        #[Option('Do not load the default configuration data')] bool $noDefaultData = false,
        #[Option('Drop the existing schema first (DESTROYS ALL DATA)')] bool $force = false,
        #[Option('Password of the admin account')] string $adminPassword = 'admin',
    ): int {
        if ($this->installer->isInstalled() && !$force) {
            $io->error('The database already contains a Symine/Redmine schema. Use --force to drop it and reinstall.');

            return Command::FAILURE;
        }
        if ($force && $input->isInteractive() && !$io->confirm('All data will be lost. Continue?', false)) {
            return Command::FAILURE;
        }
        $this->installer->createSchema($force);
        $io->writeln('Schema created.');
        $this->installer->createInitialData($adminPassword);
        $io->writeln('Administrator account "admin" created (password change required at first login).');
        if (!$noDefaultData) {
            $this->installer->loadDefaultData($lang);
            $io->writeln('Default configuration data loaded ('.$lang.').');
        }
        $io->success('Symine installed.');

        return Command::SUCCESS;
    }
}

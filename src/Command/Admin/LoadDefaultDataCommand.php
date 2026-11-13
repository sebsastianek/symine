<?php

declare(strict_types=1);

namespace App\Command\Admin;

use App\Service\Admin\DefaultDataLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Port of `rake redmine:load_default_data` */
#[AsCommand(name: 'symine:load-default-data', description: 'Load the default configuration data (roles, trackers, statuses, workflows, enumerations, queries)')]
final class LoadDefaultDataCommand
{
    public function __construct(private readonly DefaultDataLoader $loader)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Language of the default data (e.g. en, fr, de)')] string $lang = 'en',
        #[Option('Do not create the default workflow')] bool $noWorkflow = false,
    ): int {
        if (!$this->loader->noData()) {
            $io->error('Some configuration data is already loaded.');

            return Command::FAILURE;
        }
        try {
            $this->loader->load($lang, !$noWorkflow);
        } catch (\Throwable $e) {
            $io->error('Error while loading default data: '.$e->getMessage());

            return Command::FAILURE;
        }
        $io->success('Default configuration data loaded.');

        return Command::SUCCESS;
    }
}

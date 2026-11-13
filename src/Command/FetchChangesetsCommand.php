<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Project;
use App\Service\Scm\RepositoryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Port of `Repository.fetch_changesets` (`rake redmine:fetch_changesets`): fetches the new changesets of
 * all the repositories of the active projects having the repository module. Run it from cron, e.g.
 *
 *   bin/console symine:fetch-changesets [--project=foo]
 */
#[AsCommand(name: 'symine:fetch-changesets', description: 'Fetch the new changesets of the repositories')]
final class FetchChangesetsCommand
{
    public function __construct(
        private readonly RepositoryService $repos,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Id or identifier of a project (default: all active projects)')] ?string $project = null,
    ): int {
        $projects = null;
        if (null !== $project && '' !== $project) {
            $p = ctype_digit($project) ? $this->em->find(Project::class, (int) $project) : $this->em->getRepository(Project::class)->findOneBy(['identifier' => $project]);
            if (null === $p) {
                $io->error('Project not found: '.$project);

                return Command::FAILURE;
            }
            $projects = [$p];
        }
        $errors = $this->repos->fetchAllChangesets($projects);
        foreach ($errors as $e) {
            $io->warning($e);
        }

        return [] === $errors ? Command::SUCCESS : Command::FAILURE;
    }
}

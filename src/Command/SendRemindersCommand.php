<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Project;
use App\Entity\Tracker;
use App\Mailer\NotificationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Port of `rake redmine:send_reminders` (Mailer.reminders): e-mails each assignee the list of his open
 * issues due in the next days. Run it daily from cron, e.g.
 *
 *   bin/console symine:send-reminders --days=7 --tracker=1 --project=foo --users=3,4 --target-version="1.0"
 */
#[AsCommand(name: 'symine:send-reminders', description: 'Send reminders about issues due in the next days')]
final class SendRemindersCommand
{
    public function __construct(
        private readonly NotificationMailer $mailer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Number of days to remind about')] int $days = 7,
        #[Option('Id of tracker used to select issues')] ?string $tracker = null,
        #[Option('Id or identifier of the project')] ?string $project = null,
        #[Option('Comma separated list of user/group ids who should be reminded')] ?string $users = null,
        #[Option('Name of the target version for filtering issues (rake option "version")')] ?string $targetVersion = null,
    ): int {
        $projectId = null;
        if (null !== $project && '' !== $project) {
            $p = ctype_digit($project) ? $this->em->find(Project::class, (int) $project) : $this->em->getRepository(Project::class)->findOneBy(['identifier' => $project]);
            if (null === $p) {
                $io->error("Couldn't find Project with id=$project");

                return Command::FAILURE;
            }
            $projectId = $p->id;
        }
        $trackerId = null;
        if (null !== $tracker && '' !== $tracker) {
            if (null === $this->em->find(Tracker::class, (int) $tracker)) {
                $io->error("Couldn't find Tracker with id=$tracker");

                return Command::FAILURE;
            }
            $trackerId = (int) $tracker;
        }
        $userIds = array_values(array_filter(array_map('intval', explode(',', (string) $users))));
        try {
            $n = $this->mailer->reminders($days, $trackerId, $projectId, $userIds, '' === $targetVersion ? null : $targetVersion);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->writeln(\sprintf('%d reminder(s) sent.', $n));

        return Command::SUCCESS;
    }
}

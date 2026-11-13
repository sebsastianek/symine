<?php

declare(strict_types=1);

namespace App\Command;

use App\MailHandler\MailHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Port of `rake redmine:email:read`: reads a raw e-mail from standard input (or --file) and processes it
 * with the MailHandler. Typical MTA alias (Postfix /etc/aliases):
 *
 *   redmine: "|/path/to/symine/bin/console symine:receive-email --project=foo --unknown-user=accept --no-permission-check"
 */
#[AsCommand(name: 'symine:receive-email', description: 'Read an e-mail from standard input and create an issue or a reply')]
final class ReceiveEmailCommand
{
    public function __construct(private readonly MailHandler $handler)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Read the e-mail from this file instead of stdin')] ?string $file = null,
        #[Option('Identifier of the target project')] ?string $project = null,
        #[Option('Name of the target status')] ?string $status = null,
        #[Option('Name of the target tracker')] ?string $tracker = null,
        #[Option('Name of the target category')] ?string $category = null,
        #[Option('Name of the target priority')] ?string $priority = null,
        #[Option('Assignee (login or name)')] ?string $assignedTo = null,
        #[Option('Name of the target version')] ?string $fixedVersion = null,
        #[Option('Create new issues as private')] bool $private = false,
        #[Option('Allow email content to override attributes: comma separated list (project,tracker,status,...) or "all"')] ?string $allowOverride = null,
        #[Option('How to handle emails from unknown users: ignore (default), accept or create')] string $unknownUser = 'ignore',
        #[Option('Group(s) (comma separated) the created users are added to')] ?string $defaultGroup = null,
        #[Option('Do not send the account information to created users')] bool $noAccountNotice = false,
        #[Option('Disable notifications for created users')] bool $noNotification = false,
        #[Option('Disable permission checking when receiving the email')] bool $noPermissionCheck = false,
        #[Option('Select the project from the subaddress of this address (redmine+project@example.net)')] ?string $projectFromSubaddress = null,
    ): int {
        $raw = null !== $file ? @file_get_contents($file) : stream_get_contents(\STDIN);
        if (false === $raw || '' === $raw) {
            $io->error('No e-mail to read.');

            return Command::FAILURE;
        }
        $options = self::options(compact('project', 'status', 'tracker', 'category', 'priority', 'assignedTo', 'fixedVersion', 'private', 'allowOverride', 'unknownUser', 'defaultGroup', 'noAccountNotice', 'noNotification', 'noPermissionCheck', 'projectFromSubaddress'));
        $result = $this->handler->safeReceive($raw, $options);
        if (false === $result) {
            $io->error('E-mail rejected or ignored'.(null !== $this->handler->lastError() ? ': '.$this->handler->lastError() : '.'));

            return 75; // EX_TEMPFAIL is not appropriate for rejections; non-zero for the MTA log
        }
        $io->writeln(\sprintf('Processed: %s #%d', (new \ReflectionClass($result))->getShortName(), $result->id));

        return Command::SUCCESS;
    }

    /**
     * MailHandler.extract_options_from_env equivalent.
     *
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    public static function options(array $o): array
    {
        $issue = [];
        foreach (['project' => 'project', 'status' => 'status', 'tracker' => 'tracker', 'category' => 'category', 'priority' => 'priority', 'assignedTo' => 'assigned_to', 'fixedVersion' => 'fixed_version'] as $k => $attr) {
            if (null !== ($o[$k] ?? null) && '' !== $o[$k]) {
                $issue[$attr] = (string) $o[$k];
            }
        }
        if ($o['private'] ?? false) {
            $issue['is_private'] = '1';
        }
        $options = ['issue' => $issue, 'unknown_user' => $o['unknownUser'] ?? 'ignore'];
        foreach (['allowOverride' => 'allow_override', 'defaultGroup' => 'default_group', 'projectFromSubaddress' => 'project_from_subaddress'] as $k => $opt) {
            if (null !== ($o[$k] ?? null) && '' !== $o[$k]) {
                $options[$opt] = (string) $o[$k];
            }
        }
        foreach (['noAccountNotice' => 'no_account_notice', 'noNotification' => 'no_notification', 'noPermissionCheck' => 'no_permission_check'] as $k => $opt) {
            if ($o[$k] ?? false) {
                $options[$opt] = '1';
            }
        }

        return $options;
    }
}

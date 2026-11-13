<?php

declare(strict_types=1);

namespace App\Command;

use App\MailHandler\MailHandler;
use App\MailHandler\Pop3Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Port of `rake redmine:email:receive_pop3` (Redmine::POP3.check): reads every message of a POP3 mailbox
 * and processes it. Processed messages are deleted from the server; the others are left on the server
 * unless --delete-unprocessed is given. Run it from cron, e.g. every 5 minutes:
 *
 *   bin/console symine:receive-pop3 --host=pop.example.net --ssl --username=redmine --password=xxx --project=foo
 *
 * The MailHandler options are the same as symine:receive-imap.
 */
#[AsCommand(name: 'symine:receive-pop3', description: 'Read e-mails from a POP3 mailbox and create issues or replies')]
final class ReceivePop3Command
{
    public function __construct(private readonly MailHandler $handler)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('POP3 server host')] string $host = '127.0.0.1',
        #[Option('POP3 server port (110, 995 with --ssl)')] ?int $port = null,
        #[Option('Use SSL/TLS (POP3S)')] bool $ssl = false,
        #[Option('Do not verify the server certificate (ssl=force)')] bool $noVerify = false,
        #[Option('Use APOP authentication')] bool $apop = false,
        #[Option('POP3 account')] ?string $username = null,
        #[Option('POP3 password')] ?string $password = null,
        #[Option('Delete messages that could not be processed from the server')] bool $deleteUnprocessed = false,
        #[Option('Identifier of the target project')] ?string $project = null,
        #[Option('Name of the target status')] ?string $status = null,
        #[Option('Name of the target tracker')] ?string $tracker = null,
        #[Option('Name of the target category')] ?string $category = null,
        #[Option('Name of the target priority')] ?string $priority = null,
        #[Option('Assignee (login or name)')] ?string $assignedTo = null,
        #[Option('Name of the target version')] ?string $fixedVersion = null,
        #[Option('Create new issues as private')] bool $private = false,
        #[Option('Allow email content to override attributes ("all" or a comma separated list)')] ?string $allowOverride = null,
        #[Option('How to handle emails from unknown users: ignore (default), accept or create')] string $unknownUser = 'ignore',
        #[Option('Group(s) (comma separated) the created users are added to')] ?string $defaultGroup = null,
        #[Option('Do not send the account information to created users')] bool $noAccountNotice = false,
        #[Option('Disable notifications for created users')] bool $noNotification = false,
        #[Option('Disable permission checking when receiving the email')] bool $noPermissionCheck = false,
        #[Option('Select the project from the subaddress of this address')] ?string $projectFromSubaddress = null,
    ): int {
        $options = ReceiveEmailCommand::options(compact('project', 'status', 'tracker', 'category', 'priority', 'assignedTo', 'fixedVersion', 'private', 'allowOverride', 'unknownUser', 'defaultGroup', 'noAccountNotice', 'noNotification', 'noPermissionCheck', 'projectFromSubaddress'));
        $pop = new Pop3Client();
        try {
            $pop->connect($host, $port ?? ($ssl ? 995 : 110), $ssl, 60, !$noVerify);
            $pop->login((string) $username, (string) $password, $apop);
            [$ok, $failed] = self::check($pop, $this->handler, $options, $deleteUnprocessed);
            $pop->quit();
        } catch (\Throwable $e) {
            $pop->disconnect();
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->writeln(\sprintf('%d e-mail(s) processed, %d ignored.', $ok, $failed));

        return Command::SUCCESS;
    }

    /**
     * Redmine::POP3.check (without connection handling). Deletions are committed by QUIT.
     *
     * @param array<string, mixed> $options
     *
     * @return array{0: int, 1: int}
     */
    public static function check(Pop3Client $pop, MailHandler $handler, array $options, bool $deleteUnprocessed = false): array
    {
        $ok = $failed = 0;
        foreach ($pop->messageNumbers() as $number) {
            $message = $pop->retrieve($number);
            if (false !== $handler->safeReceive($message, $options)) {
                ++$ok;
                $pop->delete($number);
            } else {
                ++$failed;
                if ($deleteUnprocessed) {
                    $pop->delete($number);
                }
            }
        }

        return [$ok, $failed];
    }
}

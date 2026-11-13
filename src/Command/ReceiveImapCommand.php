<?php

declare(strict_types=1);

namespace App\Command;

use App\MailHandler\ImapClient;
use App\MailHandler\MailHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Port of `rake redmine:email:receive_imap` (Redmine::IMAP.check): fetches unread messages from an IMAP
 * mailbox and processes them. Processed messages are flagged Seen+Deleted (after a copy to --move-on-success),
 * rejected ones flagged Seen (and copied to --move-on-failure). Run it from cron, e.g. every 5 minutes:
 *
 *   bin/console symine:receive-imap --host=imap.example.net --ssl --username=redmine --password=xxx --project=foo
 *
 * Uses a built-in IMAP client (no ext-imap needed).
 */
#[AsCommand(name: 'symine:receive-imap', description: 'Read e-mails from an IMAP mailbox and create issues or replies')]
final class ReceiveImapCommand
{
    public function __construct(private readonly MailHandler $handler)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('IMAP server host')] string $host = '127.0.0.1',
        #[Option('IMAP server port (143, 993 with --ssl)')] ?int $port = null,
        #[Option('Use SSL/TLS')] bool $ssl = false,
        #[Option('Use STARTTLS')] bool $starttls = false,
        #[Option('Do not verify the server certificate')] bool $noVerify = false,
        #[Option('IMAP account')] ?string $username = null,
        #[Option('IMAP password')] ?string $password = null,
        #[Option('IMAP folder to read')] string $folder = 'INBOX',
        #[Option('Move emails that were successfully received to this folder')] ?string $moveOnSuccess = null,
        #[Option('Move emails that were ignored to this folder')] ?string $moveOnFailure = null,
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
        $imap = new ImapClient();
        try {
            $imap->connect($host, $port ?? ($ssl ? 993 : 143), $ssl, $starttls, 60, !$noVerify);
            if (null !== $username) {
                $imap->login($username, (string) $password);
            }
            [$ok, $failed] = self::check($imap, $this->handler, $options, $folder, $moveOnSuccess, $moveOnFailure);
            $imap->logout();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->writeln(\sprintf('%d e-mail(s) processed, %d ignored.', $ok, $failed));

        return Command::SUCCESS;
    }

    /**
     * Redmine::IMAP.check (without connection handling).
     *
     * @param array<string, mixed> $options
     *
     * @return array{0: int, 1: int}
     */
    public static function check(ImapClient $imap, MailHandler $handler, array $options, string $folder = 'INBOX', ?string $moveOnSuccess = null, ?string $moveOnFailure = null): array
    {
        $imap->select($folder);
        $ok = $failed = 0;
        foreach ($imap->uidSearch('NOT SEEN') as $uid) {
            $msg = $imap->uidFetchRfc822($uid);
            if (null !== $msg && false !== $handler->safeReceive($msg, $options)) {
                ++$ok;
                if (null !== $moveOnSuccess && '' !== $moveOnSuccess) {
                    $imap->uidCopy($uid, $moveOnSuccess);
                }
                $imap->uidStore($uid, '+', ['\Seen', '\Deleted']);
            } else {
                ++$failed;
                $imap->uidStore($uid, '+', ['\Seen']);
                if (null !== $moveOnFailure && '' !== $moveOnFailure) {
                    $imap->uidCopy($uid, $moveOnFailure);
                    $imap->uidStore($uid, '+', ['\Deleted']);
                }
            }
        }
        $imap->expunge();

        return [$ok, $failed];
    }
}

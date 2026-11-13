<?php

declare(strict_types=1);

namespace App\Tests\Integration\RealServices;

use App\Entity\Attachment;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\User;
use App\MailHandler\ImapClient;
use App\MailHandler\Pop3Client;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * symine:receive-imap / symine:receive-pop3 against a real mail server (GreenMail): messages are
 * delivered over SMTP, read over IMAP (move_on_success / move_on_failure folders) or POP3.
 *
 * SYMINE_TEST_IMAP_URL=imap://localhost:13143, SYMINE_TEST_POP3_URL=pop3://localhost:13110,
 * SYMINE_TEST_MAIL_SMTP=localhost:13025 (GreenMail SMTP used to deliver the test messages).
 * Accounts: redmine/secret (IMAP), redpop/secret (POP3), mailboxes @symine.test.
 */
final class IncomingMailTest extends RealServicesTestCase
{
    private static function message(string $from, string $to, string $subject, string $body, array $headers = [], ?array $attachment = null): string
    {
        $h = "From: $from\r\nTo: $to\r\nSubject: $subject\r\nMessage-ID: <".bin2hex(random_bytes(8))."@example.net>\r\nDate: ".date('r')."\r\nMIME-Version: 1.0\r\n";
        foreach ($headers as $k => $v) {
            $h .= "$k: $v\r\n";
        }
        $body = str_replace("\n", "\r\n", $body);
        if (null === $attachment) {
            return $h."Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n".$body."\r\n";
        }
        $b = 'b'.bin2hex(random_bytes(6));

        return $h."Content-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n--$b\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$body\r\n"
            ."--$b\r\nContent-Type: {$attachment[2]}; name=\"{$attachment[0]}\"\r\nContent-Disposition: attachment; filename=\"{$attachment[0]}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode($attachment[1]))."--$b--\r\n";
    }

    /** Delivers a raw message with a minimal SMTP dialog */
    private static function deliver(string $rcpt, string $raw): void
    {
        $smtp = self::optionalEnv('SYMINE_TEST_MAIL_SMTP', 'localhost:13025');
        $s = stream_socket_client('tcp://'.$smtp, $errno, $errstr, 10);
        self::assertNotFalse($s, "SMTP $smtp: $errstr");
        $expect = static function (string $code) use ($s): void {
            do {
                $line = (string) fgets($s);
            } while (isset($line[3]) && '-' === $line[3]);
            self::assertStringStartsWith($code, $line);
        };
        $expect('220');
        foreach (['EHLO symine.test' => '250', 'MAIL FROM:<sender@example.net>' => '250', "RCPT TO:<$rcpt>" => '250', 'DATA' => '354'] as $cmd => $code) {
            fwrite($s, $cmd."\r\n");
            $expect($code);
        }
        $raw = preg_replace('/^\./m', '..', $raw);
        fwrite($s, rtrim($raw, "\r\n")."\r\n.\r\n");
        $expect('250');
        fwrite($s, "QUIT\r\n");
        fclose($s);
    }

    private function imap(): ImapClient
    {
        $u = self::parseUrl(self::env('SYMINE_TEST_IMAP_URL'), 143);
        $imap = new ImapClient();
        $imap->connect($u['host'], $u['port'], 'imaps' === $u['scheme'], false, 10, false);
        $imap->login('redmine', 'secret');

        return $imap;
    }

    /** @return int number of messages in the folder */
    private function folderCount(ImapClient $imap, string $folder): int
    {
        $imap->select($folder);

        return \count($imap->uidSearch('ALL'));
    }

    /** Empties the mailbox (INBOX + move folders) */
    private function resetImap(): void
    {
        $imap = $this->imap();
        foreach (['Processed', 'Failed'] as $folder) {
            try {
                $imap->command('CREATE "'.$folder.'"');
            } catch (\RuntimeException) {
                // already exists
            }
        }
        foreach (['INBOX', 'Processed', 'Failed'] as $folder) {
            $imap->select($folder);
            foreach ($imap->uidSearch('ALL') as $uid) {
                $imap->uidStore($uid, '+', ['\Deleted']);
            }
            $imap->expunge();
        }
        $imap->logout();
    }

    private function runCommand(string $command, array $options): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($command));
        $tester->execute($options);

        return $tester;
    }

    private function maxIssueId(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT MAX(id) FROM issues');
    }

    public function testReceiveImapWithMoveFolders(): void
    {
        $u = self::parseUrl(self::env('SYMINE_TEST_IMAP_URL'), 143);
        $this->resetImap();
        $before = $this->maxIssueId();
        $to = 'redmine@symine.test';
        self::deliver($to, self::message('John Smith <jsmith@example.net>', $to, 'Printer on fire', "The printer is on fire.\n\nTracker: Bug\nPriority: Urgent\nAssigned to: dlopper\nStart date: 2026-11-10\n% Done: 20"));
        self::deliver($to, self::message('jsmith@example.net', $to, 'Log file attached', 'See the attached log.', [], ['server.log', "line 1\nline 2\n", 'text/plain']));
        self::deliver($to, self::message('Newbie Person <newbie@example.org>', $to, 'Hello from a stranger', 'I have no account yet.'));
        // rejected: locked user (an unknown "Project:" keyword would fall back to --project, like Redmine)
        self::deliver($to, self::message('locked@example.net', $to, 'From a locked user', 'Rejected.'));

        $options = ['--host' => $u['host'], '--port' => $u['port'], '--username' => 'redmine', '--password' => 'secret',
            '--project' => 'ecommerce', '--allow-override' => 'all', '--unknown-user' => 'create', '--no-permission-check' => true,
            '--move-on-success' => 'Processed', '--move-on-failure' => 'Failed'];
        $tester = $this->runCommand('symine:receive-imap', $options);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('3 e-mail(s) processed, 1 ignored.', $tester->getDisplay());

        $this->em->clear();
        $issues = $this->em->createQuery('SELECT i FROM App\Entity\Issue i WHERE i.id > :id ORDER BY i.id')->setParameter('id', $before)->getResult();
        self::assertSame(['Printer on fire', 'Log file attached', 'Hello from a stranger'], array_map(static fn (Issue $i) => $i->subject, $issues));
        [$fire, $log, $stranger] = $issues;
        self::assertSame(['ecommerce', 'Bug', 'Urgent', 'dlopper', '2026-11-10', 20, 'jsmith'],
            [$fire->project->identifier, $fire->tracker->name, $fire->priority->name, $fire->assignedTo?->login, $fire->startDate?->format('Y-m-d'), $fire->doneRatio, $fire->author->login]);
        self::assertSame('The printer is on fire.', trim((string) $fire->description));
        $attachments = $this->em->getRepository(Attachment::class)->findBy(['containerType' => 'Issue', 'containerId' => $log->id]);
        self::assertSame(['server.log'], array_map(static fn (Attachment $a) => $a->filename, $attachments));
        self::assertSame(14, $attachments[0]->filesize);
        $newbie = $this->em->getRepository(User::class)->findOneBy(['login' => 'newbie@example.org']);
        self::assertNotNull($newbie);
        self::assertSame(['Newbie', 'Person', 'newbie@example.org'], [$newbie->firstname, $newbie->lastname, $newbie->getMail()]);
        self::assertSame($newbie->id, $stranger->author->id);

        $imap = $this->imap();
        self::assertSame(0, $this->folderCount($imap, 'INBOX'));
        self::assertSame(3, $this->folderCount($imap, 'Processed'));
        self::assertSame(1, $this->folderCount($imap, 'Failed'));

        // a reply to the created issue: journal with attribute changes
        self::deliver($to, self::message('John Smith <jsmith@example.net>', $to, 'Re: [eCommerce - Bug #'.$fire->id.'] Printer on fire', "Fire is out now.\n\nStatus: Resolved\n% Done: 100", ['In-Reply-To' => '<redmine.issue-'.$fire->id.'.20261107@symine.test>']));
        $tester = $this->runCommand('symine:receive-imap', $options);
        self::assertStringContainsString('1 e-mail(s) processed, 0 ignored.', $tester->getDisplay());
        $this->em->clear();
        $fire = $this->em->find(Issue::class, $fire->id);
        self::assertSame(['Resolved', 100], [$fire->status->name, $fire->doneRatio]);
        $journals = $this->em->getRepository(Journal::class)->findBy(['issue' => $fire->id]);
        self::assertCount(1, $journals);
        self::assertSame('Fire is out now.', trim((string) $journals[0]->notes));
        self::assertSame(4, $this->folderCount($imap, 'Processed'));
        self::assertSame(0, $this->folderCount($imap, 'INBOX'));
        $imap->logout();
    }

    public function testReceiveImapWithoutMoveFolders(): void
    {
        $u = self::parseUrl(self::env('SYMINE_TEST_IMAP_URL'), 143);
        $this->resetImap();
        $to = 'redmine@symine.test';
        self::deliver($to, self::message('jsmith@example.net', $to, 'Plain IMAP', 'No move folders.'));
        self::deliver($to, self::message('stranger@example.org', $to, 'Ignored', 'Unknown user is ignored.'));
        $tester = $this->runCommand('symine:receive-imap', ['--host' => $u['host'], '--port' => $u['port'], '--username' => 'redmine', '--password' => 'secret', '--project' => 'ecommerce']);
        self::assertStringContainsString('1 e-mail(s) processed, 1 ignored.', $tester->getDisplay());
        // processed: deleted; ignored: left in INBOX, flagged as seen (not processed again)
        $imap = $this->imap();
        $imap->select('INBOX');
        self::assertCount(1, $imap->uidSearch('ALL'));
        self::assertCount(0, $imap->uidSearch('NOT SEEN'));
        $imap->logout();
        $tester = $this->runCommand('symine:receive-imap', ['--host' => $u['host'], '--port' => $u['port'], '--username' => 'redmine', '--password' => 'secret', '--project' => 'ecommerce']);
        self::assertStringContainsString('0 e-mail(s) processed, 0 ignored.', $tester->getDisplay());

        $tester = $this->runCommand('symine:receive-imap', ['--host' => $u['host'], '--port' => $u['port'], '--username' => 'redmine', '--password' => 'wrong']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('LOGIN', $tester->getDisplay());
    }

    public function testReceivePop3(): void
    {
        $u = self::parseUrl(self::env('SYMINE_TEST_POP3_URL'), 110);
        $pop = new Pop3Client();
        $pop->connect($u['host'], $u['port'], 'pop3s' === $u['scheme'], 10, false);
        $pop->login('redpop', 'secret');
        foreach ($pop->messageNumbers() as $n) {
            $pop->delete($n);
        }
        $pop->quit();

        $to = 'redpop@symine.test';
        $before = $this->maxIssueId();
        self::deliver($to, self::message('jsmith@example.net', $to, 'From POP3', "Read over POP3.\n.leading dot line\n\nTracker: Support", [], ['notes.txt', 'some notes', 'text/plain']));
        self::deliver($to, self::message('stranger@example.org', $to, 'Ignored POP3', 'Unknown user.'));
        $base = ['--host' => $u['host'], '--port' => $u['port'], '--username' => 'redpop', '--password' => 'secret', '--project' => 'ecommerce', '--allow-override' => 'tracker'];

        $tester = $this->runCommand('symine:receive-pop3', $base);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('1 e-mail(s) processed, 1 ignored.', $tester->getDisplay());
        $this->em->clear();
        $issue = $this->em->find(Issue::class, $before + 1);
        self::assertSame(['From POP3', 'Support', 'jsmith'], [$issue?->subject, $issue?->tracker->name, $issue?->author->login]);
        self::assertStringContainsString('.leading dot line', (string) $issue->description);
        self::assertCount(1, $this->em->getRepository(Attachment::class)->findBy(['containerType' => 'Issue', 'containerId' => $issue->id]));
        self::assertNull($this->em->find(Issue::class, $before + 2));

        // the unprocessed message is left on the server, then deleted with --delete-unprocessed
        $tester = $this->runCommand('symine:receive-pop3', $base);
        self::assertStringContainsString('0 e-mail(s) processed, 1 ignored.', $tester->getDisplay());
        $tester = $this->runCommand('symine:receive-pop3', $base + ['--delete-unprocessed' => true]);
        self::assertStringContainsString('0 e-mail(s) processed, 1 ignored.', $tester->getDisplay());
        $tester = $this->runCommand('symine:receive-pop3', $base);
        self::assertStringContainsString('0 e-mail(s) processed, 0 ignored.', $tester->getDisplay());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\MailHandler;

use App\Command\ReceiveImapCommand;
use App\Entity\Issue;
use App\MailHandler\ImapClient;
use App\MailHandler\MailHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** POST /mail_handler (rdm-mailhandler.rb), symine:receive-email and the IMAP checker */
final class MailHandlerEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $dir = \dirname(__DIR__, 2);
        copy($dir.'/tests/fixtures/symine-seeded.db', $dir.'/var/test'.($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '').'.db');
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM settings WHERE name IN ('mail_handler_api_enabled', 'mail_handler_api_key')");
        $conn->executeStatement("INSERT INTO settings (name, value, updated_on) VALUES ('mail_handler_api_enabled', '1', CURRENT_TIMESTAMP), ('mail_handler_api_key', 'secret-key', CURRENT_TIMESTAMP)");
    }

    private const string RAW = "From: John Smith <jsmith@example.net>\r\nTo: redmine@example.net\r\nSubject: Sent through rdm-mailhandler\r\n\r\nIssue body\r\n";

    private function maxIssueId(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT MAX(id) FROM issues');
    }

    public function testEndpointCreatesIssue(): void
    {
        $before = $this->maxIssueId();
        $this->client->request('POST', '/mail_handler', ['key' => 'secret-key', 'email' => self::RAW, 'issue' => ['project' => 'ecommerce', 'tracker' => 'Support', 'assigned_to' => 'dlopper'], 'allow_override' => 'tracker']);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        // the notification is sent after the response (kernel.terminate)
        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(), 'To', 'dlopper@example.net');
        $issue = $this->em->find(Issue::class, $this->maxIssueId());
        self::assertGreaterThan($before, $issue->id);
        self::assertSame('Sent through rdm-mailhandler', $issue->subject);
        self::assertSame('Support', $issue->tracker->name);
    }

    public function testEndpointRejectsInvalidKeyAndDisabledService(): void
    {
        $this->client->request('POST', '/mail_handler', ['key' => 'wrong', 'email' => self::RAW, 'issue' => ['project' => 'ecommerce']]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Access denied', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/mail_handler?key=secret-key');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Redmine Mail Handler', (string) $this->client->getResponse()->getContent());

        // unprocessable e-mail
        $this->client->request('POST', '/mail_handler', ['key' => 'secret-key', 'email' => self::RAW]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $this->em->getConnection()->executeStatement("UPDATE settings SET value = '0' WHERE name = 'mail_handler_api_enabled'");
        $this->client->request('POST', '/mail_handler', ['key' => 'secret-key', 'email' => self::RAW, 'issue' => ['project' => 'ecommerce']]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testReceiveEmailCommand(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'eml');
        file_put_contents($file, self::RAW);
        $tester = new CommandTester((new Application(self::$kernel))->find('symine:receive-email'));
        $tester->execute(['--file' => $file, '--project' => 'ecommerce', '--private' => true]);
        unlink($file);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Processed: Issue #', $tester->getDisplay());
        $issue = $this->em->find(Issue::class, $this->maxIssueId());
        self::assertTrue($issue->isPrivate);
    }

    public function testImapCheckWithScriptedServer(): void
    {
        [$client, $server] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $msg = self::RAW;
        fwrite($server, implode('', [
            "* 1 EXISTS\r\nA0001 OK [READ-WRITE] SELECT completed\r\n",
            "* SEARCH 42\r\nA0002 OK SEARCH completed\r\n",
            '* 1 FETCH (UID 42 RFC822 {'.\strlen($msg)."}\r\n".$msg.")\r\nA0003 OK FETCH completed\r\n",
            "A0004 OK COPY completed\r\n",
            "A0005 OK STORE completed\r\n",
            "A0006 OK EXPUNGE completed\r\n",
        ]));
        $imap = new ImapClient($client);
        $handler = static::getContainer()->get(MailHandler::class);
        [$ok, $failed] = ReceiveImapCommand::check($imap, $handler, ['issue' => ['project' => 'ecommerce']], 'INBOX', 'Processed');
        self::assertSame([1, 0], [$ok, $failed]);
        stream_set_blocking($server, false);
        $sent = (string) stream_get_contents($server);
        self::assertStringContainsString('A0001 SELECT "INBOX"', $sent);
        self::assertStringContainsString('A0002 UID SEARCH NOT SEEN', $sent);
        self::assertStringContainsString('A0003 UID FETCH 42 RFC822', $sent);
        self::assertStringContainsString('A0004 UID COPY 42 "Processed"', $sent);
        self::assertStringContainsString('A0005 UID STORE 42 +FLAGS (\Seen \Deleted)', $sent);
        self::assertStringContainsString('A0006 EXPUNGE', $sent);
        self::assertSame('Sent through rdm-mailhandler', $this->em->find(Issue::class, $this->maxIssueId())->subject);
    }
}

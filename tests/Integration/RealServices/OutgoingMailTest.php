<?php

declare(strict_types=1);

namespace App\Tests\Integration\RealServices;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Issue notifications delivered over SMTP to Mailpit (SYMINE_TEST_SMTP_DSN=smtp://localhost:11125,
 * SYMINE_TEST_MAILPIT_URL=http://localhost:18125). Headers, threading, recipients and the
 * multipart/alternative structure were compared with the reference Redmine sending to the same Mailpit.
 */
final class OutgoingMailTest extends RealServicesTestCase
{
    private HttpClientInterface $mailpit;
    /** @var array{server: mixed, env: mixed}|null values of MAILER_DSN before the test (null: untouched) */
    private ?array $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = self::env('SYMINE_TEST_SMTP_DSN');
        $this->mailpit = HttpClient::createForBaseUri(rtrim(self::env('SYMINE_TEST_MAILPIT_URL'), '/'));
        // the mailer DSN is an env var resolved when the container is booted
        $this->previousDsn = ['server' => $_SERVER['MAILER_DSN'] ?? null, 'env' => $_ENV['MAILER_DSN'] ?? null];
        $_SERVER['MAILER_DSN'] = $_ENV['MAILER_DSN'] = $dsn;
        parent::setUp();
        $this->mailpit->request('DELETE', '/api/v1/messages')->getStatusCode();
    }

    protected function tearDown(): void
    {
        // restore only what setUp changed (setUp is skipped when the services are not configured)
        if (null !== $this->previousDsn) {
            if (null === $this->previousDsn['server']) {
                unset($_SERVER['MAILER_DSN']);
            } else {
                $_SERVER['MAILER_DSN'] = $this->previousDsn['server'];
            }
            if (null === $this->previousDsn['env']) {
                unset($_ENV['MAILER_DSN']);
            } else {
                $_ENV['MAILER_DSN'] = $this->previousDsn['env'];
            }
            $this->previousDsn = null;
        }
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> message summaries, oldest first */
    private function messages(int $expected): array
    {
        $deadline = microtime(true) + 10;
        do {
            $list = $this->mailpit->request('GET', '/api/v1/messages')->toArray()['messages'] ?? [];
            if (\count($list) >= $expected) {
                break;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        return array_reverse($list);
    }

    /** @return array<string, list<string>> */
    private function headers(string $id): array
    {
        return $this->mailpit->request('GET', '/api/v1/message/'.$id.'/headers')->toArray();
    }

    private function raw(string $id): string
    {
        return $this->mailpit->request('GET', '/api/v1/message/'.$id.'/raw')->getContent();
    }

    public function testIssueNotificationsThroughSmtp(): void
    {
        $response = $this->api('POST', '/issues.json', ['issue' => [
            'project_id' => 1, 'tracker_id' => 1, 'subject' => 'Mail over SMTP', 'description' => "First line\nsecond line", 'assigned_to_id' => 6, 'priority_id' => 2,
        ]], 'jsmith', 'password123');
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = json_decode((string) $response->getContent(), true)['issue']['id'];

        // issue_add: only the assignee (jsmith, the author, has no_self_notified; others only_assigned)
        $messages = $this->messages(1);
        self::assertCount(1, $messages);
        $added = $messages[0];
        self::assertSame("[eCommerce - Bug #$id] (New) Mail over SMTP", $added['Subject']);
        self::assertSame(['dlopper@example.net'], array_column($added['To'], 'Address'));
        self::assertSame(['John Smith', 'redmine@example.net'], [$added['From']['Name'], $added['From']['Address']]);
        $h = $this->headers($added['ID']);
        $messageId = $h['Message-Id'][0] ?? $h['Message-ID'][0];
        self::assertMatchesRegularExpression("/^<redmine\\.issue-$id\\.\\d{14}\\.6@example\\.net>$/", $messageId);
        self::assertSame([$messageId], $h['References']);
        foreach (['X-Mailer' => 'Redmine', 'X-Redmine-Host' => 'localhost:3000', 'X-Redmine-Site' => 'Redmine Reference', 'X-Redmine-Project' => 'ecommerce',
            'X-Redmine-Issue-Id' => (string) $id, 'X-Redmine-Issue-Author' => 'jsmith', 'X-Redmine-Issue-Assignee' => 'dlopper', 'X-Redmine-Issue-Tracker' => 'Bug',
            'X-Redmine-Issue-Priority' => 'Normal', 'X-Redmine-Sender' => 'jsmith', 'Auto-Submitted' => 'auto-generated', 'X-Auto-Response-Suppress' => 'All',
            'List-Id' => '<ecommerce.redmine.example.net>'] as $name => $value) {
            self::assertSame([$value], $h[$name] ?? null, $name);
        }
        $raw = $this->raw($added['ID']);
        self::assertMatchesRegularExpression('#^Content-Type: multipart/alternative;#mi', $raw);
        self::assertMatchesRegularExpression('#^Content-Type: text/plain; charset=utf-8#mi', $raw);
        self::assertMatchesRegularExpression('#^Content-Type: text/html; charset=utf-8#mi', $raw);
        $full = $this->mailpit->request('GET', '/api/v1/message/'.$added['ID'])->toArray();
        self::assertStringContainsString("Bug #$id: Mail over SMTP\r\nhttp://localhost:3000/issues/$id\r\n", $full['Text']);
        self::assertStringContainsString("First line\r\nsecond line\r\n\r\n\r\n\r\n-- \r\n", $full['Text']);
        // common_mark hard breaks, like Redmine
        self::assertStringContainsString('<p>First line<br />', $full['HTML']);

        // issue_edit: assignee and author (watcher), threaded under the issue message
        $response = $this->api('PUT', "/issues/$id.json", ['issue' => ['notes' => 'Admin note', 'status_id' => 2]]);
        self::assertSame(204, $response->getStatusCode());
        $messages = $this->messages(3);
        self::assertCount(3, $messages);
        $edits = \array_slice($messages, 1);
        $recipients = array_merge(...array_map(static fn ($m) => array_column($m['To'], 'Address'), $edits));
        sort($recipients);
        self::assertSame(['dlopper@example.net', 'jsmith@example.net'], $recipients);
        foreach ($edits as $edit) {
            self::assertSame("[eCommerce - Bug #$id] (In Progress) Mail over SMTP", $edit['Subject']);
            $h = $this->headers($edit['ID']);
            self::assertMatchesRegularExpression('/^<redmine\.journal-\d+\.\d{14}\.\d+@example\.net>$/', $h['Message-Id'][0] ?? $h['Message-ID'][0]);
            // like Redmine: threaded with References only (no In-Reply-To)
            self::assertMatchesRegularExpression("/^<redmine\\.issue-$id\\.\\d{14}\\.\\d+@example\\.net>$/", $h['References'][0]);
            self::assertArrayNotHasKey('In-Reply-To', $h);
            self::assertSame(['Redmine Admin', 'redmine@example.net'], [$edit['From']['Name'], $edit['From']['Address']]);
            $full = $this->mailpit->request('GET', '/api/v1/message/'.$edit['ID'])->toArray();
            self::assertMatchesRegularExpression("#^Issue \\#$id has been updated by Redmine Admin\\.\r\n\r\nStatus changed from New to In Progress\r\n\r\nAdmin note\r\n#", $full['Text']);
            self::assertMatchesRegularExpression("#http://localhost:3000/issues/$id\\#change-\\d+\r\n#", $full['Text']);
        }
    }
}

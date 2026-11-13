<?php

declare(strict_types=1);

namespace App\Tests\Integration\RealServices;

use App\Entity\Project;
use App\Entity\ProjectWebhook;
use App\Entity\User;
use App\Entity\Webhook;
use App\Webhook\WebhookDispatcher;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Webhook deliveries to a real HTTP receiver (fixtures/webhook-receiver.php in a container).
 * SYMINE_TEST_WEBHOOK_RECEIVER_URL: URL the application posts to; it must not be a loopback address
 * (SSRF protection), e.g. http://<LAN IP>:18190. SYMINE_TEST_WEBHOOK_INSPECT_URL (default: the receiver
 * URL): where the test reads the recorded requests (GET /_requests).
 * Payload structures were compared with the reference Redmine posting to the same receiver.
 */
final class WebhookTest extends RealServicesTestCase
{
    private const string SECRET = 's3cr3t-g6';

    /** Redmine's WebhookPayload key structure (from the reference instance) */
    private const array SHAPES = [
        'issue' => ['id', 'project' => ['id', 'name'], 'tracker' => ['id', 'name'], 'status' => ['id', 'name', 'is_closed'], 'priority' => ['id', 'name'], 'author' => ['id', 'name'],
            'assigned_to' => ['id', 'name'], 'subject', 'description', 'start_date', 'due_date', 'done_ratio', 'is_private', 'estimated_hours', 'total_estimated_hours',
            'custom_fields', 'created_on', 'updated_on', 'closed_on'],
        'wiki_page' => ['title', 'text', 'version', 'author' => ['id', 'name'], 'comments', 'project' => ['id', 'name'], 'created_on', 'updated_on'],
        'time_entry' => ['id', 'project' => ['id', 'name'], 'issue' => ['id'], 'user' => ['id', 'name'], 'activity' => ['id', 'name'], 'hours', 'comments', 'spent_on', 'created_on', 'updated_on'],
        'news' => ['id', 'project' => ['id', 'name'], 'author' => ['id', 'name'], 'title', 'summary', 'description', 'created_on'],
        'version' => ['id', 'project' => ['id', 'name'], 'name', 'description', 'status', 'due_date', 'sharing', 'wiki_page_title', 'created_on', 'updated_on'],
    ];

    private HttpClientInterface $inspect;

    protected function setUp(): void
    {
        $receiver = self::env('SYMINE_TEST_WEBHOOK_RECEIVER_URL');
        $this->inspect = HttpClient::createForBaseUri(rtrim(self::optionalEnv('SYMINE_TEST_WEBHOOK_INSPECT_URL', $receiver), '/'));
        parent::setUp();
        $this->inspect->request('DELETE', '/_requests')->getStatusCode();

        $this->em->getConnection()->executeStatement("DELETE FROM settings WHERE name = 'webhooks_enabled'");
        $this->em->getConnection()->executeStatement("INSERT INTO settings (name, value, updated_on) VALUES ('webhooks_enabled', '1', CURRENT_TIMESTAMP)");
        $hook = new Webhook();
        $hook->url = rtrim($receiver, '/').'/hook';
        $hook->secret = self::SECRET;
        $hook->active = true;
        $hook->user = $this->user('admin');
        $hook->events = array_merge(...array_map(static fn (string $t) => ["$t.created", "$t.updated", "$t.deleted"], array_keys(self::SHAPES)));
        $this->em->persist($hook);
        $link = new ProjectWebhook();
        $link->webhook = $hook;
        $link->project = $this->em->getRepository(Project::class)->findOneBy(['identifier' => 'ecommerce']);
        $this->em->persist($link);
        $this->em->flush();
    }

    /** @return list<array{type: string, payload: array, headers: array, body: string}> */
    private function deliveries(int $expected): array
    {
        $deadline = microtime(true) + 10;
        do {
            $requests = $this->inspect->request('GET', '/_requests')->toArray();
            if (\count($requests) >= $expected) {
                break;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        return array_map(static fn (array $r) => ['type' => json_decode($r['body'], true)['type'] ?? null, 'payload' => json_decode($r['body'], true), 'headers' => $r['headers'], 'body' => $r['body'], 'path' => $r['path']], $requests);
    }

    private function assertShape(array $expected, array $actual, string $path): void
    {
        $keys = [];
        foreach ($expected as $k => $v) {
            $keys[] = \is_int($k) ? $v : $k;
            if (!\is_int($k)) {
                self::assertIsArray($actual[$k] ?? null, "$path.$k");
                $this->assertShape($v, $actual[$k], "$path.$k");
            }
        }
        self::assertSame($keys, array_keys($actual), $path);
    }

    public function testDeliveriesAndSignature(): void
    {
        $r = $this->api('POST', '/issues.json', ['issue' => ['project_id' => 1, 'tracker_id' => 1, 'subject' => 'Hooked', 'assigned_to_id' => 6, 'priority_id' => 2]]);
        self::assertSame(201, $r->getStatusCode(), (string) $r->getContent());
        $issueId = json_decode((string) $r->getContent(), true)['issue']['id'];
        self::assertSame(204, $this->api('PUT', "/issues/$issueId.json", ['issue' => ['notes' => 'Hook note', 'done_ratio' => 40]])->getStatusCode());
        self::assertSame(201, $this->api('PUT', '/projects/ecommerce/wiki/HookPage.json', ['wiki_page' => ['text' => 'v1', 'comments' => 'first']])->getStatusCode());
        self::assertSame(204, $this->api('PUT', '/projects/ecommerce/wiki/HookPage.json', ['wiki_page' => ['text' => 'v2']])->getStatusCode());
        self::assertSame(204, $this->api('POST', '/projects/ecommerce/news.json', ['news' => ['title' => 'Hook news', 'summary' => 's', 'description' => 'd']])->getStatusCode());
        $r = $this->api('POST', '/time_entries.json', ['time_entry' => ['issue_id' => $issueId, 'hours' => 1.5, 'activity_id' => 9, 'spent_on' => '2026-11-06']]);
        self::assertSame(201, $r->getStatusCode(), (string) $r->getContent());
        $teId = json_decode((string) $r->getContent(), true)['time_entry']['id'];
        $r = $this->api('POST', '/projects/ecommerce/versions.json', ['version' => ['name' => 'Hook 1.0']]);
        self::assertSame(201, $r->getStatusCode());
        $versionId = json_decode((string) $r->getContent(), true)['version']['id'];
        self::assertSame(204, $this->api('PUT', "/versions/$versionId.json", ['version' => ['status' => 'locked']])->getStatusCode());
        self::assertSame(204, $this->api('DELETE', "/time_entries/$teId.json")->getStatusCode());
        self::assertSame(204, $this->api('DELETE', "/versions/$versionId.json")->getStatusCode());
        self::assertSame(204, $this->api('DELETE', "/issues/$issueId.json")->getStatusCode());

        $expected = ['issue.created', 'issue.updated', 'wiki_page.created', 'wiki_page.updated', 'news.created', 'time_entry.created', 'version.created',
            'version.updated', 'time_entry.deleted', 'version.deleted', 'issue.deleted'];
        $deliveries = $this->deliveries(\count($expected));
        self::assertSame($expected, array_column($deliveries, 'type'));
        foreach ($deliveries as $d) {
            self::assertSame('/hook', $d['path']);
            self::assertSame('application/json', $d['headers']['content-type']);
            self::assertSame('Redmine', $d['headers']['user-agent']);
            self::assertSame('*/*', $d['headers']['accept']);
            self::assertSame('sha256='.hash_hmac('sha256', $d['body'], self::SECRET), $d['headers']['x-redmine-signature-256'], $d['type']);
            self::assertSame(WebhookDispatcher::signature($d['body'], self::SECRET), $d['headers']['x-redmine-signature-256']);
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $d['payload']['timestamp']);
            [$type] = explode('.', $d['type']);
            $data = $d['payload']['data'];
            $expectedKeys = 'issue.updated' === $d['type'] ? [$type, 'journal'] : [$type];
            self::assertSame($expectedKeys, array_keys($data), $d['type']);
            $shape = self::SHAPES[$type];
            if ('issue' === $type) {
                // category / fixed_version only when set
                $shape = array_filter($shape, static fn ($v, $k) => !\in_array($k, ['category', 'fixed_version'], true), \ARRAY_FILTER_USE_BOTH);
            }
            $this->assertShape($shape, $data[$type], $d['type']);
        }
        $updated = $deliveries[1]['payload']['data'];
        self::assertSame('Hook note', $updated['journal']['notes']);
        self::assertSame(['id', 'created_on', 'notes', 'user', 'details'], array_keys($updated['journal']));
        self::assertSame(40, $updated['issue']['done_ratio']);
        self::assertSame('v2', $deliveries[3]['payload']['data']['wiki_page']['text']);
        self::assertSame(2, $deliveries[3]['payload']['data']['wiki_page']['version']);
        self::assertEquals(1.5, $deliveries[5]['payload']['data']['time_entry']['hours']);
        self::assertSame('locked', $deliveries[7]['payload']['data']['version']['status']);
    }

    public function testHooksAreFilteredByProjectEventAndOwner(): void
    {
        // internal project: not linked to the hook
        $r = $this->api('POST', '/issues.json', ['issue' => ['project_id' => 3, 'tracker_id' => 1, 'subject' => 'Not hooked']]);
        self::assertSame(201, $r->getStatusCode(), (string) $r->getContent());
        // the hook owner is locked: no delivery
        $admin = $this->user('admin');
        $owner = $this->user('jsmith');
        $hook = $this->em->getRepository(Webhook::class)->findOneBy([], ['id' => 'DESC']);
        $hook->user = $owner;
        $owner->status = User::STATUS_LOCKED;
        $this->em->flush();
        self::assertSame(201, $this->api('POST', '/issues.json', ['issue' => ['project_id' => 1, 'tracker_id' => 1, 'subject' => 'Owner locked']])->getStatusCode());
        // wrong receiver answer / unreachable endpoints never break the request
        $hook->user = $admin;
        $owner->status = User::STATUS_ACTIVE;
        $hook->events = ['issue.updated'];
        $this->em->flush();
        self::assertSame(201, $this->api('POST', '/issues.json', ['issue' => ['project_id' => 1, 'tracker_id' => 1, 'subject' => 'Event not subscribed']])->getStatusCode());
        usleep(500_000);
        self::assertSame([], $this->deliveries(0));
    }
}

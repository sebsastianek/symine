<?php

declare(strict_types=1);

namespace App\Tests\Notifications;

use App\Entity\Issue;
use App\Entity\News;
use App\Entity\ProjectWebhook;
use App\Entity\User;
use App\Entity\Webhook;
use App\Event\ContentEvent;
use App\Webhook\WebhookDispatcher;
use App\Webhook\WebhookEndpointValidator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebhookTest extends NotificationTestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requests = [];
        $mock = new MockHttpClient(function (string $method, string $url, array $options) {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('ok', ['http_code' => 200]);
        });
        self::getContainer()->set(HttpClientInterface::class, $mock);
        $this->settings()->set('webhooks_enabled', '1');
        $this->settings()->set('notified_events', []);
        $this->em->flush();
    }

    /** @param list<string> $events */
    private function hook(User $owner, array $events, ?string $secret = 's3cret', string $url = 'http://93.184.216.34/hook'): Webhook
    {
        $h = new Webhook();
        $h->url = $url;
        $h->secret = $secret;
        $h->events = $events;
        $h->user = $owner;
        $h->active = true;
        $link = new ProjectWebhook();
        $link->project = $this->project('ecommerce');
        $link->webhook = $h;
        $h->projectLinks->add($link);
        $this->em->persist($h);
        $this->em->flush();

        return $h;
    }

    /** @return array<string, string> */
    private static function headers(array $options): array
    {
        $out = [];
        foreach ($options['headers'] ?? [] as $line) {
            [$k, $v] = explode(': ', $line, 2);
            $out[strtolower($k)] = $v;
        }

        return $out;
    }

    public function testIssueCreatedAndUpdatedPayloadsWithSignature(): void
    {
        $this->hook($this->user('jsmith'), ['issue.created', 'issue.updated']);
        $issue = $this->createIssue($this->user('dlopper'), 'ecommerce', ['subject' => 'Hooked', 'description' => 'desc']);
        self::assertCount(1, $this->requests);
        $r = $this->requests[0];
        self::assertSame('POST', $r['method']);
        self::assertSame('http://93.184.216.34/hook', $r['url']);
        $body = (string) $r['options']['body'];
        $h = self::headers($r['options']);
        self::assertSame('application/json', $h['content-type']);
        self::assertSame('Redmine', $h['user-agent']);
        self::assertSame('sha256='.hash_hmac('sha256', $body, 's3cret'), $h['x-redmine-signature-256']);
        $payload = json_decode($body, true);
        self::assertSame('issue.created', $payload['type']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $payload['timestamp']);
        self::assertSame($issue->id, $payload['data']['issue']['id']);
        self::assertSame('Hooked', $payload['data']['issue']['subject']);
        self::assertSame(['id' => 1, 'name' => 'eCommerce'], $payload['data']['issue']['project']);
        self::assertSame(['id' => 6, 'name' => 'Dave Lopper'], $payload['data']['issue']['author']);
        self::assertArrayNotHasKey('spent_hours', $payload['data']['issue']);
        self::assertSame('Database', $payload['data']['issue']['custom_fields'][0]['name']);

        $this->requests = [];
        $this->updateIssue($issue, $this->user('dlopper'), ['notes' => 'hello hook', 'done_ratio' => 40]);
        $payload = json_decode((string) $this->requests[0]['options']['body'], true);
        self::assertSame('issue.updated', $payload['type']);
        self::assertSame('hello hook', $payload['data']['journal']['notes']);
        self::assertSame(['property' => 'attr', 'prop_key' => 'done_ratio', 'old_value' => '0', 'value' => '40'], $payload['data']['journal']['details'][0]);
        self::assertSame(['id' => 6, 'name' => 'Dave Lopper'], $payload['data']['journal']['user']);
    }

    public function testFilteringByEventVisibilityPermissionAndSetting(): void
    {
        // rhill (Reporter) has no use_webhooks permission
        $this->hook($this->user('rhill'), ['issue.created']);
        // jsmith only subscribed to updates
        $this->hook($this->user('jsmith'), ['issue.updated'], null);
        $this->createIssue($this->user('dlopper'), 'ecommerce', []);
        self::assertSame([], $this->requests);

        $issue = $this->em->find(Issue::class, 13);
        $this->updateIssue($issue, $this->user('dlopper'), ['notes' => 'x']);
        self::assertCount(1, $this->requests);
        self::assertArrayNotHasKey('x-redmine-signature-256', self::headers($this->requests[0]['options']));

        $this->requests = [];
        $this->settings()->set('webhooks_enabled', '0');
        $this->em->flush();
        $this->updateIssue($issue, $this->user('dlopper'), ['notes' => 'y']);
        self::assertSame([], $this->requests);
    }

    public function testNewsPayloadAndPrivateNotesHidden(): void
    {
        $this->hook($this->user('jsmith'), ['news.created']);
        $news = $this->em->find(News::class, 1);
        $this->service(EventDispatcherInterface::class)->dispatch(new ContentEvent('news', 'created', $news, $this->user('jsmith')));
        $payload = json_decode((string) $this->requests[0]['options']['body'], true);
        self::assertSame('news.created', $payload['type']);
        self::assertSame($news->title, $payload['data']['news']['title']);
        self::assertSame(['id' => 5, 'name' => 'John Smith'], $payload['data']['news']['author']);
    }

    public function testFailuresAreLoggedAndUnsafeEndpointsRefused(): void
    {
        $dispatcher = $this->service(WebhookDispatcher::class);
        self::assertFalse($dispatcher->call(1, 'http://127.0.0.1/hook', '{}'));
        self::assertFalse($dispatcher->call(1, 'http://93.184.216.34:22/hook', '{}'));
        self::assertFalse($dispatcher->call(1, 'ftp://93.184.216.34/hook', '{}'));
        self::assertSame([], $this->requests);

        $failing = new MockHttpClient(new MockResponse('boom', ['http_code' => 500]));
        $validator = new WebhookEndpointValidator([]);
        self::assertTrue($validator->isSafe('https://93.184.216.34/x'));
        self::assertFalse($validator->isSafe('http://[::1]/x'));
        self::assertFalse($validator->isSafe('http://169.254.169.254/latest/meta-data'));
        self::assertFalse((new WebhookEndpointValidator(['93.184.0.0/16']))->isSafe('http://93.184.216.34/x'));
        self::assertTrue(WebhookEndpointValidator::inCidr('10.1.2.3', '10.0.0.0/8'));
        self::assertSame('sha256='.hash_hmac('sha256', 'abc', 'k'), WebhookDispatcher::signature('abc', 'k'));
        unset($failing);
    }
}

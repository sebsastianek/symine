<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\Webhook;
use App\Service\Admin\WebhookManager;
use App\Service\Settings;

final class WebhooksTest extends AdminWebTestCase
{
    private function enable(bool $on = true): void
    {
        self::getContainer()->get(Settings::class)->setMany(['webhooks_enabled' => $on ? '1' : '0']);
    }

    private function projectId(string $identifier): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT id FROM projects WHERE identifier = ?', [$identifier]);
    }

    public function testDisabled(): void
    {
        $this->loginAs('jsmith');
        $this->get('/webhooks');
        $this->assertStatus(403);
        $this->loginAs('admin');
        $crawler = $this->get('/admin/webhooks');
        $this->assertStatus(200);
        self::assertCount(1, $crawler->filter('p.warning'));
    }

    public function testCrudAsMember(): void
    {
        $this->enable();
        $this->loginAs('jsmith');
        $crawler = $this->get('/webhooks/new');
        $this->assertStatus(200);
        // jsmith is Manager (use_webhooks) on ecommerce and checkout only
        self::assertCount(2, $crawler->filter('#webhook_project_ids input[type=checkbox]'));

        $this->post('/webhooks', ['webhook' => ['url' => 'http://127.0.0.1/hook', 'events' => ['issue.created'], 'project_ids' => ['']]]);
        $this->assertStatus(422);
        self::assertStringContainsString('URL is invalid', (string) $this->client->getResponse()->getContent());
        $this->post('/webhooks', ['webhook' => ['url' => 'http://93.184.216.34/hook', 'events' => ['bogus.event']]]);
        $this->assertStatus(422);

        $this->post('/webhooks', ['webhook' => [
            'url' => 'https://93.184.216.34/hook', 'secret' => 's3cret', 'active' => '1',
            'events' => ['issue.created', 'issue.updated', ''],
            'project_ids' => [(string) $this->projectId('ecommerce'), (string) $this->projectId('internal'), ''],
        ]]);
        $this->assertRedirectTo('/webhooks');
        $this->em->clear();
        $hook = $this->em->getRepository(Webhook::class)->findOneBy(['url' => 'https://93.184.216.34/hook']);
        self::assertNotNull($hook);
        self::assertSame(['issue.created', 'issue.updated'], $hook->getEvents());
        self::assertTrue($hook->active);
        self::assertSame('s3cret', $hook->secret);
        self::assertSame([$this->projectId('ecommerce')], array_map(static fn ($l) => $l->project->id, $hook->projectLinks->toArray()), 'internal is not setable');

        $crawler = $this->get('/webhooks');
        self::assertCount(1, $crawler->filter('#webhook_'.$hook->id));

        // admin edits it: the secret of another user is kept when blank
        $this->loginAs('admin');
        $this->post('/webhooks/'.$hook->id, ['webhook' => ['url' => 'https://93.184.216.34/other', 'secret' => '', 'active' => '0', 'events' => ['news.created'], 'project_ids' => [(string) $this->projectId('checkout')]], 'back_url' => '/admin/webhooks'], 'PATCH');
        $this->assertRedirectTo('/admin/webhooks');
        $this->em->clear();
        $hook = $this->em->find(Webhook::class, $hook->id);
        self::assertSame('s3cret', $hook->secret);
        self::assertFalse($hook->active);
        self::assertSame(['news.created'], $hook->getEvents());
        self::assertSame([$this->projectId('checkout')], array_map(static fn ($l) => $l->project->id, $hook->projectLinks->toArray()));
        $crawler = $this->get('/admin/webhooks');
        self::assertCount(1, $crawler->filter('#webhook_'.$hook->id));

        // another member cannot edit it
        $this->loginAs('dlopper');
        $this->get('/webhooks/'.$hook->id.'/edit');
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);

        $this->loginAs('jsmith');
        $this->post('/webhooks/'.$hook->id, [], 'DELETE');
        $this->assertRedirectTo('/webhooks');
        $this->em->clear();
        self::assertNull($this->em->find(Webhook::class, $hook->id));
    }

    public function testUrlValidation(): void
    {
        self::assertTrue(WebhookManager::isSafeUrl('https://93.184.216.34/x'));
        self::assertTrue(WebhookManager::isSafeUrl('http://93.184.216.34:8080/x'));
        self::assertFalse(WebhookManager::isSafeUrl('ftp://93.184.216.34/x'));
        self::assertFalse(WebhookManager::isSafeUrl('http://93.184.216.34:22/x'));
        self::assertFalse(WebhookManager::isSafeUrl('http://127.0.0.1/x'));
        self::assertFalse(WebhookManager::isSafeUrl('http://169.254.169.254/latest'));
        self::assertFalse(WebhookManager::isSafeUrl('http://0.0.0.0/'));
        self::assertFalse(WebhookManager::isSafeUrl('http://[::1]/'));
        self::assertFalse(WebhookManager::isSafeUrl('http://[::ffff:127.0.0.1]/'));
        self::assertFalse(WebhookManager::isSafeUrl('not a url'));
    }
}

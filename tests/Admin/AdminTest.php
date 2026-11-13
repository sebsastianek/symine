<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\IssuePriority;
use App\Entity\Role;
use App\Service\Admin\DefaultDataLoader;

final class AdminTest extends AdminWebTestCase
{
    private const array PAGES = ['/admin', '/admin/projects', '/admin/projects?status=1&name=e', '/admin/info', '/admin/plugins', '/admin/webhooks', '/auth_sources', '/auth_sources/new'];

    public function testAdminPagesAsAdmin(): void
    {
        $this->loginAs('admin');
        foreach (self::PAGES as $url) {
            $this->get($url);
            $this->assertStatus(200, $url);
        }
        $crawler = $this->get('/admin');
        self::assertCount(1, $crawler->filter('#admin-index #admin-menu a.users'));
        self::assertCount(0, $crawler->filter('#admin-index .nodata'), 'configuration already loaded');
        $crawler = $this->get('/admin/projects');
        self::assertGreaterThan(0, $crawler->filter('table.list.projects tbody tr')->count());
        $crawler = $this->get('/admin/info');
        self::assertGreaterThan(3, $crawler->filter('table.list tr')->count());
    }

    public function testAdminPagesForbiddenForNonAdmin(): void
    {
        $this->loginAs('jsmith');
        foreach (self::PAGES as $url) {
            $this->get($url);
            $this->assertStatus(403, $url);
        }
    }

    public function testAdminPagesRequireLogin(): void
    {
        $this->get('/admin');
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testProjectsFilter(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/admin/projects?status=9');
        $this->assertStatus(200);
        foreach ($crawler->filter('table.list.projects tbody tr td.status') as $td) {
            self::assertSame('archived', trim($td->textContent));
        }
    }

    public function testLoadDefaultConfiguration(): void
    {
        $this->wipeConfiguration();
        $this->loginAs('admin');
        $crawler = $this->get('/admin');
        self::assertCount(1, $crawler->filter('#admin-index .nodata form select#lang'));
        $this->post('/admin/default_configuration', ['lang' => 'en']);
        $this->assertRedirectTo('/admin');
        $this->em->clear();
        self::assertSame(['Manager', 'Developer', 'Reporter'], array_map(static fn (Role $r) => $r->name, $this->em->getRepository(Role::class)->findBy(['builtin' => 0], ['position' => 'ASC'])));
        $this->assertWorkflowCounts();
        $names = [];
        foreach ($this->em->getRepository(IssuePriority::class)->findBy([], ['position' => 'ASC']) as $p) {
            $names[$p->name] = $p->positionName;
        }
        // same values as the reference Redmine instance
        self::assertSame(['Low' => 'lowest', 'Normal' => 'default', 'High' => 'high3', 'Urgent' => 'high2', 'Immediate' => 'highest'], $names);
        // a second load is refused
        $this->post('/admin/default_configuration', ['lang' => 'en']);
        $this->client->followRedirect();
        self::assertStringContainsString('Default configuration could not be loaded', (string) $this->client->getResponse()->getContent());
    }

    public function testLoadDefaultDataCommandInFrench(): void
    {
        $this->wipeConfiguration();
        $app = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($app->find('symine:load-default-data'));
        self::assertSame(0, $tester->execute(['--lang' => 'fr']));
        $this->em->clear();
        $trackers = array_column($this->em->getConnection()->fetchAllAssociative('SELECT name FROM trackers ORDER BY position'), 'name');
        self::assertSame(['Anomalie', 'Evolution', 'Assistance'], $trackers);
        $this->assertWorkflowCounts();
        self::assertSame(1, $tester->execute([]), 'refused when data exists');
    }

    public function testTestEmail(): void
    {
        $this->loginAs('admin');
        $this->post('/admin/test_email');
        $this->assertRedirectTo('/settings?tab=notifications');
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', (string) $this->user('admin')->getMail());
    }

    private function assertWorkflowCounts(): void
    {
        // Manager: all transitions, Developer and Reporter: Redmine's default workflow (3 trackers)
        $rows = $this->em->getConnection()->fetchAllKeyValue("SELECT r.position, COUNT(w.id) FROM workflows w JOIN roles r ON r.id = w.role_id WHERE w.type = 'WorkflowTransition' GROUP BY r.position ORDER BY r.position");
        self::assertEquals([1 => 90, 2 => 39, 3 => 15], array_map('intval', $rows));
    }

    private function wipeConfiguration(): void
    {
        $c = $this->em->getConnection();
        foreach (['DELETE FROM workflows', 'DELETE FROM member_roles', 'DELETE FROM members', 'DELETE FROM roles WHERE builtin = 0', 'DELETE FROM trackers', 'DELETE FROM issue_statuses', 'DELETE FROM enumerations', 'DELETE FROM queries', 'DELETE FROM queries_roles', "DELETE FROM settings WHERE name = 'default_projects_tracker_ids'"] as $sql) {
            $c->executeStatement($sql);
        }
        self::assertTrue(self::getContainer()->get(DefaultDataLoader::class)->noData());
    }
}

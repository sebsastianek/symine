<?php

declare(strict_types=1);

namespace App\Tests\Projects;

use App\Entity\Member;
use App\Entity\Project;

final class ProjectsTest extends ProjectsWebTestCase
{
    private function project(string $identifier): ?Project
    {
        $this->em->clear();

        return $this->em->getRepository(Project::class)->findOneBy(['identifier' => $identifier]);
    }

    public function testPagesForAdminAndJsmith(): void
    {
        foreach (['admin', 'jsmith'] as $login) {
            $this->login($login);
            foreach (['/projects', '/projects?set_filter=1&display_type=list&c[]=name&c[]=status&c[]=cf_5&group_by=is_public', '/projects/ecommerce', '/projects/ecommerce/settings', '/projects/ecommerce/settings/members', '/projects/new', '/projects/autocomplete.js?q=e', '/projects.atom'] as $url) {
                $this->get($url);
                self::assertSame(200, $this->code(), "$login $url");
            }
        }
    }

    public function testAnonymousSeesPublicProjectsOnly(): void
    {
        $html = $this->get('/projects');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('eCommerce', $html);
        self::assertStringNotContainsString('Internal Tools', $html);
        $this->get('/projects/internal');
        self::assertSame(302, $this->code());
    }

    public function testOverviewContent(): void
    {
        $this->login('admin');
        $html = $this->get('/projects/ecommerce');
        self::assertStringContainsString('<div class="issues box">', $html);
        self::assertStringContainsString('<span class="label">Manager:</span> <a class="user active" href="/users/5">John Smith</a>', $html);
        self::assertStringContainsString('<li class="string_cf cf_5"><span class="label">Customer:</span> ACME Corp</li>', $html);
        self::assertStringContainsString('<a class="project child leaf public" href="/projects/checkout">Checkout</a>', $html);
        self::assertMatchesRegularExpression('/tracker_id=1">2<\/a>/', $html);
    }

    public function testBoardAndListViews(): void
    {
        $this->login('jsmith');
        $html = $this->get('/projects');
        self::assertStringContainsString("<ul class='projects root'>", $html);
        self::assertStringContainsString('icon-user my-project', $html);
        $html = $this->get('/projects?set_filter=1&display_type=list&f[]=name&op[name]=~&v[name][]=check&f[]=');
        self::assertStringContainsString('<table class="list projects', $html);
        self::assertStringContainsString('Checkout', $html);
        self::assertStringNotContainsString('href="/projects/internal"', substr($html, (int) strpos($html, '<table class="list projects')));
    }

    public function testCreateProjectAsAdmin(): void
    {
        $this->login('admin');
        $code = $this->submit('/projects', ['project' => [
            'name' => 'Mobile app', 'identifier' => 'mobile', 'description' => 'desc', 'is_public' => '0',
            'parent_id' => '1', 'enabled_module_names' => ['issue_tracking', 'wiki', ''], 'tracker_ids' => ['1', ''],
            'custom_field_values' => ['5' => 'Globex'],
        ]]);
        self::assertSame(302, $code);
        self::assertStringEndsWith('/projects/mobile/settings', (string) $this->client->getResponse()->headers->get('Location'));
        $p = $this->project('mobile');
        self::assertNotNull($p);
        self::assertFalse($p->isPublic);
        self::assertSame(1, $p->parent?->id);
        self::assertEqualsCanonicalizing(['issue_tracking', 'wiki'], $p->getEnabledModuleNames());
        self::assertCount(1, $p->trackers);
        self::assertTrue($p->lft > $p->parent->lft && $p->rgt < $p->parent->rgt, 'nested set rebuilt');
        $cv = $this->em->getConnection()->fetchOne("SELECT value FROM custom_values WHERE customized_type = 'Project' AND customized_id = ? AND custom_field_id = 5", [$p->id]);
        self::assertSame('Globex', $cv);
    }

    public function testCreateValidation(): void
    {
        $this->login('admin');
        $this->submit('/projects', ['project' => ['name' => '', 'identifier' => 'ecommerce']]);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(200, $this->code());
        self::assertStringContainsString('errorExplanation', $html);
        self::assertStringContainsString('Name cannot be blank', $html);
        self::assertStringContainsString('Identifier has already been taken', $html);
    }

    public function testNonAdminCreatorBecomesMember(): void
    {
        $this->login('jsmith');
        $code = $this->submit('/projects', ['project' => ['name' => 'Side', 'identifier' => 'side']]);
        self::assertSame(302, $code);
        $p = $this->project('side');
        self::assertNotNull($p);
        $m = $this->em->getRepository(Member::class)->findOneBy(['project' => $p]);
        self::assertNotNull($m);
        self::assertSame('jsmith', $m->principal->login);
        self::assertNotEmpty($m->getRoles());
    }

    public function testUpdateSettingsKeepsIdentifier(): void
    {
        $this->login('admin');
        $code = $this->submit('/projects/ecommerce', ['_method' => 'PATCH', 'project' => ['name' => 'eShop', 'identifier' => 'changed', 'homepage' => 'https://shop.example']]);
        self::assertSame(302, $code);
        $p = $this->project('ecommerce');
        self::assertSame('eShop', $p->name);
        self::assertSame('https://shop.example', $p->homepage);
        // issue tracking tab
        $this->submit('/projects/ecommerce', ['_method' => 'PATCH', 'tab' => 'issues', 'project' => ['tracker_ids' => ['1', '2', ''], 'default_version_id' => '1', 'default_assigned_to_id' => '6']]);
        self::assertStringEndsWith('/projects/ecommerce/settings/issues', (string) $this->client->getResponse()->headers->get('Location'));
        $p = $this->project('ecommerce');
        self::assertCount(2, $p->trackers);
        self::assertSame(1, $p->defaultVersion?->id);
        self::assertSame(6, $p->defaultAssignedTo?->id);
    }

    public function testCloseReopenArchiveUnarchive(): void
    {
        $this->login('admin');
        $this->submit('/projects/ecommerce/close');
        self::assertSame(Project::STATUS_CLOSED, $this->project('ecommerce')->status);
        self::assertSame(Project::STATUS_CLOSED, $this->project('checkout')->status, 'descendants closed');
        $this->submit('/projects/ecommerce/reopen');
        self::assertSame(Project::STATUS_ACTIVE, $this->project('checkout')->status);
        $this->submit('/projects/internal/archive');
        self::assertSame(Project::STATUS_ARCHIVED, $this->project('internal')->status);
        $this->submit('/projects/internal/unarchive');
        self::assertSame(Project::STATUS_ACTIVE, $this->project('internal')->status);
    }

    public function testJsmithCannotArchive(): void
    {
        $this->login('jsmith');
        $this->submit('/projects/ecommerce/archive');
        self::assertSame(403, $this->code());
    }

    public function testDestroyWithConfirmation(): void
    {
        $this->login('admin');
        $this->submit('/projects/ecommerce', ['_method' => 'DELETE']);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('Checkout', (string) $this->client->getResponse()->getContent());
        self::assertNotNull($this->project('ecommerce'));
        $this->submit('/projects/ecommerce', ['_method' => 'DELETE', 'confirm' => 'ecommerce']);
        self::assertSame(302, $this->code());
        self::assertNull($this->project('ecommerce'));
        self::assertNull($this->project('checkout'));
        $conn = $this->em->getConnection();
        foreach (['issues', 'versions', 'issue_categories', 'members', 'news', 'boards', 'documents', 'time_entries', 'wikis', 'enabled_modules'] as $table) {
            self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM $table WHERE project_id IN (1, 3)"), $table);
        }
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM custom_values WHERE customized_type = 'Project' AND customized_id IN (1, 3)"));
        // tree still consistent
        $internal = $this->project('internal');
        self::assertSame(1, $internal->lft);
    }

    public function testCopyProject(): void
    {
        $this->login('admin');
        $this->get('/projects/ecommerce/copy');
        self::assertSame(200, $this->code());
        $code = $this->submit('/projects/ecommerce/copy', [
            'project' => ['name' => 'eCommerce copy', 'identifier' => 'ecommerce-copy', 'tracker_ids' => ['1', '2', '3']],
            'only' => ['members', 'versions', 'issue_categories', 'issues', 'boards', 'wiki', 'queries', 'documents'],
        ]);
        self::assertSame(302, $code, (string) $this->client->getResponse()->getContent());
        $copy = $this->project('ecommerce-copy');
        self::assertNotNull($copy);
        $conn = $this->em->getConnection();
        self::assertSame((int) $conn->fetchOne('SELECT COUNT(*) FROM versions WHERE project_id = 1'), (int) $conn->fetchOne('SELECT COUNT(*) FROM versions WHERE project_id = ?', [$copy->id]));
        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM issue_categories WHERE project_id = ?', [$copy->id]));
        self::assertGreaterThan(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM members WHERE project_id = ?', [$copy->id]));
        self::assertGreaterThan(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM issues WHERE project_id = ?', [$copy->id]));
    }

    public function testBookmark(): void
    {
        $this->login('jsmith');
        $this->submit('/projects/ecommerce/bookmark');
        self::assertSame(302, $this->code());
        $html = $this->get('/projects/ecommerce');
        self::assertStringContainsString('icon-bookmark"', $html);
        $this->submit('/projects/ecommerce/bookmark', ['_method' => 'DELETE']);
        $html = $this->get('/projects/ecommerce');
        self::assertStringContainsString('icon-bookmark-off', $html);
    }

    public function testAutocomplete(): void
    {
        $this->login('jsmith');
        $js = $this->get('/projects/autocomplete.js?q=check');
        self::assertStringContainsString("#project-jump .drdn-items.projects", $js);
        self::assertStringContainsString('Checkout', $js);
        self::assertStringNotContainsString('Internal', $js);
    }

    public function testActivitiesOverride(): void
    {
        $this->login('admin');
        $this->submit('/projects/ecommerce/enumerations', ['_method' => 'PUT', 'enumerations' => ['8' => ['parent_id' => '8', 'active' => '0'], '9' => ['parent_id' => '9', 'active' => '1']]]);
        self::assertSame(302, $this->code());
        $conn = $this->em->getConnection();
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM enumerations WHERE project_id = 1 AND parent_id = 8 AND active = 0"));
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM enumerations WHERE project_id = 1 AND parent_id = 9"));
        $data = $this->api('GET', '/projects/ecommerce.json?include=time_entry_activities');
        self::assertSame(['Development'], array_column($data['project']['time_entry_activities'], 'name'));
        $this->submit('/projects/ecommerce/enumerations', ['_method' => 'DELETE']);
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM enumerations WHERE project_id = 1'));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Projects;

use App\Entity\Project;
use App\Entity\Version;

final class VersionsTest extends ProjectsWebTestCase
{
    public function testPagesReturn200ForAdminAndJsmith(): void
    {
        foreach (['admin', 'jsmith'] as $login) {
            $this->login($login);
            foreach (['/projects/ecommerce/roadmap', '/projects/ecommerce/versions', '/versions/1', '/versions/2', '/projects/ecommerce/versions/new', '/versions/1/edit'] as $url) {
                $this->get($url);
                self::assertSame(200, $this->code(), "$login $url");
            }
        }
    }

    public function testRoadmapListsOpenVersionsAndHidesCompleted(): void
    {
        $this->login('jsmith');
        // The fixture has a fixed due date: make it relative to today
        $this->em->find(Version::class, 1)->effectiveDate = new \DateTimeImmutable('today +30 days');
        $this->em->flush();
        $html = $this->get('/projects/ecommerce/roadmap');
        self::assertStringContainsString('<header id="version-1">', $html);
        self::assertStringNotContainsString('<header id="version-2">', $html);
        self::assertStringContainsString('id="toggle-completed-versions"', $html);
        self::assertStringContainsString('Due in 30 days', $html);
        // Feature is the only tracker in roadmap by default: the bug #13 is not listed
        self::assertStringNotContainsString('related-issues', $html);

        $html = $this->get('/projects/ecommerce/roadmap?completed=1&tracker_ids[]=1');
        self::assertStringContainsString('<header id="version-2">', $html);
        self::assertStringContainsString('value="13"', $html);
    }

    public function testRoadmapRequiresViewIssues(): void
    {
        $this->get('/projects/internal/roadmap');
        self::assertContains($this->code(), [302, 401, 403]);
    }

    public function testShowAndStatusBy(): void
    {
        $this->login('jsmith');
        $html = $this->get('/versions/1');
        self::assertStringContainsString('<h2 class="inline-flex">1.0</h2>', $html);
        self::assertStringContainsString('<span class="hours hours-int">8</span>', $html);
        self::assertStringContainsString('id="status_by_select"', $html);

        $token = $this->csrf('/versions/1');
        $this->client->request('POST', '/versions/1/status_by', ['status_by' => 'category', '_token' => $token], [], ['HTTP_ACCEPT' => 'text/javascript']);
        self::assertSame(200, $this->code());
        $js = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("$('#status_by').html(", $js);
        self::assertStringContainsString('UI', $js);

        $this->client->request('GET', '/versions/1.txt');
        self::assertStringContainsString('# 1.0', (string) $this->client->getResponse()->getContent());
    }

    public function testCreateUpdateDestroyFromForms(): void
    {
        $this->login('jsmith');
        $code = $this->submit('/projects/ecommerce/versions', ['version' => [
            'name' => '2.0', 'description' => 'Next', 'effective_date' => '2027-01-15', 'sharing' => 'system', 'default_project_version' => '1',
        ]]);
        self::assertSame(302, $code);
        self::assertStringContainsString('/projects/ecommerce/settings', (string) $this->client->getResponse()->headers->get('Location'));
        $this->em->clear();
        $v = $this->em->getRepository(Version::class)->findOneBy(['name' => '2.0']);
        self::assertNotNull($v);
        self::assertSame('none', $v->sharing, 'non admin cannot share with all projects');
        self::assertSame('2027-01-15', $v->effectiveDate->format('Y-m-d'));
        self::assertSame($v->id, $this->em->find(Project::class, 1)->defaultVersion?->id);

        // duplicate name
        $code = $this->submit('/projects/ecommerce/versions', ['version' => ['name' => '2.0']]);
        self::assertSame(422, $code);
        self::assertStringContainsString('errorExplanation', (string) $this->client->getResponse()->getContent());

        // invalid date
        $this->submit('/versions/'.$v->id, ['_method' => 'PATCH', 'version' => ['effective_date' => '2027-02-31']]);
        self::assertSame(422, $this->code());

        $code = $this->submit('/versions/'.$v->id, ['_method' => 'PATCH', 'version' => ['name' => '2.0.1', 'status' => 'locked', 'sharing' => 'tree']]);
        self::assertSame(302, $code);
        $this->em->clear();
        $v = $this->em->find(Version::class, $v->id);
        self::assertSame('2.0.1', $v->name);
        self::assertSame('locked', $v->status);
        self::assertSame('tree', $v->sharing);

        $this->submit('/versions/'.$v->id, ['_method' => 'DELETE']);
        self::assertSame(302, $this->code());
        $this->em->clear();
        self::assertNull($this->em->find(Version::class, $v->id));
        self::assertNull($this->em->find(Project::class, 1)->defaultVersion);

        // version 1 has issues: not deletable
        $this->submit('/versions/1', ['_method' => 'DELETE']);
        self::assertSame(302, $this->code());
        $this->em->clear();
        self::assertNotNull($this->em->find(Version::class, 1));
    }

    public function testCloseCompleted(): void
    {
        $this->em->getConnection()->executeStatement("INSERT INTO versions (project_id, name, description, effective_date, status, sharing, created_on, updated_on) VALUES (1, 'old', '', '2020-01-01', 'open', 'none', datetime('now'), datetime('now'))");
        $id = (int) $this->em->getConnection()->lastInsertId();
        $this->login('jsmith');
        $this->submit('/projects/ecommerce/versions/close_completed', ['_method' => 'PUT']);
        self::assertSame(302, $this->code());
        $this->em->clear();
        self::assertSame('closed', $this->em->find(Version::class, $id)->status);
        self::assertSame('open', $this->em->find(Version::class, 1)->status);
    }

    public function testReporterCannotManageVersions(): void
    {
        $this->login('rhill');
        $this->get('/projects/ecommerce/roadmap');
        self::assertSame(200, $this->code());
        $this->get('/projects/ecommerce/versions/new');
        self::assertSame(403, $this->code());
        $this->get('/versions/1/edit');
        self::assertSame(403, $this->code());
    }

    public function testApi(): void
    {
        $data = $this->api('GET', '/projects/ecommerce/versions.json');
        self::assertSame(2, $data['total_count']);
        self::assertSame(['id' => 1, 'name' => 'eCommerce'], $data['versions'][0]['project']);
        self::assertSame('descendants', $data['versions'][0]['sharing']);

        $data = $this->api('GET', '/versions/1.json');
        self::assertSame('1.0', $data['version']['name']);
        self::assertSame(8.0, $data['version']['estimated_hours']);
        self::assertSame(2.5, $data['version']['spent_hours']);

        $data = $this->api('POST', '/projects/ecommerce/versions.json', ['version' => ['name' => 'API', 'due_date' => '2027-03-01', 'sharing' => 'descendants']]);
        self::assertSame(201, $this->code());
        self::assertSame('API', $data['version']['name']);
        self::assertSame('2027-03-01', $data['version']['due_date']);
        $id = $data['version']['id'];
        self::assertStringEndsWith('/versions/'.$id, (string) $this->client->getResponse()->headers->get('Location'));

        $data = $this->api('POST', '/projects/ecommerce/versions.json', ['version' => ['name' => '']]);
        self::assertSame(422, $this->code());
        self::assertSame(['Name cannot be blank'], $data['errors']);

        $this->api('PUT', '/versions/'.$id.'.json', ['version' => ['status' => 'closed']]);
        self::assertSame(204, $this->code());
        self::assertSame('closed', $this->api('GET', '/versions/'.$id.'.json')['version']['status']);

        $this->api('DELETE', '/versions/1.json');
        self::assertSame(422, $this->code());
        $this->api('DELETE', '/versions/'.$id.'.json');
        self::assertSame(204, $this->code());
        $this->api('GET', '/versions/'.$id.'.json');
        self::assertSame(404, $this->code());

        $this->client->request('GET', '/versions/1.xml', [], [], ['HTTP_X_REDMINE_API_KEY' => '3de6a93a7c5f8921fa98e0fecd9ae25695aa7536']);
        self::assertStringContainsString('<project id="1" name="eCommerce"/>', (string) $this->client->getResponse()->getContent());
    }

    public function testSharingChangeUnassignsIssuesOfOtherProjects(): void
    {
        // issue 17 belongs to the subproject "checkout" and uses the shared version 1.0
        $this->em->getConnection()->executeStatement('UPDATE issues SET fixed_version_id = 1 WHERE id = 17');
        $this->api('PUT', '/versions/1.json', ['version' => ['sharing' => 'none']]);
        self::assertSame(204, $this->code());
        self::assertNull($this->em->getConnection()->fetchOne('SELECT fixed_version_id FROM issues WHERE id = 17'));
    }
}

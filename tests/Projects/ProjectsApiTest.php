<?php

declare(strict_types=1);

namespace App\Tests\Projects;

use App\Entity\Project;

final class ProjectsApiTest extends ProjectsWebTestCase
{
    public function testIndexMatchesReference(): void
    {
        $data = $this->api('GET', '/projects.json');
        self::assertSame(200, $this->code());
        self::assertSame(3, $data['total_count']);
        self::assertSame(['internal', 'ecommerce', 'checkout'], array_column($data['projects'], 'identifier'));
        $checkout = $data['projects'][2];
        self::assertSame(['id' => 1, 'name' => 'eCommerce'], $checkout['parent']);
        self::assertSame([['id' => 5, 'name' => 'Customer', 'value' => null]], $checkout['custom_fields']);
        self::assertSame(['id', 'name', 'identifier', 'description', 'homepage', 'parent', 'status', 'is_public', 'inherit_members', 'custom_fields', 'created_on', 'updated_on'], array_keys($checkout));
    }

    public function testShowWithIncludes(): void
    {
        $data = $this->api('GET', '/projects/ecommerce.json?include=trackers,issue_categories,enabled_modules,time_entry_activities,issue_custom_fields');
        $p = $data['project'];
        self::assertSame(['Bug', 'Feature', 'Support'], array_column($p['trackers'], 'name'));
        self::assertSame(['Backend', 'UI'], array_column($p['issue_categories'], 'name'));
        self::assertSame(['Design', 'Development'], array_column($p['time_entry_activities'], 'name'));
        self::assertCount(10, $p['enabled_modules']);
        self::assertSame([1, 2, 3, 4, 7], array_column($p['issue_custom_fields'], 'id'));
        $this->client->request('GET', '/projects/ecommerce.xml', [], [], ['HTTP_X_REDMINE_API_KEY' => '3de6a93a7c5f8921fa98e0fecd9ae25695aa7536']);
        self::assertStringContainsString('<identifier>ecommerce</identifier>', (string) $this->client->getResponse()->getContent());
    }

    public function testFilters(): void
    {
        $data = $this->api('GET', '/projects.json?is_public=1');
        self::assertSame(['ecommerce', 'checkout'], array_column($data['projects'], 'identifier'));
        $data = $this->api('GET', '/projects.json?status=*', null, $this->adminKey());
        self::assertSame(3, $data['total_count'], 'archived projects are not visible');
    }

    public function testCreateUpdateDelete(): void
    {
        $key = $this->adminKey();
        $data = $this->api('POST', '/projects.json', ['project' => ['name' => 'API project', 'identifier' => 'api-project', 'tracker_ids' => [1], 'enabled_module_names' => ['issue_tracking'], 'custom_fields' => [['id' => 5, 'value' => 'Initech']]]], $key);
        self::assertSame(201, $this->code(), json_encode($data));
        self::assertSame('api-project', $data['project']['identifier']);
        self::assertSame('Initech', $data['project']['custom_fields'][0]['value']);
        $this->api('PUT', '/projects/api-project.json', ['project' => ['name' => 'Renamed']], $key);
        self::assertSame(204, $this->code());
        $this->em->clear();
        self::assertSame('Renamed', $this->em->getRepository(Project::class)->findOneBy(['identifier' => 'api-project'])->name);
        $err = $this->api('POST', '/projects.json', ['project' => ['name' => '', 'identifier' => 'API project']], $key);
        self::assertSame(422, $this->code());
        self::assertContains('Name cannot be blank', $err['errors']);
        self::assertContains('Identifier is invalid', $err['errors']);
        $this->api('PUT', '/projects/api-project/archive.json', null, $key);
        self::assertSame(204, $this->code());
        $this->api('PUT', '/projects/api-project/unarchive.json', null, $key);
        self::assertSame(204, $this->code());
        $this->api('PUT', '/projects/api-project/close.json', null, $key);
        self::assertSame(204, $this->code());
        $this->api('PUT', '/projects/api-project/reopen.json', null, $key);
        self::assertSame(204, $this->code());
        $this->api('DELETE', '/projects/api-project.json', null, $key);
        self::assertSame(204, $this->code());
        $this->em->clear();
        self::assertNull($this->em->getRepository(Project::class)->findOneBy(['identifier' => 'api-project']));
    }

    public function testPermissions(): void
    {
        // jsmith is not admin: archive denied
        $this->api('PUT', '/projects/ecommerce/archive.json');
        self::assertSame(403, $this->code());
        $this->api('GET', '/projects/legacy.json');
        self::assertSame(403, $this->code());
    }
}

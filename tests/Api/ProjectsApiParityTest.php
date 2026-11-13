<?php

declare(strict_types=1);

namespace App\Tests\Api;

/** Port of api_test/projects_test.rb on the seeded data. */
final class ProjectsApiParityTest extends ApiTestCase
{
    use AdminApiHelpers;

    private const string ALL_INCLUDES = 'include=trackers,issue_categories,enabled_modules,time_entry_activities,issue_custom_fields';

    public function testIndexXml(): void
    {
        $this->conn()->executeStatement('UPDATE projects SET inherit_members = 1, homepage = ? WHERE id = 1', ['http://shop.example.net/']);
        $xml = $this->xml('GET', '/projects.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('array', (string) $xml['type']);
        self::assertSame(['2', '0', '25'], [(string) $xml['total_count'], (string) $xml['offset'], (string) $xml['limit']]);
        $first = $xml->project[0];
        self::assertSame('1', (string) $first->id);
        self::assertSame('1', (string) $first->status);
        self::assertSame('true', (string) $first->is_public);
        self::assertSame('true', (string) $first->inherit_members);
        self::assertSame('http://shop.example.net/', (string) $first->homepage);
        self::assertSame('ACME Corp', (string) $first->custom_fields->custom_field->value);
        self::assertSame('Customer', (string) $first->custom_fields->custom_field['name']);
        // sub project carries its parent
        self::assertSame(['1', 'eCommerce'], [(string) $xml->project[1]->parent['id'], (string) $xml->project[1]->parent['name']]);
    }

    public function testIndexJson(): void
    {
        $data = $this->json('GET', '/projects.json', null, null);
        $this->assertJsonResponse();
        self::assertSame([1, 3], self::ids($data['projects']));
        self::assertSame(['id', 'name', 'identifier', 'description', 'homepage', 'status', 'is_public', 'inherit_members', 'custom_fields', 'created_on', 'updated_on'], array_keys($data['projects'][0]));
        $this->assertPaging($data, 2, 0, 25);
    }

    public function testIndexVisibilityPerUser(): void
    {
        foreach (['admin' => [2, 1, 3], 'jsmith' => [2, 1, 3], 'dlopper' => [1, 3], 'rhill' => [1, 3], 'anna' => [1, 3]] as $login => $ids) {
            $data = $this->json('GET', '/projects.json', null, $login);
            self::assertSame($ids, self::ids($data['projects']), $login);
            self::assertSame(\count($ids), $data['total_count'], $login);
        }
    }

    public function testArchivedProjectIsHiddenEvenForAdmin(): void
    {
        $data = $this->json('GET', '/projects.json?status=9', null, 'admin');
        $this->assertStatus(200);
        self::assertSame([], $data['projects']);
        self::assertSame(0, $data['total_count']);
        $this->request('GET', '/projects/legacy.json', null, 'admin');
        $this->assertStatus(403);
        $this->request('GET', '/projects/4.xml', null, 'jsmith');
        $this->assertStatus(403);
        self::assertSame('', $this->body());
    }

    public function testClosedProjectsAreListedWithStatusFilter(): void
    {
        $this->conn()->executeStatement('UPDATE projects SET status = 5 WHERE id = 3');
        $data = $this->json('GET', '/projects.json', null, 'jsmith');
        self::assertSame([2, 1], self::ids($data['projects']), 'default filter: active projects');
        $data = $this->json('GET', '/projects.json?status=5', null, 'jsmith');
        self::assertSame([3], self::ids($data['projects']));
        self::assertSame(5, $data['projects'][0]['status']);
        $data = $this->json('GET', '/projects.json?status=1|5', null, 'jsmith');
        self::assertSame([2, 1, 3], self::ids($data['projects']));
        $data = $this->json('GET', '/projects/3.json', null, 'jsmith');
        $this->assertStatus(200);
        self::assertSame(5, $data['project']['status']);
    }

    public function testShortFilters(): void
    {
        $cases = [
            'is_public=1' => [1, 3],
            'is_public=0' => [2],
            'is_public=!0' => [1, 3],
            'id=2' => [2],
            'id=1|2' => [2, 1],
            'parent_id=1' => [3],
            'name=~Check' => [3],
        ];
        foreach ($cases as $q => $ids) {
            $data = $this->json('GET', '/projects.json?'.$q, null, 'admin');
            $this->assertStatus(200, $q);
            self::assertSame($ids, self::ids($data['projects']), $q);
            self::assertSame(\count($ids), $data['total_count'], $q);
        }
        $data = $this->json('GET', '/projects.json?f[]=is_public&op[is_public]==&v[is_public][]=0', null, 'admin');
        self::assertSame([2], self::ids($data['projects']));
    }

    public function testPaging(): void
    {
        $data = $this->json('GET', '/projects.json?limit=1', null, 'admin');
        self::assertSame([2], self::ids($data['projects']));
        $this->assertPaging($data, 3, 0, 1);
        $data = $this->json('GET', '/projects.json?offset=1&limit=1', null, 'admin');
        self::assertSame([1], self::ids($data['projects']));
        $this->assertPaging($data, 3, 1, 1);
        $data = $this->json('GET', '/projects.json?offset=2&limit=5', null, 'admin');
        self::assertSame([3], self::ids($data['projects']));
        $this->assertPaging($data, 3, 2, 5);
        // page parameter (api_offset_and_limit)
        $data = $this->json('GET', '/projects.json?page=2&limit=2', null, 'admin');
        self::assertSame([3], self::ids($data['projects']));
        $this->assertPaging($data, 3, 2, 2);
        // limit is capped at 100
        $data = $this->json('GET', '/projects.json?limit=500', null, 'admin');
        self::assertSame(100, $data['limit']);
        $xml = $this->xml('GET', '/projects.xml?offset=1&limit=1', null, 'admin');
        self::assertSame(['3', '1', '1'], [(string) $xml['total_count'], (string) $xml['offset'], (string) $xml['limit']]);
    }

    public function testIndexIncludes(): void
    {
        $data = $this->json('GET', '/projects.json?'.self::ALL_INCLUDES, null, 'jsmith');
        $p = $data['projects'][1];
        self::assertSame(1, $p['id']);
        self::assertSame([['id' => 1, 'name' => 'Bug'], ['id' => 2, 'name' => 'Feature'], ['id' => 3, 'name' => 'Support']], $p['trackers']);
        self::assertSame([['id' => 2, 'name' => 'Backend'], ['id' => 1, 'name' => 'UI']], $p['issue_categories']);
        self::assertSame([['id' => 8, 'name' => 'Design'], ['id' => 9, 'name' => 'Development']], $p['time_entry_activities']);
        self::assertSame(['issue_tracking', 'time_tracking', 'news', 'documents', 'files', 'wiki', 'repository', 'boards', 'calendar', 'gantt'], array_column($p['enabled_modules'], 'name'));
        self::assertSame([1, 2, 3, 4, 7], self::ids($p['issue_custom_fields']));
        $xml = $this->xml('GET', '/projects.xml?include=trackers', null, 'jsmith');
        self::assertSame('Bug', (string) $xml->project[1]->trackers->tracker[0]['name']);
        self::assertSame('array', (string) $xml->project[1]->trackers['type']);
    }

    public function testIncludesRequireViewIssuesAndViewTimeEntries(): void
    {
        // anna is not a member of ecommerce: the Non member role applies
        $this->removePermissions(1, 'view_issues', 'view_time_entries');
        $data = $this->json('GET', '/projects/1.json?'.self::ALL_INCLUDES, null, 'anna');
        $this->assertStatus(200);
        $p = $data['project'];
        foreach (['trackers', 'issue_categories', 'issue_custom_fields', 'time_entry_activities'] as $key) {
            self::assertArrayNotHasKey($key, $p, $key);
        }
        self::assertArrayHasKey('enabled_modules', $p);
        $data = $this->json('GET', '/projects.json?include=trackers,issue_categories', null, 'anna');
        self::assertArrayNotHasKey('trackers', $data['projects'][0]);
        self::assertArrayNotHasKey('issue_categories', $data['projects'][0]);
        // members still get them
        $data = $this->json('GET', '/projects/1.json?'.self::ALL_INCLUDES, null, 'rhill');
        self::assertArrayHasKey('trackers', $data['project']);
        self::assertArrayHasKey('time_entry_activities', $data['project']);
    }

    public function testShowByIdAndIdentifier(): void
    {
        $byId = $this->json('GET', '/projects/1.json', null, 'jsmith');
        $this->assertJsonResponse();
        $byIdentifier = $this->json('GET', '/projects/ecommerce.json', null, 'jsmith');
        self::assertSame($byId, $byIdentifier);
        self::assertSame('eCommerce', $byId['project']['name']);
        self::assertSame('Online **shop** project', $byId['project']['description']);
        self::assertSame([['id' => 5, 'name' => 'Customer', 'value' => 'ACME Corp']], $byId['project']['custom_fields']);

        $xml = $this->xml('GET', '/projects/checkout.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('3', (string) $xml->id);
        self::assertSame('checkout', (string) $xml->identifier);
        self::assertSame('1', (string) $xml->parent['id']);
    }

    public function testShowPrivateProject(): void
    {
        $this->request('GET', '/projects/internal.json', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/projects/internal.json', null, 'rhill');
        $this->assertStatus(403);
        $data = $this->json('GET', '/projects/internal.json', null, 'jsmith');
        $this->assertStatus(200);
        self::assertFalse($data['project']['is_public']);
        $this->request('GET', '/projects/nope.json', null, 'jsmith');
        $this->assertStatus(404);
        $this->request('GET', '/projects/999.xml', null, 'admin');
        $this->assertStatus(404);
        self::assertSame('', $this->body());
    }

    public function testShowHidesInvisibleCustomFields(): void
    {
        $this->conn()->executeStatement('UPDATE custom_fields SET visible = 0 WHERE id = 5');
        $data = $this->json('GET', '/projects/1.json', null, 'rhill');
        self::assertSame([], $data['project']['custom_fields'] ?? []);
        $data = $this->json('GET', '/projects/1.json', null, 'admin');
        self::assertSame('ACME Corp', $data['project']['custom_fields'][0]['value']);
    }

    public function testShowWithDefaultVersionAndAssignee(): void
    {
        $this->conn()->executeStatement('UPDATE projects SET default_assigned_to_id = 6, default_version_id = 1 WHERE id = 1');
        $data = $this->json('GET', '/projects/1.json', null, 'jsmith');
        self::assertSame(['id' => 6, 'name' => 'Dave Lopper'], $data['project']['default_assignee']);
        self::assertSame(['id' => 1, 'name' => '1.0'], $data['project']['default_version']);
        $xml = $this->xml('GET', '/projects/1.xml', null, 'jsmith');
        self::assertSame('Dave Lopper', (string) $xml->default_assignee['name']);
        self::assertSame('1.0', (string) $xml->default_version['name']);
    }

    public function testCreateXmlWithDefaultModules(): void
    {
        $this->setting('default_projects_modules', ['issue_tracking', 'repository']);
        $before = $this->rows('projects');
        $this->form('POST', '/projects.xml', ['project' => ['name' => 'API test', 'identifier' => 'api-test']]);
        $this->assertXmlResponse(201);
        self::assertSame($before + 1, $this->rows('projects'));
        $id = $this->lastId('projects');
        $xml = simplexml_load_string($this->body());
        self::assertSame((string) $id, (string) $xml->id);
        self::assertSame(['API test', 'api-test'], [(string) $this->dbOne('SELECT name FROM projects WHERE id = ?', [$id]), (string) $this->dbOne('SELECT identifier FROM projects WHERE id = ?', [$id])]);
        self::assertSame(['issue_tracking', 'repository'], array_column($this->db('SELECT name FROM enabled_modules WHERE project_id = ? ORDER BY name', [$id]), 'name'));
        self::assertSame(3, $this->rows('projects_trackers', 'project_id = ?', [$id]));
    }

    public function testCreateWithModulesTrackersAndCustomFieldsJson(): void
    {
        $data = $this->json('POST', '/projects.json', ['project' => [
            'name' => 'API test', 'identifier' => 'api-test', 'parent_id' => 1, 'is_public' => false,
            'enabled_module_names' => ['issue_tracking', 'news', 'time_tracking'], 'tracker_ids' => [1, 3],
            'custom_fields' => [['id' => 5, 'value' => 'Initech']],
        ]], 'admin');
        $this->assertJsonResponse(201);
        $id = $data['project']['id'];
        self::assertSame(['id' => 1, 'name' => 'eCommerce'], $data['project']['parent']);
        self::assertFalse($data['project']['is_public']);
        self::assertSame('Initech', $data['project']['custom_fields'][0]['value']);
        self::assertSame(['issue_tracking', 'news', 'time_tracking'], array_column($this->db('SELECT name FROM enabled_modules WHERE project_id = ? ORDER BY name', [$id]), 'name'));
        self::assertSame([1, 3], array_map('intval', array_column($this->db('SELECT tracker_id FROM projects_trackers WHERE project_id = ? ORDER BY tracker_id', [$id]), 'tracker_id')));
        self::assertStringEndsWith('/projects/'.$id, (string) $this->response()->headers->get('Location'));
    }

    public function testCreateValidationErrors(): void
    {
        $before = $this->rows('projects');
        $xml = $this->xml('POST', '/projects.xml', '<project><name>API test</name></project>', 'admin');
        $this->assertXmlResponse(422);
        self::assertContains('Identifier cannot be blank', self::xpath($xml, '/errors/error'));
        $data = $this->json('POST', '/projects.json', ['project' => ['name' => '', 'identifier' => 'ecommerce']], 'admin');
        $this->assertErrors($data, 'Name cannot be blank', 'Identifier has already been taken');
        $data = $this->json('POST', '/projects.json', ['project' => ['name' => 'X', 'identifier' => 'Bad Identifier']], 'admin');
        $this->assertErrors($data, 'Identifier is invalid');
        self::assertSame($before, $this->rows('projects'));
    }

    public function testCreatePermissions(): void
    {
        $this->request('POST', '/projects.json', ['project' => ['name' => 'Anon', 'identifier' => 'anon-p']], null);
        $this->assertStatus(401);
        // rhill has no add_project permission (Reporter)
        $this->request('POST', '/projects.json', ['project' => ['name' => 'Rhill', 'identifier' => 'rhill-p']], 'rhill');
        $this->assertStatus(403);
        // jsmith is Manager (add_project): allowed, becomes a member with the default creator role
        $this->setting('new_project_user_role_id', '3');
        $data = $this->json('POST', '/projects.json', ['project' => ['name' => 'Jsmith project', 'identifier' => 'jsmith-p']], 'jsmith');
        $this->assertStatus(201);
        $id = $data['project']['id'];
        self::assertSame(['3'], array_map('strval', array_column($this->db('SELECT mr.role_id FROM members m JOIN member_roles mr ON mr.member_id = m.id WHERE m.project_id = ? AND m.user_id = 5', [$id]), 'role_id')));
    }

    public function testUpdateXmlAndJson(): void
    {
        $this->form('PUT', '/projects/2.xml', ['project' => ['name' => 'API update']], 'jsmith');
        $this->assertStatus(403, 'jsmith is Developer on internal (no edit_project)');
        $this->form('PUT', '/projects/1.xml', ['project' => ['name' => 'API update']], 'jsmith');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame('API update', $this->dbOne('SELECT name FROM projects WHERE id = 1'));

        $this->request('PUT', '/projects/2.json', ['project' => ['name' => 'Internal 2', 'enabled_module_names' => ['issue_tracking', 'news', 'time_tracking'], 'tracker_ids' => [1, 3], 'custom_fields' => [['id' => 5, 'value' => 'Umbrella']]]], 'admin');
        $this->assertStatus(204);
        self::assertSame('Internal 2', $this->dbOne('SELECT name FROM projects WHERE id = 2'));
        self::assertSame(['issue_tracking', 'news', 'time_tracking'], array_column($this->db('SELECT name FROM enabled_modules WHERE project_id = 2 ORDER BY name'), 'name'));
        self::assertSame([1, 3], array_map('intval', array_column($this->db('SELECT tracker_id FROM projects_trackers WHERE project_id = 2 ORDER BY tracker_id'), 'tracker_id')));
        self::assertSame('Umbrella', $this->dbOne("SELECT value FROM custom_values WHERE customized_type = 'Project' AND customized_id = 2 AND custom_field_id = 5"));
    }

    public function testUpdateIdentifierIsNotChangeable(): void
    {
        $this->request('PUT', '/projects/1.json', ['project' => ['identifier' => 'renamed']], 'admin');
        $this->assertStatus(204);
        self::assertSame('ecommerce', $this->dbOne('SELECT identifier FROM projects WHERE id = 1'));
    }

    public function testUpdateValidationErrors(): void
    {
        $xml = $this->xml('PUT', '/projects/2.xml', '<project><name></name></project>', 'admin');
        $this->assertXmlResponse(422);
        self::assertSame(['Name cannot be blank'], self::xpath($xml, '/errors/error'));
        $data = $this->json('PUT', '/projects/1.json', ['project' => ['parent_id' => 3]], 'admin');
        $this->assertErrors($data, 'Subproject of is invalid');
    }

    public function testDelete(): void
    {
        $this->request('DELETE', '/projects/2.json', null, 'jsmith');
        $this->assertStatus(403);
        $this->request('DELETE', '/projects/2.xml', null, 'admin');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        // DestroyProjectJob: the project is scheduled for deletion (status 10) or already deleted
        $status = $this->dbOne('SELECT status FROM projects WHERE id = 2');
        self::assertTrue(false === $status || 10 === (int) $status, 'status '.var_export($status, true));
        $this->request('GET', '/projects/2.json', null, 'admin');
        self::assertContains($this->response()->getStatusCode(), [403, 404]);
    }

    public function testArchiveUnarchive(): void
    {
        $this->request('PUT', '/projects/1/archive.xml', null, 'jsmith');
        $this->assertStatus(403);
        $this->request('PUT', '/projects/1/archive.xml', null, 'admin');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(9, (int) $this->dbOne('SELECT status FROM projects WHERE id = 1'));
        self::assertSame(9, (int) $this->dbOne('SELECT status FROM projects WHERE id = 3'), 'subprojects are archived too');
        $this->request('PUT', '/projects/1/unarchive.json', null, 'admin');
        $this->assertStatus(204);
        self::assertSame(1, (int) $this->dbOne('SELECT status FROM projects WHERE id = 1'));
        $this->request('PUT', '/projects/4/unarchive.json', null, 'admin');
        $this->assertStatus(204);
        self::assertSame(1, (int) $this->dbOne('SELECT status FROM projects WHERE id = 4'));
    }

    public function testCloseReopen(): void
    {
        $this->request('PUT', '/projects/1/close.xml', null, 'rhill');
        $this->assertStatus(403);
        $this->request('PUT', '/projects/1/close.xml', null, 'jsmith');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(5, (int) $this->dbOne('SELECT status FROM projects WHERE id = 1'));
        $this->request('PUT', '/projects/1/reopen.json', null, 'jsmith');
        $this->assertStatus(204);
        self::assertSame(1, (int) $this->dbOne('SELECT status FROM projects WHERE id = 1'));
    }
}

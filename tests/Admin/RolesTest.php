<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\Role;

final class RolesTest extends AdminWebTestCase
{
    use WorkflowAdminTestTrait;

    public function testPagesAccess(): void
    {
        $this->assertAdminOnly(['/roles', '/roles/new', '/roles/new?copy=3', '/roles/3/edit', '/roles/1/edit', '/roles/2/edit', '/roles/permissions', '/roles/permissions?ids[]=3&ids[]=1']);
        $this->get('/roles/permissions.csv');
        $this->assertStatus(200);
        self::assertStringContainsString('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testIndexListsRolesWithReorderHandles(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/roles');
        self::assertCount(3, $crawler->filter('table.roles tr.givable'));
        self::assertCount(2, $crawler->filter('table.roles tr.builtin'));
        self::assertSame('/roles/3', $crawler->filter('tr.givable .sort-handle')->first()->attr('data-reorder-url'));
        self::assertSame('Non member', trim($crawler->filter('tr.builtin td.name em a')->first()->text()));
    }

    public function testBuiltinRoleFormIsRestricted(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/roles/2/edit'); // Anonymous
        self::assertCount(0, $crawler->filter('#role_name'));
        self::assertCount(0, $crawler->filter('#role_issues_visibility'));
        self::assertCount(0, $crawler->filter('#role_permissions_add_project'), 'loggedin only permission');
        self::assertCount(1, $crawler->filter('#role_permissions_view_issues'));
        $crawler = $this->get('/roles/1/edit'); // Non member
        self::assertCount(0, $crawler->filter('#role_permissions_manage_members'), 'members only permission');
        self::assertCount(1, $crawler->filter('#role_permissions_add_project'));
    }

    public function testCreateWithWorkflowCopyAndTrackerPermissions(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/roles', [
            'role' => [
                'name' => 'Tester', 'assignable' => '1', 'issues_visibility' => 'own', 'users_visibility' => 'all', 'time_entries_visibility' => 'own',
                'all_roles_managed' => '0', 'managed_role_ids' => ['4', ''],
                'permissions' => ['view_issues', 'add_issues', 'edit_issues', ''],
                'permissions_all_trackers' => ['view_issues' => '1', 'add_issues' => '0', 'edit_issues' => '0'],
                'permissions_tracker_ids' => ['view_issues' => [''], 'add_issues' => ['1', ''], 'edit_issues' => ['']],
                'default_time_entry_activity_id' => '',
            ],
            'copy_workflow_from' => '4',
        ]);
        self::assertTrue($r->isRedirect('/roles'), (string) $r->getStatusCode());
        $this->em->clear();
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => 'Tester']);
        self::assertNotNull($role);
        self::assertSame(['view_issues', 'add_issues', 'edit_issues'], $role->getPermissions());
        self::assertSame(4, $role->position);
        self::assertFalse($role->allRolesManaged);
        self::assertSame([4], array_map(static fn ($r) => $r->id, $role->managedRoles->toArray()));
        self::assertTrue($role->permissionsAllTrackers('view_issues'));
        self::assertFalse($role->permissionsAllTrackers('add_issues'));
        self::assertTrue($role->allowedTracker('add_issues', 1));
        self::assertFalse($role->allowedTracker('add_issues', 2));
        self::assertFalse($role->allowedTracker('edit_issues', 1));
        self::assertSame($this->transitionRows(1, 4), $this->transitionRows(1, (int) $role->id));
        self::assertNotEmpty($this->transitionRows(1, (int) $role->id));
    }

    public function testCreateValidation(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/roles', ['role' => ['name' => 'Manager']]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Name has already been taken', (string) $r->getContent());
        $r = $this->post('/roles', ['role' => ['name' => '']]);
        self::assertStringContainsString('Name cannot be blank', (string) $r->getContent());
    }

    public function testNewCopyPrefillsPermissions(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/roles/new?copy=4');
        self::assertSame('checked', $crawler->filter('#role_permissions_commit_access')->attr('checked'));
        self::assertSame('4', $crawler->filter('#copy_workflow_from option[selected]')->attr('value'));
        // default: Non member permissions
        $crawler = $this->get('/roles/new');
        self::assertSame('checked', $crawler->filter('#role_permissions_add_issue_notes')->attr('checked'));
        self::assertNull($crawler->filter('#role_permissions_commit_access')->attr('checked'));
    }

    public function testUpdateAndReorder(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/roles/5', ['role' => ['name' => 'Reporter 2', 'permissions' => ['view_issues', '']]], 'PATCH');
        self::assertTrue($r->isRedirect());
        $token = $this->csrfToken();
        $this->client->request('PUT', '/roles/5', ['role' => ['position' => '1']], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_X-CSRF-Token' => $token]);
        $this->assertStatus(200);
        $rows = $this->db()->fetchAllKeyValue('SELECT id, position FROM roles WHERE builtin = 0 ORDER BY id');
        self::assertSame([3 => 2, 4 => 3, 5 => 1], array_map('intval', $rows));
        self::assertSame('["view_issues"]', $this->db()->fetchOne('SELECT permissions FROM roles WHERE id = 5'));
        self::assertSame('Reporter 2', $this->db()->fetchOne('SELECT name FROM roles WHERE id = 5'));
    }

    public function testDestroy(): void
    {
        $this->loginAs('admin');
        // in use
        $r = $this->post('/roles/3', [], 'DELETE');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('This role is in use and cannot be deleted.', (string) $r->getContent());
        // builtin
        $this->post('/roles/1', [], 'DELETE');
        self::assertNotFalse($this->db()->fetchOne('SELECT id FROM roles WHERE id = 1'));
        // unused role
        $this->post('/roles', ['role' => ['name' => 'Temp', 'permissions' => ['add_issues']], 'copy_workflow_from' => '4']);
        $id = (int) $this->db()->fetchOne("SELECT id FROM roles WHERE name = 'Temp'");
        self::assertGreaterThan(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM workflows WHERE role_id = ?', [$id]));
        $r = $this->post('/roles/'.$id, [], 'DELETE');
        self::assertTrue($r->isRedirect('/roles'));
        self::assertFalse($this->db()->fetchOne('SELECT id FROM roles WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM workflows WHERE role_id = ?', [$id]));
    }

    public function testPermissionsReportUpdate(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/roles/permissions');
        self::assertGreaterThan(0, $crawler->filter('table.permissions input.role-3')->count());
        // anonymous role cannot get loggedin-only permissions
        self::assertCount(0, $crawler->filter('tr.permission-add_project input.role-2'));
        $r = $this->post('/roles/permissions', ['permissions' => ['5' => ['view_issues', 'add_issues', ''], '2' => ['']]]);
        self::assertTrue($r->isRedirect('/roles'));
        self::assertSame('["view_issues","add_issues"]', $this->db()->fetchOne('SELECT permissions FROM roles WHERE id = 5'));
        self::assertSame('[]', $this->db()->fetchOne('SELECT permissions FROM roles WHERE id = 2'));
    }

    public function testApi(): void
    {
        $r = $this->api('GET', '/roles.json');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame(['roles' => [['id' => 3, 'name' => 'Manager'], ['id' => 4, 'name' => 'Developer'], ['id' => 5, 'name' => 'Reporter']]], json_decode((string) $r->getContent(), true));
        $r = $this->api('GET', '/roles/4.json');
        $data = json_decode((string) $r->getContent(), true)['role'];
        self::assertSame('Developer', $data['name']);
        self::assertTrue($data['assignable']);
        self::assertSame('default', $data['issues_visibility']);
        self::assertContains('commit_access', $data['permissions']);
        $r = $this->api('GET', '/roles/1.xml');
        self::assertStringContainsString('<permissions type="array"><permission>view_issues</permission>', (string) $r->getContent());
        $r = $this->api('GET', '/roles/999.json');
        self::assertSame(404, $r->getStatusCode());
    }
}

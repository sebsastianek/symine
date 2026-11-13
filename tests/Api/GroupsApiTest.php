<?php

declare(strict_types=1);

namespace App\Tests\Api;

/** Port of api_test/groups_test.rb (group "Developers" #10 has member dlopper and a membership on eCommerce). */
final class GroupsApiTest extends ApiTestCase
{
    use AdminApiHelpers;

    public function testIndexRequiresAuthenticationAndAdmin(): void
    {
        $this->request('GET', '/groups.xml', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/groups.json', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/groups.json', null, 'jsmith');
        $this->assertStatus(403);
    }

    public function testIndexReturnsGivableGroups(): void
    {
        $xml = $this->xml('GET', '/groups.xml', null, 'admin');
        $this->assertXmlResponse();
        self::assertSame(['10'], self::xpath($xml, '/groups/group/id'));
        self::assertSame(['Developers'], self::xpath($xml, '/groups/group/name'));
        $data = $this->json('GET', '/groups.json', null, 'admin');
        $this->assertJsonResponse();
        self::assertSame(['groups' => [['id' => 10, 'name' => 'Developers']]], $data);
    }

    public function testIndexWithBuiltinGroups(): void
    {
        $xml = $this->xml('GET', '/groups.xml?builtin=1', null, 'admin');
        self::assertSame(['10', '2', '3'], self::xpath($xml, '/groups/group/id'));
        self::assertSame(['anonymous'], self::xpath($xml, '/groups/group[id=2]/builtin'));
        self::assertSame(['non_member'], self::xpath($xml, '/groups/group[id=3]/builtin'));
        $data = $this->json('GET', '/groups.json?builtin=1', null, 'admin');
        self::assertSame(['id' => 3, 'name' => 'Non member users', 'builtin' => 'non_member'], $data['groups'][2]);
    }

    public function testShow(): void
    {
        $xml = $this->xml('GET', '/groups/10.xml', null, 'admin');
        $this->assertXmlResponse();
        self::assertSame('10', (string) $xml->id);
        self::assertSame('Developers', (string) $xml->name);
        self::assertCount(0, $xml->users);
        $xml = $this->xml('GET', '/groups/3.xml', null, 'admin');
        self::assertSame('Non member users', (string) $xml->name);
        self::assertSame('non_member', (string) $xml->builtin);
        $this->request('GET', '/groups/999.json', null, 'admin');
        $this->assertStatus(404);
    }

    public function testShowIncludes(): void
    {
        $data = $this->json('GET', '/groups/10.json?include=users,memberships', null, 'admin');
        self::assertSame(['group' => ['id' => 10, 'name' => 'Developers',
            'users' => [['id' => 6, 'name' => 'Dave Lopper']],
            'memberships' => [['id' => 6, 'project' => ['id' => 1, 'name' => 'eCommerce'], 'roles' => [['id' => 4, 'name' => 'Developer']]]],
        ]], $data);
        $xml = $this->xml('GET', '/groups/10.xml?include=users', null, 'admin');
        self::assertSame(['6'], self::xpath($xml, '/group/users/user/@id'));
    }

    public function testMembershipsOnlyInVisibleProjects(): void
    {
        $this->conn()->executeStatement('INSERT INTO members (id, user_id, project_id, created_on, mail_notification) VALUES (50, 10, 2, datetime(\'now\'), 0)');
        $this->conn()->executeStatement('INSERT INTO member_roles (member_id, role_id) VALUES (50, 5)');
        $xml = $this->xml('GET', '/groups/10.xml?include=memberships', null, 'admin');
        self::assertSame(['1', '2'], self::xpath($xml, '/group/memberships/membership/project/@id'));
        $data = $this->json('GET', '/groups/10.json?include=memberships', null, 'dlopper');
        $this->assertStatus(200);
        self::assertSame([1], array_column(array_column($data['group']['memberships'], 'project'), 'id'));
    }

    public function testCreate(): void
    {
        $this->form('POST', '/groups.xml', ['group' => ['name' => 'Test', 'user_ids' => [5, 7]]]);
        $this->assertXmlResponse(201);
        self::assertSame('Test', (string) simplexml_load_string($this->body())->name);
        $id = (int) $this->dbOne("SELECT id FROM users WHERE type = 'Group' AND lastname = 'Test'");
        self::assertSame([5, 7], array_map('intval', array_column($this->db('SELECT user_id FROM groups_users WHERE group_id = ? ORDER BY user_id', [$id]), 'user_id')));
        $data = $this->json('POST', '/groups.json', ['group' => ['name' => 'Json group']], 'admin');
        $this->assertJsonResponse(201);
        self::assertSame('Json group', $data['group']['name']);
    }

    public function testCreateInvalidAndForbidden(): void
    {
        $before = $this->rows('users');
        $xml = $this->xml('POST', '/groups.xml', '<group><name></name></group>', 'admin');
        $this->assertXmlResponse(422);
        self::assertContains('Name cannot be blank', self::xpath($xml, '/errors/error'));
        $data = $this->json('POST', '/groups.json', ['group' => ['name' => 'Developers']], 'admin');
        $this->assertErrors($data, 'Name has already been taken');
        $this->request('POST', '/groups.json', ['group' => ['name' => 'Hack']], 'jsmith');
        $this->assertStatus(403);
        self::assertSame($before, $this->rows('users'));
    }

    public function testUpdate(): void
    {
        $this->form('PUT', '/groups/10.xml', ['group' => ['name' => 'New name', 'user_ids' => [5, 7]]]);
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame('New name', $this->dbOne('SELECT lastname FROM users WHERE id = 10'));
        self::assertSame([5, 7], array_map('intval', array_column($this->db('SELECT user_id FROM groups_users WHERE group_id = 10 ORDER BY user_id'), 'user_id')));
        $xml = $this->xml('PUT', '/groups/10.xml', '<group><name></name></group>', 'admin');
        $this->assertXmlResponse(422);
        self::assertContains('Name cannot be blank', self::xpath($xml, '/errors/error'));
    }

    public function testDelete(): void
    {
        $this->request('DELETE', '/groups/10.json', null, 'jsmith');
        $this->assertStatus(403);
        $this->request('DELETE', '/groups/10.xml', null, 'admin');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(0, $this->rows('users', 'id = 10'));
        self::assertSame(0, $this->rows('groups_users', 'group_id = 10'));
        $this->request('DELETE', '/groups/10.xml', null, 'admin');
        $this->assertStatus(404);
    }

    public function testAddUser(): void
    {
        $this->form('POST', '/groups/10/users.xml', ['user_id' => 5]);
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(1, $this->rows('groups_users', 'group_id = 10 AND user_id = 5'));
        // group membership is inherited on eCommerce
        self::assertGreaterThan(0, $this->rows('member_roles mr JOIN members m ON m.id = mr.member_id', 'm.user_id = 5 AND m.project_id = 1 AND mr.inherited_from IS NOT NULL'));
        $this->request('POST', '/groups/10/users.json', ['user_id' => [7, 9]], 'admin');
        $this->assertStatus(204);
        self::assertSame(4, $this->rows('groups_users', 'group_id = 10'));
    }

    public function testAddUserAlreadyAdded(): void
    {
        $this->form('POST', '/groups/10/users.xml', ['user_id' => 6]);
        $this->assertStatus(422);
        self::assertMatchesRegularExpression('/User is invalid/', $this->body());
        self::assertSame(1, $this->rows('groups_users', 'group_id = 10'));
    }

    public function testRemoveUser(): void
    {
        $this->request('DELETE', '/groups/10/users/6.xml', null, 'admin');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(0, $this->rows('groups_users', 'group_id = 10'));
        self::assertSame(0, $this->rows('member_roles', 'member_id = 2 AND inherited_from IS NOT NULL'));
        $this->request('DELETE', '/groups/10/users/6.xml', null, 'admin');
        $this->assertStatus(404);
    }
}

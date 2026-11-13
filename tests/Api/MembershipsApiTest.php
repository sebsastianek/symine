<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Port of api_test/memberships_test.rb. eCommerce memberships: #1 jsmith Manager, #2 dlopper Developer (+ inherited
 * from group), #3 rhill Reporter, #6 group Developers.
 */
final class MembershipsApiTest extends ApiTestCase
{
    use AdminApiHelpers;

    public function testIndexXml(): void
    {
        $xml = $this->xml('GET', '/projects/1/memberships.xml', null, 'jsmith');
        $this->assertXmlResponse();
        self::assertSame(['4', '0', '25'], [(string) $xml['total_count'], (string) $xml['offset'], (string) $xml['limit']]);
        self::assertSame(['1', '2', '3', '6'], self::xpath($xml, '/memberships/membership/id'));
        self::assertSame(['Dave Lopper'], self::xpath($xml, '/memberships/membership[id=2]/user/@name'));
        self::assertSame(['Developers'], self::xpath($xml, '/memberships/membership[id=6]/group/@name'));
        self::assertSame(['true'], self::xpath($xml, '/memberships/membership[id=2]/roles/role/@inherited'));
    }

    public function testIndexJson(): void
    {
        $data = $this->json('GET', '/projects/ecommerce/memberships.json', null, 'jsmith');
        $this->assertJsonResponse();
        $this->assertPaging($data, 4, 0, 25);
        self::assertSame(['id' => 1, 'project' => ['id' => 1, 'name' => 'eCommerce'], 'user' => ['id' => 5, 'name' => 'John Smith'], 'roles' => [['id' => 3, 'name' => 'Manager']]], $data['memberships'][0]);
        self::assertSame(['id' => 6, 'project' => ['id' => 1, 'name' => 'eCommerce'], 'group' => ['id' => 10, 'name' => 'Developers'], 'roles' => [['id' => 4, 'name' => 'Developer']]], $data['memberships'][3]);
        $data = $this->json('GET', '/projects/1/memberships.json?offset=1&limit=2', null, 'jsmith');
        self::assertSame([2, 3], self::ids($data['memberships']));
        $this->assertPaging($data, 4, 1, 2);
    }

    public function testIndexPermissions(): void
    {
        $data = $this->json('GET', '/projects/1/memberships.json', null, null);
        $this->assertStatus(200, 'public project');
        self::assertSame(4, $data['total_count']);
        $this->request('GET', '/projects/internal/memberships.json', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/projects/internal/memberships.json', null, 'rhill');
        $this->assertStatus(403);
        $this->request('GET', '/projects/nope/memberships.json', null, 'jsmith');
        $this->assertStatus(404);
    }

    public function testIndexForClosedProjectAndLockedUsers(): void
    {
        $this->conn()->executeStatement('UPDATE projects SET status = 5 WHERE id = 1');
        $this->conn()->executeStatement('UPDATE users SET status = 3 WHERE id = 6');
        $xml = $this->xml('GET', '/projects/1/memberships.xml', null, 'jsmith');
        $this->assertStatus(200);
        self::assertSame(['Dave Lopper'], self::xpath($xml, '/memberships/membership[id=2]/user/@name'));
    }

    public function testShow(): void
    {
        $xml = $this->xml('GET', '/memberships/2.xml', null, 'jsmith');
        $this->assertXmlResponse();
        self::assertSame('6', (string) $xml->user['id']);
        self::assertSame(['Developer', 'Developer'], self::xpath($xml, '/membership/roles/role/@name'));
        $data = $this->json('GET', '/memberships/3.json', null, 'jsmith');
        self::assertSame(['membership' => ['id' => 3, 'project' => ['id' => 1, 'name' => 'eCommerce'], 'user' => ['id' => 7, 'name' => 'Robert Hill'], 'roles' => [['id' => 5, 'name' => 'Reporter']]]], $data);
        $this->request('GET', '/memberships/4.json', null, 'rhill');
        $this->assertStatus(403, 'membership of a private project');
        $this->request('GET', '/memberships/999.json', null, 'jsmith');
        $this->assertStatus(404);
    }

    public function testCreateUserMembership(): void
    {
        $before = $this->rows('members');
        $this->form('POST', '/projects/1/memberships.xml', ['membership' => ['user_id' => 9, 'role_ids' => [4, 5]]], 'jsmith');
        $this->assertXmlResponse(201);
        self::assertSame($before + 1, $this->rows('members'));
        $id = (int) $this->dbOne('SELECT id FROM members WHERE user_id = 9 AND project_id = 1');
        self::assertSame((string) $id, (string) simplexml_load_string($this->body())->id);
        self::assertSame([4, 5], array_map('intval', array_column($this->db('SELECT role_id FROM member_roles WHERE member_id = ? ORDER BY role_id', [$id]), 'role_id')));
    }

    public function testCreateGroupMembershipAddsMembersOfTheGroup(): void
    {
        $before = $this->rows('members');
        $data = $this->json('POST', '/projects/3/memberships.json', ['membership' => ['user_id' => 10, 'role_ids' => [4]]], 'jsmith');
        $this->assertJsonResponse(201);
        self::assertSame(['id' => 10, 'name' => 'Developers'], $data['membership']['group']);
        self::assertSame($before + 2, $this->rows('members'), 'group + its user dlopper');
        self::assertSame(1, $this->rows('member_roles mr JOIN members m ON m.id = mr.member_id', 'm.user_id = 6 AND m.project_id = 3 AND mr.inherited_from IS NOT NULL'));
    }

    public function testCreateInvalid(): void
    {
        $before = $this->rows('members');
        $this->form('POST', '/projects/1/memberships.xml', ['membership' => ['role_ids' => [4]]], 'jsmith');
        $this->assertXmlResponse(422);
        self::assertContains('User or Group cannot be blank', self::xpath(simplexml_load_string($this->body()), '/errors/error'));
        $data = $this->json('POST', '/projects/1/memberships.json', ['membership' => ['user_id' => 9]], 'jsmith');
        $this->assertErrors($data, 'Role cannot be empty');
        $data = $this->json('POST', '/projects/1/memberships.json', ['membership' => ['user_id' => 5, 'role_ids' => [4]]], 'jsmith');
        $this->assertStatus(422);
        self::assertSame($before, $this->rows('members'));
    }

    public function testCreateRequiresManageMembers(): void
    {
        $this->request('POST', '/projects/1/memberships.json', ['membership' => ['user_id' => 9, 'role_ids' => [5]]], 'rhill');
        $this->assertStatus(403);
        $this->request('POST', '/projects/1/memberships.json', ['membership' => ['user_id' => 9, 'role_ids' => [5]]], null);
        $this->assertStatus(401);
        self::assertSame(0, $this->rows('members', 'user_id = 9'));
    }

    public function testUpdate(): void
    {
        $this->form('PUT', '/memberships/3.xml', ['membership' => ['user_id' => 7, 'role_ids' => [4, 5]]], 'jsmith');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame([4, 5], array_map('intval', array_column($this->db('SELECT role_id FROM member_roles WHERE member_id = 3 ORDER BY role_id'), 'role_id')));
        $this->request('PUT', '/memberships/3.json', ['membership' => ['role_ids' => [5]]], 'rhill');
        $this->assertStatus(403);
    }

    public function testUpdateInvalid(): void
    {
        $this->form('PUT', '/memberships/3.xml', ['membership' => ['user_id' => 7, 'role_ids' => [99]]], 'jsmith');
        $this->assertXmlResponse(422);
        self::assertSame(['Role cannot be empty'], self::xpath(simplexml_load_string($this->body()), '/errors/error'));
        self::assertSame([5], array_map('intval', array_column($this->db('SELECT role_id FROM member_roles WHERE member_id = 3'), 'role_id')));
    }

    public function testDestroy(): void
    {
        $before = $this->rows('members');
        $this->request('DELETE', '/memberships/3.xml', null, 'jsmith');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame($before - 1, $this->rows('members'));
        self::assertSame(0, $this->rows('members', 'id = 3'));
        $this->request('DELETE', '/memberships/3.xml', null, 'jsmith');
        $this->assertStatus(404);
    }

    public function testDestroyInheritedMembershipFails(): void
    {
        $this->conn()->executeStatement('UPDATE member_roles SET inherited_from = 99 WHERE member_id = 3');
        $this->request('DELETE', '/memberships/3.xml', null, 'jsmith');
        $this->assertStatus(422);
        self::assertSame(1, $this->rows('members', 'id = 3'));
    }
}

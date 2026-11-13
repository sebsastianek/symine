<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\Group;
use App\Entity\Principal;
use App\Entity\User;

final class UsersApiTest extends AdminWebTestCase
{
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testIndex(): void
    {
        $this->api('GET', '/users.json');
        $this->assertStatus(200);
        $d = $this->json();
        self::assertSame(5, $d['total_count']); // active users only
        self::assertSame(['id', 'login', 'admin', 'firstname', 'lastname', 'mail', 'created_on', 'updated_on', 'last_login_on', 'passwd_changed_on', 'twofa_scheme', 'status', 'custom_fields'], array_keys($d['users'][0]));
        $this->api('GET', '/users.json?status=');
        self::assertGreaterThan(5, $this->json()['total_count']);
        $this->api('GET', '/users.json?name=jsmith');
        self::assertSame('jsmith', $this->json()['users'][0]['login']);
        $this->api('GET', '/users.json?group_id=10');
        self::assertSame(['dlopper'], array_column($this->json()['users'], 'login'));
        $this->api('GET', '/users.json?limit=2&offset=1');
        self::assertCount(2, $this->json()['users']);
        $this->api('GET', '/users.xml');
        $this->assertStatus(200);
        self::assertStringContainsString('<users type="array" total_count="5"', (string) $this->client->getResponse()->getContent());
    }

    public function testShowWithIncludes(): void
    {
        $id = $this->user('jsmith')->id;
        $this->api('GET', '/users/'.$id.'.json?include=memberships,groups');
        $this->assertStatus(200);
        $u = $this->json()['user'];
        self::assertSame('jsmith', $u['login']);
        self::assertSame('3de6a93a7c5f8921fa98e0fecd9ae25695aa7536', $u['api_key']);
        self::assertSame([['id' => 6, 'name' => 'Phone', 'value' => '+1 555 0100']], $u['custom_fields']);
        self::assertSame([], $u['groups']);
        self::assertSame(['eCommerce', 'Internal Tools', 'Checkout'], array_column(array_column($u['memberships'], 'project'), 'name'));
        self::assertSame([['id' => 3, 'name' => 'Manager']], $u['memberships'][0]['roles']);
    }

    public function testCurrent(): void
    {
        $this->api('GET', '/users/current.json', null, 'jsmith', 'password123');
        $this->assertStatus(200);
        $u = $this->json()['user'];
        self::assertSame('jsmith', $u['login']);
        self::assertArrayHasKey('api_key', $u);
        self::assertArrayNotHasKey('status', $u);
        $this->client->request('GET', '/users/current.json');
        self::assertContains($this->client->getResponse()->getStatusCode(), [401, 403]);
    }

    public function testNonAdminCannotListUsers(): void
    {
        $this->api('GET', '/users.json', null, 'jsmith', 'password123');
        $this->assertStatus(403);
    }

    public function testCreateUpdateDelete(): void
    {
        $this->api('POST', '/users.json', ['user' => ['login' => 'apiuser', 'firstname' => 'Api', 'lastname' => 'User', 'mail' => 'api@example.net', 'password' => 'secret123', 'custom_fields' => [['id' => 6, 'value' => '123']]]]);
        $this->assertStatus(201);
        $u = $this->json()['user'];
        self::assertSame('apiuser', $u['login']);
        self::assertSame('123', $u['custom_fields'][0]['value']);
        $id = $u['id'];

        $this->api('POST', '/users.json', ['user' => ['login' => 'apiuser', 'firstname' => '', 'lastname' => 'User', 'mail' => 'x']]);
        $this->assertStatus(422);
        $errors = $this->json()['errors'];
        self::assertContains('Login has already been taken', $errors);
        self::assertContains('First name cannot be blank', $errors);
        self::assertContains('Email is invalid', $errors);

        $this->api('PUT', '/users/'.$id.'.json', ['user' => ['lastname' => 'Changed', 'status' => 3]]);
        $this->assertStatus(204);
        $this->em->clear();
        $user = $this->em->find(User::class, $id);
        self::assertSame('Changed', $user->lastname);
        self::assertSame(Principal::STATUS_LOCKED, $user->status);

        $this->api('DELETE', '/users/'.$id.'.json');
        $this->assertStatus(204);
        $this->em->clear();
        self::assertNull($this->em->find(Principal::class, $id));
    }

    public function testGroupsApi(): void
    {
        $this->api('GET', '/groups.json');
        self::assertSame([['id' => 10, 'name' => 'Developers']], $this->json()['groups']);
        $this->api('GET', '/groups.json?builtin=1');
        self::assertSame([['id' => 10, 'name' => 'Developers'], ['id' => 2, 'name' => 'Anonymous users', 'builtin' => 'anonymous'], ['id' => 3, 'name' => 'Non member users', 'builtin' => 'non_member']], $this->json()['groups']);
        $this->api('GET', '/groups/10.json?include=users,memberships');
        $g = $this->json()['group'];
        self::assertSame([['id' => 6, 'name' => 'Dave Lopper']], $g['users']);
        self::assertSame([['id' => 6, 'project' => ['id' => 1, 'name' => 'eCommerce'], 'roles' => [['id' => 4, 'name' => 'Developer']]]], $g['memberships']);

        $this->api('POST', '/groups.json', ['group' => ['name' => 'API group', 'user_ids' => [$this->user('anna')->id]]]);
        $this->assertStatus(201);
        $gid = $this->json()['group']['id'];
        $this->em->clear();
        self::assertCount(1, $this->em->find(Group::class, $gid)->users);

        $jsmith = $this->user('jsmith')->id;
        $this->api('POST', '/groups/'.$gid.'/users.json', ['user_id' => $jsmith]);
        $this->assertStatus(204);
        $this->api('POST', '/groups/'.$gid.'/users.json', ['user_id' => 99999]);
        $this->assertStatus(422);
        $this->api('DELETE', '/groups/'.$gid.'/users/'.$jsmith.'.json');
        $this->assertStatus(204);
        $this->api('PUT', '/groups/'.$gid.'.json', ['group' => ['name' => 'Renamed']]);
        $this->assertStatus(204);
        $this->api('PUT', '/groups/'.$gid.'.json', ['group' => ['name' => '']]);
        $this->assertStatus(422);
        $this->api('DELETE', '/groups/'.$gid.'.json');
        $this->assertStatus(204);
        $this->em->clear();
        self::assertNull($this->em->find(Principal::class, $gid));
    }
}

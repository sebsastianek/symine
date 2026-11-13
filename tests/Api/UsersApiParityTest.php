<?php

declare(strict_types=1);

namespace App\Tests\Api;

/** Port of api_test/users_test.rb on the seeded data. */
final class UsersApiParityTest extends ApiTestCase
{
    use AdminApiHelpers;

    public function testIndexXml(): void
    {
        $xml = $this->xml('GET', '/users.xml', null, 'admin');
        $this->assertXmlResponse();
        self::assertSame(['5', '0', '25'], [(string) $xml['total_count'], (string) $xml['offset'], (string) $xml['limit']]);
        self::assertSame(['admin', 'anna', 'dlopper', 'jsmith', 'rhill'], self::xpath($xml, '/users/user/login'));
        $jsmith = $xml->xpath('/users/user[login="jsmith"]')[0];
        self::assertSame('5', (string) $jsmith->id);
        self::assertSame('jsmith@example.net', (string) $jsmith->mail);
        self::assertSame('1', (string) $jsmith->status);
        self::assertSame('false', (string) $jsmith->admin);
        self::assertSame('+1 555 0100', (string) $jsmith->custom_fields->custom_field->value);
    }

    public function testIndexJson(): void
    {
        $data = $this->json('GET', '/users.json', null, 'admin');
        $this->assertJsonResponse();
        self::assertSame([1, 9, 6, 5, 7], self::ids($data['users']));
        $this->assertPaging($data, 5, 0, 25);
        $u = $data['users'][3];
        foreach (['id', 'login', 'admin', 'firstname', 'lastname', 'mail', 'created_on', 'updated_on', 'last_login_on', 'passwd_changed_on', 'twofa_scheme', 'status', 'custom_fields'] as $key) {
            self::assertArrayHasKey($key, $u, $key);
        }
        self::assertArrayNotHasKey('api_key', $u);
    }

    public function testIndexRequiresAdmin(): void
    {
        $this->request('GET', '/users.json', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/users.json', null, 'jsmith');
        $this->assertStatus(403);
        $this->request('GET', '/users.xml', null, 'dlopper');
        $this->assertStatus(403);
    }

    public function testLegacyFilterParams(): void
    {
        $data = $this->json('GET', '/users.json?status=3', null, 'admin');
        self::assertSame([8], self::ids($data['users']));
        $data = $this->json('GET', '/users.json?status=*', null, 'admin');
        self::assertSame(6, $data['total_count'], 'all logged users');
        $data = $this->json('GET', '/users.json?status=1|3', null, 'admin');
        self::assertSame(6, $data['total_count']);
        $data = $this->json('GET', '/users.json?name=jsmith', null, 'admin');
        self::assertSame([5], self::ids($data['users']));
        $data = $this->json('GET', '/users.json?name=Lopper', null, 'admin');
        self::assertSame([6], self::ids($data['users']));
        $data = $this->json('GET', '/users.json?name=rhill@example.net', null, 'admin');
        self::assertSame([7], self::ids($data['users']));
        $data = $this->json('GET', '/users.json?group_id=10', null, 'admin');
        self::assertSame([6], self::ids($data['users']));

        // implicit status = 1 filter
        $this->conn()->executeStatement('UPDATE users SET status = 3 WHERE id IN (5, 6)');
        $data = $this->json('GET', '/users.json?name=jsmith', null, 'admin');
        self::assertSame([], $data['users']);
        $data = $this->json('GET', '/users.json?group_id=10', null, 'admin');
        self::assertSame([], $data['users']);
    }

    public function testPaging(): void
    {
        $data = $this->json('GET', '/users.json?offset=1&limit=2', null, 'admin');
        self::assertSame(['anna', 'dlopper'], array_column($data['users'], 'login'));
        $this->assertPaging($data, 5, 1, 2);
        $data = $this->json('GET', '/users.json?offset=4&limit=2', null, 'admin');
        self::assertSame(['rhill'], array_column($data['users'], 'login'));
        $this->assertPaging($data, 5, 4, 2);
    }

    public function testIncludeAuthSource(): void
    {
        $this->conn()->executeStatement("INSERT INTO auth_sources (id, type, name, host, port, attr_login, onthefly_register, tls, timeout) VALUES (1, 'AuthSourceLdap', 'LDAP test', 'ldap.example.net', 389, 'uid', 0, 0, 5)");
        $this->conn()->executeStatement('UPDATE users SET auth_source_id = 1 WHERE id = 7');
        $data = $this->json('GET', '/users.json?include=auth_source', null, 'admin');
        foreach ($data['users'] as $u) {
            if (7 === $u['id']) {
                self::assertSame(['id' => 1, 'name' => 'LDAP test'], $u['auth_source']);
            } else {
                self::assertArrayNotHasKey('auth_source', $u);
            }
        }
        $data = $this->json('GET', '/users/7.json?include=auth_source', null, 'admin');
        self::assertSame(['id' => 1, 'name' => 'LDAP test'], $data['user']['auth_source']);
        $data = $this->json('GET', '/users/7.json', null, 'admin');
        self::assertArrayNotHasKey('auth_source', $data['user']);
        $data = $this->json('GET', '/users/7.json?include=auth_source', null, 'jsmith');
        self::assertArrayNotHasKey('auth_source', $data['user']);
    }

    public function testShowXmlAsAnonymous(): void
    {
        $xml = $this->xml('GET', '/users/5.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('5', (string) $xml->id);
        self::assertSame('John', (string) $xml->firstname);
        self::assertSame('jsmith', (string) $xml->login);
        self::assertCount(0, $xml->api_key);
        self::assertCount(0, $xml->status);
        self::assertCount(0, $xml->admin);
    }

    public function testShowJsonForOtherUser(): void
    {
        $data = $this->json('GET', '/users/6.json', null, 'jsmith');
        $this->assertJsonResponse();
        $u = $data['user'];
        self::assertSame('dlopper', $u['login'], 'login is returned for visible users');
        self::assertSame(['Dave', 'Lopper'], [$u['firstname'], $u['lastname']]);
        foreach (['api_key', 'status', 'admin', 'twofa_scheme', 'mail'] as $hidden) {
            self::assertArrayNotHasKey($hidden, $u, $hidden);
        }
    }

    public function testShowJsonForCurrentUserAndAdmin(): void
    {
        $data = $this->json('GET', '/users/5.json', null, 'jsmith');
        self::assertSame(self::JSMITH_KEY, $data['user']['api_key']);
        self::assertFalse($data['user']['admin']);
        self::assertArrayNotHasKey('status', $data['user']);

        $this->conn()->executeStatement("UPDATE users SET twofa_scheme = 'totp' WHERE id = 6");
        $data = $this->json('GET', '/users/6.json', null, 'admin');
        self::assertSame(1, $data['user']['status']);
        self::assertFalse($data['user']['admin']);
        self::assertSame('totp', $data['user']['twofa_scheme']);
        self::assertSame('dlopper@example.net', $data['user']['mail']);
        self::assertArrayHasKey('api_key', $data['user']);
        $data = $this->json('GET', '/users/6.json', null, 'jsmith');
        self::assertArrayNotHasKey('twofa_scheme', $data['user']);
    }

    public function testMailIsShownWhenNotHidden(): void
    {
        $this->conn()->executeStatement('UPDATE user_preferences SET hide_mail = 0 WHERE user_id = 6');
        if (0 === $this->rows('user_preferences', 'user_id = 6')) {
            $this->conn()->executeStatement('INSERT INTO user_preferences (user_id, hide_mail, others) VALUES (6, 0, ?)', ['{}']);
        }
        $data = $this->json('GET', '/users/6.json', null, 'jsmith');
        self::assertSame('dlopper@example.net', $data['user']['mail']);
    }

    public function testShowLockedUser(): void
    {
        $this->request('GET', '/users/8.json', null, 'jsmith');
        $this->assertStatus(404);
        $data = $this->json('GET', '/users/8.json', null, 'admin');
        $this->assertStatus(200);
        self::assertSame(3, $data['user']['status']);
        $this->request('GET', '/users/999.json', null, 'admin');
        $this->assertStatus(404);
    }

    public function testShowRespectsUsersVisibility(): void
    {
        // anna is a member of nothing: she sees the members of visible (public) projects only
        $data = $this->json('GET', '/users/6.json', null, 'anna');
        $this->assertStatus(200);
        self::assertSame('dlopper', $data['user']['login']);
        $this->conn()->executeStatement('UPDATE projects SET is_public = 0');
        $this->request('GET', '/users/6.json', null, 'anna');
        $this->assertStatus(404);
        // with users_visibility = all on the Non member role: every active user
        $this->conn()->executeStatement("UPDATE roles SET users_visibility = 'all' WHERE id = 1");
        $this->request('GET', '/users/6.json', null, 'anna');
        $this->assertStatus(200);
    }

    public function testIncludeMembershipsAndGroups(): void
    {
        $data = $this->json('GET', '/users/6.json?include=memberships,groups', null, 'admin');
        self::assertSame([['id' => 10, 'name' => 'Developers']], $data['user']['groups']);
        self::assertSame([['id' => 2, 'project' => ['id' => 1, 'name' => 'eCommerce'], 'roles' => [['id' => 4, 'name' => 'Developer'], ['id' => 4, 'name' => 'Developer', 'inherited' => true]]]], $data['user']['memberships']);
        $xml = $this->xml('GET', '/users/5.xml?include=memberships', null, 'admin');
        self::assertSame(['eCommerce', 'Internal Tools', 'Checkout'], self::xpath($xml, '/user/memberships/membership/project/@name'));
        self::assertSame('array', (string) $xml->memberships['type']);
    }

    public function testMembershipsOnlyInVisibleProjects(): void
    {
        // jsmith is member of the private "internal" project: rhill does not see that membership
        $data = $this->json('GET', '/users/5.json?include=memberships', null, 'rhill');
        self::assertSame([1, 3], array_column(array_column($data['user']['memberships'], 'project'), 'id'));
        $data = $this->json('GET', '/users/5.json?include=memberships', null, 'jsmith');
        self::assertSame([1, 2, 3], array_column(array_column($data['user']['memberships'], 'project'), 'id'));
    }

    public function testCurrent(): void
    {
        $this->request('GET', '/users/current.xml', null, null);
        $this->assertStatus(401);
        $xml = $this->xml('GET', '/users/current.xml', null, 'jsmith');
        $this->assertXmlResponse();
        self::assertSame('jsmith', (string) $xml->login);
        self::assertSame(self::JSMITH_KEY, (string) $xml->api_key);
        // groups are only included for administrators
        $data = $this->json('GET', '/users/current.json?include=memberships,groups', null, 'dlopper');
        self::assertSame(6, $data['user']['id']);
        self::assertArrayNotHasKey('groups', $data['user']);
        self::assertCount(1, $data['user']['memberships']);
        $data = $this->json('GET', '/users/current.json?include=groups', null, 'admin');
        self::assertSame([], $data['user']['groups']);
    }

    public function testCreateXmlWithFormParams(): void
    {
        $before = $this->rows('users');
        $this->form('POST', '/users.xml', ['user' => ['login' => 'foo', 'firstname' => 'Firstname', 'lastname' => 'Lastname', 'mail' => 'foo@example.net', 'password' => 'secret123', 'mail_notification' => 'only_assigned']]);
        $this->assertXmlResponse(201);
        self::assertSame($before + 1, $this->rows('users'));
        $row = $this->db("SELECT id, firstname, lastname, admin, mail_notification FROM users WHERE login = 'foo'")[0];
        self::assertSame(['Firstname', 'Lastname', 0, 'only_assigned'], [$row['firstname'], $row['lastname'], (int) $row['admin'], $row['mail_notification']]);
        self::assertSame('foo@example.net', $this->dbOne('SELECT address FROM email_addresses WHERE user_id = ? AND is_default = 1', [$row['id']]));
        self::assertSame((string) $row['id'], (string) simplexml_load_string($this->body())->id);
        // the password works
        $data = $this->json('GET', '/users/current.json', null, null, self::basic('foo', 'secret123'));
        $this->assertStatus(200);
        self::assertSame('foo', $data['user']['login']);
    }

    public function testCreateWithGeneratedPassword(): void
    {
        $this->form('POST', '/users.xml', ['user' => ['login' => 'foo', 'firstname' => 'Firstname', 'lastname' => 'Lastname', 'mail' => 'foo@example.net', 'generate_password' => 'true']]);
        $this->assertStatus(201);
        self::assertNotEmpty($this->dbOne("SELECT hashed_password FROM users WHERE login = 'foo'"));
    }

    public function testCreateJson(): void
    {
        $data = $this->json('POST', '/users.json', ['user' => ['login' => 'foo', 'firstname' => 'Firstname', 'lastname' => 'Lastname', 'mail' => 'foo@example.net', 'password' => 'secret123', 'admin' => true, 'custom_fields' => [['id' => 6, 'value' => '+33 1']]]], 'admin');
        $this->assertJsonResponse(201);
        $id = (int) $this->dbOne("SELECT id FROM users WHERE login = 'foo'");
        self::assertSame($id, $data['user']['id']);
        self::assertTrue($data['user']['admin']);
        self::assertSame('+33 1', $data['user']['custom_fields'][0]['value']);
    }

    public function testCreateInvalid(): void
    {
        $before = $this->rows('users');
        $this->form('POST', '/users.xml', ['user' => ['login' => 'foo', 'lastname' => 'Lastname', 'mail' => 'foo']]);
        $this->assertXmlResponse(422);
        $errors = self::xpath(simplexml_load_string($this->body()), '/errors/error');
        self::assertContains('First name cannot be blank', $errors);
        self::assertContains('Email is invalid', $errors);
        $data = $this->json('POST', '/users.json', ['user' => ['login' => 'jsmith', 'firstname' => 'J', 'lastname' => 'S', 'mail' => 'jsmith@example.net', 'password' => 'secret123']], 'admin');
        $this->assertErrors($data, 'Login has already been taken', 'Email has already been taken');
        $data = $this->json('POST', '/users.json', ['user' => ['login' => 'short', 'firstname' => 'S', 'lastname' => 'P', 'mail' => 'short@example.net', 'password' => 'abc']], 'admin');
        $this->assertErrors($data, 'Password is too short (minimum is 8 characters)');
        self::assertSame($before, $this->rows('users'));
    }

    public function testCreateRequiresAdmin(): void
    {
        $this->request('POST', '/users.json', ['user' => ['login' => 'foo', 'firstname' => 'F', 'lastname' => 'L', 'mail' => 'foo@example.net', 'password' => 'secret123']], 'jsmith');
        $this->assertStatus(403);
        $this->request('POST', '/users.json', ['user' => ['login' => 'foo']], null);
        $this->assertStatus(401);
        self::assertSame(0, $this->rows('users', "login = 'foo'"));
    }

    public function testUpdateXmlAndJson(): void
    {
        $this->form('PUT', '/users/5.xml', ['user' => ['login' => 'jsmith', 'firstname' => 'John', 'lastname' => 'Renamed', 'mail' => 'jsmith@somenet.foo']]);
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame('Renamed', $this->dbOne('SELECT lastname FROM users WHERE id = 5'));
        self::assertSame('jsmith@somenet.foo', $this->dbOne('SELECT address FROM email_addresses WHERE user_id = 5 AND is_default = 1'));
        self::assertSame(0, (int) $this->dbOne('SELECT admin FROM users WHERE id = 5'));

        $this->request('PUT', '/users/7.json', ['user' => ['firstname' => 'Bob', 'status' => 3, 'custom_fields' => [['id' => 6, 'value' => '42']]]], 'admin');
        $this->assertStatus(204);
        self::assertSame(['Bob', 3], [$this->dbOne('SELECT firstname FROM users WHERE id = 7'), (int) $this->dbOne('SELECT status FROM users WHERE id = 7')]);
        self::assertSame('42', $this->dbOne("SELECT value FROM custom_values WHERE customized_type = 'Principal' AND customized_id = 7 AND custom_field_id = 6"));
    }

    public function testUpdateInvalid(): void
    {
        $this->form('PUT', '/users/5.xml', ['user' => ['login' => 'jsmith', 'firstname' => '', 'lastname' => 'Lastname', 'mail' => 'foo']]);
        $this->assertXmlResponse(422);
        self::assertContains('First name cannot be blank', self::xpath(simplexml_load_string($this->body()), '/errors/error'));
        $data = $this->json('PUT', '/users/5.json', ['user' => ['login' => 'dlopper']], 'admin');
        $this->assertErrors($data, 'Login has already been taken');
        self::assertSame('Smith', $this->dbOne('SELECT lastname FROM users WHERE id = 5'));
    }

    public function testUpdateRequiresAdminAnd404(): void
    {
        $this->request('PUT', '/users/5.json', ['user' => ['firstname' => 'Hacker']], 'jsmith');
        $this->assertStatus(403);
        $this->request('PUT', '/users/999.json', ['user' => ['firstname' => 'X']], 'admin');
        $this->assertStatus(404);
        self::assertSame('John', $this->dbOne('SELECT firstname FROM users WHERE id = 5'));
    }

    public function testAdminChangingOwnMailDoesNotCreateSessionToken(): void
    {
        $this->conn()->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) VALUES (1, 'session', 'sessionsessionsessionsessionsession00001', datetime('now'), datetime('now'))");
        $this->request('PUT', '/users/1.json', ['user' => ['mail' => 'newadmin@somenet.foo']], 'admin');
        $this->assertStatus(204);
        self::assertSame('newadmin@somenet.foo', $this->dbOne('SELECT address FROM email_addresses WHERE user_id = 1 AND is_default = 1'));
        self::assertSame(0, $this->rows('tokens', "user_id = 1 AND action = 'session'"));
    }

    public function testDelete(): void
    {
        $before = $this->rows('users');
        $this->request('DELETE', '/users/7.json', null, 'jsmith');
        $this->assertStatus(403);
        $this->request('DELETE', '/users/7.xml', null, 'admin');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame($before - 1, $this->rows('users'));
        $this->request('DELETE', '/users/9.json', null, 'admin');
        $this->assertStatus(204);
        self::assertSame($before - 2, $this->rows('users'));
        $this->request('DELETE', '/users/9.json', null, 'admin');
        $this->assertStatus(404);
    }
}

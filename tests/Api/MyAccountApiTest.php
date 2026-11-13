<?php

declare(strict_types=1);

namespace App\Tests\Api;

/** Port of api_test/my_test.rb (OAuth scenarios are covered by tests/Auth). */
final class MyAccountApiTest extends ApiTestCase
{
    use AdminApiHelpers;

    public function testShowWithBasicAuth(): void
    {
        $data = $this->json('GET', '/my/account.json', null, null, self::basic('dlopper'));
        $this->assertJsonResponse();
        self::assertSame('dlopper', $data['user']['login']);
        self::assertSame(['id', 'login', 'admin', 'firstname', 'lastname', 'mail', 'created_on', 'last_login_on', 'api_key', 'custom_fields'], array_keys($data['user']));
    }

    public function testShowWithApiKeyDisclosesTheKey(): void
    {
        $data = $this->json('GET', '/my/account.json', null, 'jsmith');
        self::assertSame('jsmith', $data['user']['login']);
        self::assertSame(self::JSMITH_KEY, $data['user']['api_key']);
        self::assertSame('jsmith@example.net', $data['user']['mail']);
        self::assertSame([['id' => 6, 'name' => 'Phone', 'value' => '+1 555 0100']], $data['user']['custom_fields']);
        $xml = $this->xml('GET', '/my/account.xml', null, 'jsmith');
        $this->assertXmlResponse();
        self::assertSame(self::JSMITH_KEY, (string) $xml->api_key);
        self::assertSame('false', (string) $xml->admin);
    }

    public function testRequiresLogin(): void
    {
        $this->request('GET', '/my/account.json', null, null);
        $this->assertStatus(401);
        $this->request('PUT', '/my/account.json', ['user' => ['firstname' => 'Anon']], null);
        $this->assertStatus(401);
    }

    public function testUpdateXml(): void
    {
        $this->form('PUT', '/my/account.xml', ['user' => ['firstname' => 'Dave', 'lastname' => 'Renamed', 'mail' => 'dave@somenet.foo', 'admin' => '1']], 'dlopper');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        $row = $this->db('SELECT firstname, lastname, admin FROM users WHERE id = 6')[0];
        self::assertSame(['Dave', 'Renamed', 0], [$row['firstname'], $row['lastname'], (int) $row['admin']]);
        self::assertSame('dave@somenet.foo', $this->dbOne('SELECT address FROM email_addresses WHERE user_id = 6 AND is_default = 1'));
    }

    public function testUpdateJsonWithCustomFieldsAndPreferences(): void
    {
        $this->request('PUT', '/my/account.json', ['user' => ['firstname' => 'Renamed', 'custom_fields' => [['id' => 6, 'value' => '+49 1']]]], 'jsmith');
        $this->assertStatus(204);
        self::assertSame('Renamed', $this->dbOne('SELECT firstname FROM users WHERE id = 5'));
        self::assertSame('+49 1', $this->dbOne("SELECT value FROM custom_values WHERE customized_type = 'Principal' AND customized_id = 5 AND custom_field_id = 6"));
        // login cannot be changed by the user
        $this->request('PUT', '/my/account.json', ['user' => ['login' => 'johnny']], 'jsmith');
        $this->assertStatus(204);
        self::assertSame('jsmith', $this->dbOne('SELECT login FROM users WHERE id = 5'));
    }

    public function testUpdateInvalid(): void
    {
        $this->form('PUT', '/my/account.xml', ['user' => ['login' => 'dlopper', 'firstname' => '', 'lastname' => 'Lastname']], 'dlopper');
        $this->assertXmlResponse(422);
        self::assertSame(['First name cannot be blank'], self::xpath(simplexml_load_string($this->body()), '/errors/error'));
        $data = $this->json('PUT', '/my/account.json', ['user' => ['firstname' => '', 'mail' => 'not-a-mail']], 'dlopper');
        $this->assertErrors($data, 'First name cannot be blank', 'Email is invalid');
        self::assertSame('Dave', $this->dbOne('SELECT firstname FROM users WHERE id = 6'));
    }

    public function testChangedMailRemovesSessionTokens(): void
    {
        $this->conn()->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) VALUES (6, 'session', 'sessionsessionsessionsessionsession00006', datetime('now'), datetime('now'))");
        $this->request('PUT', '/my/account.json', ['user' => ['mail' => 'dave@somenet.foo']], 'dlopper');
        $this->assertStatus(204);
        self::assertSame('dave@somenet.foo', $this->dbOne('SELECT address FROM email_addresses WHERE user_id = 6 AND is_default = 1'));
        self::assertSame(0, $this->rows('tokens', "user_id = 6 AND action = 'session'"));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Port of api_test/versions_test.rb. eCommerce versions: #1 "1.0" (open, shared with descendants, issue #13 with
 * 8h estimated / 2.5h spent), #2 "0.9" (closed).
 */
final class VersionsApiTest extends ApiTestCase
{
    use AdminApiHelpers;

    public function testIndexXml(): void
    {
        $xml = $this->xml('GET', '/projects/1/versions.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('array', (string) $xml['type']);
        self::assertSame('2', (string) $xml['total_count']);
        self::assertSame(['1', '2'], self::xpath($xml, '/versions/version/id'));
        self::assertSame(['1.0'], self::xpath($xml, '/versions/version[id=1]/name'));
        self::assertSame(['closed'], self::xpath($xml, '/versions/version[id=2]/status'));
    }

    public function testIndexJsonIncludesSharedVersions(): void
    {
        $data = $this->json('GET', '/projects/ecommerce/versions.json', null, 'jsmith');
        $this->assertJsonResponse();
        self::assertSame(2, $data['total_count']);
        self::assertSame(['id', 'project', 'name', 'description', 'status', 'due_date', 'sharing', 'wiki_page_title', 'created_on', 'updated_on'], array_keys($data['versions'][0]));
        // checkout (sub project) sees the version shared with descendants
        $data = $this->json('GET', '/projects/checkout/versions.json', null, 'jsmith');
        self::assertSame([1], self::ids($data['versions']));
        self::assertSame(['id' => 1, 'name' => 'eCommerce'], $data['versions'][0]['project']);
        // internal has no versions
        $data = $this->json('GET', '/projects/internal/versions.json', null, 'jsmith');
        self::assertSame(['versions' => [], 'total_count' => 0], $data);
    }

    public function testIndexPermissions(): void
    {
        $this->request('GET', '/projects/internal/versions.json', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/projects/internal/versions.json', null, 'rhill');
        $this->assertStatus(403);
        $this->request('GET', '/projects/nope/versions.json', null, 'jsmith');
        $this->assertStatus(404);
    }

    public function testShow(): void
    {
        $xml = $this->xml('GET', '/versions/1.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('1', (string) $xml->id);
        self::assertSame('1.0', (string) $xml->name);
        self::assertSame('descendants', (string) $xml->sharing);
        self::assertSame('8.0', (string) $xml->estimated_hours);
        self::assertSame('2.5', (string) $xml->spent_hours);
        $data = $this->json('GET', '/versions/2.json', null, 'jsmith');
        self::assertSame('0.9', $data['version']['name']);
        self::assertSame(0.0, $data['version']['estimated_hours']);
        self::assertSame(0.0, $data['version']['spent_hours']);
        $this->request('GET', '/versions/999.json', null, 'jsmith');
        $this->assertStatus(404);
    }

    public function testCreate(): void
    {
        $before = $this->rows('versions');
        $this->form('POST', '/projects/1/versions.xml', ['version' => ['name' => 'API test']], 'jsmith');
        $this->assertXmlResponse(201);
        self::assertSame($before + 1, $this->rows('versions'));
        $id = $this->lastId('versions');
        self::assertSame((string) $id, (string) simplexml_load_string($this->body())->id);
        self::assertSame('API test', $this->dbOne('SELECT name FROM versions WHERE id = ?', [$id]));
    }

    public function testCreateWithDueDateWikiPageAndSharing(): void
    {
        $data = $this->json('POST', '/projects/1/versions.json', ['version' => ['name' => 'API test', 'due_date' => '2012-01-24', 'wiki_page_title' => 'Wiki', 'sharing' => 'system', 'status' => 'locked', 'description' => 'Desc']], 'jsmith');
        $this->assertJsonResponse(201);
        self::assertSame('2012-01-24', $data['version']['due_date']);
        self::assertSame('Wiki', $data['version']['wiki_page_title']);
        self::assertSame('locked', $data['version']['status']);
        self::assertSame('2012-01-24', $this->dbOne('SELECT effective_date FROM versions WHERE id = ?', [$data['version']['id']]));
        self::assertSame('none', $data['version']['sharing'], 'only admins can share system wide: the attribute is ignored');
        $data = $this->json('POST', '/projects/1/versions.json', ['version' => ['name' => 'Shared', 'sharing' => 'system']], 'admin');
        self::assertSame('system', $data['version']['sharing']);
        $data = $this->json('POST', '/projects/1/versions.json', ['version' => ['name' => 'Tree', 'sharing' => 'tree']], 'jsmith');
        self::assertSame('tree', $data['version']['sharing']);

    }

    public function testCreateWithCustomFields(): void
    {
        $this->conn()->executeStatement("INSERT INTO custom_fields (id, name, field_format, type, position, visible, editable) VALUES (20, 'Codename', 'string', 'VersionCustomField', 1, 1, 1)");
        $this->form('POST', '/projects/1/versions.xml', ['version' => ['name' => 'API test', 'custom_fields' => [['id' => '20', 'value' => 'Some value']]]], 'jsmith');
        $this->assertXmlResponse(201);
        $xml = simplexml_load_string($this->body());
        self::assertSame(['Some value'], self::xpath($xml, '/version/custom_fields/custom_field[@id=20]/value'));
        self::assertSame('Some value', $this->dbOne("SELECT value FROM custom_values WHERE customized_type = 'Version' AND custom_field_id = 20"));
    }

    public function testCreateInvalid(): void
    {
        $before = $this->rows('versions');
        $this->form('POST', '/projects/1/versions.xml', ['version' => ['name' => '']], 'jsmith');
        $this->assertStatus(422);
        self::assertSame(['Name cannot be blank'], self::xpath(simplexml_load_string($this->body()), '/errors/error'));
        $data = $this->json('POST', '/projects/1/versions.json', ['version' => ['name' => '1.0']], 'jsmith');
        $this->assertErrors($data, 'Name has already been taken');
        $data = $this->json('POST', '/projects/1/versions.json', ['version' => ['name' => 'Bad date', 'due_date' => '2012-13-45']], 'jsmith');
        $this->assertErrors($data, 'Due date is not a valid date');
        self::assertSame($before, $this->rows('versions'));
    }

    public function testCreateRequiresManageVersions(): void
    {
        $this->request('POST', '/projects/1/versions.json', ['version' => ['name' => 'Nope']], 'rhill');
        $this->assertStatus(403);
        $this->request('POST', '/projects/1/versions.json', ['version' => ['name' => 'Nope']], null);
        $this->assertStatus(401);
        // dlopper (Developer: manage_versions)
        $this->request('POST', '/projects/1/versions.json', ['version' => ['name' => 'By dev']], 'dlopper');
        $this->assertStatus(201);
    }

    public function testUpdate(): void
    {
        $this->form('PUT', '/versions/2.xml', ['version' => ['name' => 'API update', 'wiki_page_title' => 'Wiki']], 'jsmith');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(['API update', 'Wiki'], array_values($this->db('SELECT name, wiki_page_title FROM versions WHERE id = 2')[0]));
        $data = $this->json('PUT', '/versions/2.json', ['version' => ['name' => '']], 'jsmith');
        $this->assertErrors($data, 'Name cannot be blank');
        $this->request('PUT', '/versions/2.json', ['version' => ['name' => 'X']], 'rhill');
        $this->assertStatus(403);
    }

    public function testDestroy(): void
    {
        $this->request('DELETE', '/versions/2.xml', null, 'jsmith');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(0, $this->rows('versions', 'id = 2'));
    }

    public function testDestroyUsedVersionFails(): void
    {
        $this->request('DELETE', '/versions/1.json', null, 'jsmith');
        $this->assertStatus(422);
        self::assertSame(1, $this->rows('versions', 'id = 1'));
        self::assertSame('', $this->body(), 'head :unprocessable_content');
    }
}

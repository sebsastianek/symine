<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Port of api_test/documents_test.rb (Redmine 7 documents API). Seeded document #1 "Specs" on eCommerce,
 * category #6 "User documentation"; categories: 6 User documentation, 7 Technical documentation.
 */
final class DocumentsApiTest extends ApiTestCase
{
    use AdminApiHelpers;

    private function addDocuments(): void
    {
        $this->conn()->executeStatement("INSERT INTO documents (id, title, description, created_on, project_id, category_id) VALUES (2, 'Second', 'd2', '2026-11-08 10:00:00', 1, 7), (3, 'Third', 'd3', '2026-11-09 10:00:00', 1, 7)");
    }

    public function testIndexXml(): void
    {
        $this->addDocuments();
        $xml = $this->xml('GET', '/projects/ecommerce/documents.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame(['array', '3'], [(string) $xml['type'], (string) $xml['total_count']]);
        // sorted by descending creation date
        self::assertSame(['3', '2', '1'], self::xpath($xml, '/documents/document/id'));
        $first = $xml->document[0];
        self::assertSame(['1', 'eCommerce'], [(string) $first->project['id'], (string) $first->project['name']]);
        self::assertSame(['7', 'Technical documentation'], [(string) $first->category['id'], (string) $first->category['name']]);
        self::assertSame('Third', (string) $first->title);
        self::assertSame('2026-11-09T10:00:00Z', (string) $first->created_on);
    }

    public function testIndexJsonAndPaging(): void
    {
        $this->addDocuments();
        $data = $this->json('GET', '/projects/ecommerce/documents.json', null, null);
        $this->assertJsonResponse();
        self::assertSame(3, $data['total_count']);
        self::assertSame(3, $data['documents'][0]['id']);
        self::assertSame(['id' => 7, 'name' => 'Technical documentation'], $data['documents'][0]['category']);
        $xml = $this->xml('GET', '/projects/ecommerce/documents.xml?limit=2&offset=1', null, null);
        self::assertSame(['3', '1', '2'], [(string) $xml['total_count'], (string) $xml['offset'], (string) $xml['limit']]);
        self::assertSame(['2', '1'], self::xpath($xml, '/documents/document/id'));
    }

    public function testIndexPermissions(): void
    {
        $this->removePermissions(2, 'view_documents');
        $this->request('GET', '/projects/ecommerce/documents.xml', null, null);
        $this->assertStatus(401);
        $this->removePermissions(5, 'view_documents');
        $this->request('GET', '/projects/ecommerce/documents.xml', null, 'rhill');
        $this->assertStatus(403);
        $this->request('GET', '/projects/ecommerce/documents.json', null, 'jsmith');
        $this->assertStatus(200);
    }

    public function testShow(): void
    {
        $xml = $this->xml('GET', '/documents/1.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('1', (string) $xml->id);
        self::assertSame('eCommerce', (string) $xml->project['name']);
        self::assertSame(['6', 'User documentation'], [(string) $xml->category['id'], (string) $xml->category['name']]);
        self::assertSame('Specs', (string) $xml->title);
        self::assertSame('Specification', (string) $xml->description);
        self::assertCount(0, $xml->attachments);
        $data = $this->json('GET', '/documents/1.json', null, null);
        self::assertSame(['id' => 1, 'title' => 'Specs'], ['id' => $data['document']['id'], 'title' => $data['document']['title']]);
        $this->request('GET', '/documents/999.json', null, null);
        $this->assertStatus(404);
    }

    public function testShowWithAttachmentsAndCustomFields(): void
    {
        $token = $this->upload('document content', 'document.txt');
        $this->request('PUT', '/documents/1.json', ['document' => ['uploads' => [['token' => $token, 'filename' => 'document.txt', 'content_type' => 'text/plain']]]], 'jsmith');
        $this->assertStatus(204);
        $this->conn()->executeStatement("INSERT INTO custom_fields (id, name, field_format, type, position, visible, editable) VALUES (21, 'Author', 'string', 'DocumentCustomField', 1, 1, 1)");
        $this->conn()->executeStatement("INSERT INTO custom_values (customized_type, customized_id, custom_field_id, value) VALUES ('Document', 1, 21, 'John Smith')");
        $xml = $this->xml('GET', '/documents/1.xml?include=attachments', null, null);
        self::assertSame('array', (string) $xml->attachments['type']);
        self::assertSame(['document.txt'], self::xpath($xml, '/document/attachments/attachment/filename'));
        self::assertMatchesRegularExpression('#^http://localhost/attachments/download/\d+/document\.txt$#', self::xpath($xml, '/document/attachments/attachment/content_url')[0]);
        self::assertSame(['John Smith'], self::xpath($xml, '/document/custom_fields/custom_field[@id=21][@name="Author"]/value'));
    }

    public function testShowOnPrivateProject(): void
    {
        $this->conn()->executeStatement('UPDATE projects SET is_public = 0 WHERE id = 1');
        $this->request('GET', '/documents/1.xml', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/documents/1.xml', null, 'anna');
        $this->assertStatus(403);
    }

    public function testCreateXml(): void
    {
        $payload = "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n<document><title>API document</title><description>This is a document created by the API</description><category_id>7</category_id></document>";
        $this->request('POST', '/projects/ecommerce/documents.xml', $payload, 'jsmith');
        $this->assertXmlResponse(201);
        $row = $this->db("SELECT id, description, category_id, project_id FROM documents WHERE title = 'API document'")[0];
        self::assertSame(['This is a document created by the API', 7, 1], [$row['description'], (int) $row['category_id'], (int) $row['project_id']]);
        self::assertSame((string) $row['id'], (string) simplexml_load_string($this->body())->id);
    }

    public function testCreateJsonWithAttachment(): void
    {
        $token = $this->upload('attached');
        $data = $this->json('POST', '/projects/ecommerce/documents.json', ['document' => ['title' => 'API document with attachment', 'category_id' => 6, 'uploads' => [['token' => $token, 'filename' => 'test.txt', 'content_type' => 'text/plain']]]], 'jsmith');
        $this->assertJsonResponse(201);
        self::assertSame('API document with attachment', $data['document']['title']);
        self::assertSame(1, $this->rows('attachments', "container_type = 'Document' AND container_id = ?", [$data['document']['id']]));
    }

    public function testCreateFailures(): void
    {
        $data = $this->json('POST', '/projects/ecommerce/documents.json', ['document' => ['title' => '', 'category_id' => 6]], 'jsmith');
        $this->assertErrors($data, 'Title cannot be blank');
        $this->request('POST', '/projects/ecommerce/documents.json', ['document' => ['title' => 'API document']], 'rhill');
        $this->assertStatus(403);
        $this->request('POST', '/projects/ecommerce/documents.json', ['document' => ['title' => 'API document']], null);
        $this->assertStatus(401);
        self::assertSame(1, $this->rows('documents'));
    }

    public function testUpdate(): void
    {
        $this->request('PUT', '/documents/1.xml', '<document><title>Updated title</title><description>Updated description</description><category_id>7</category_id></document>', 'jsmith');
        $this->assertStatus(204);
        self::assertSame(['Updated title', 'Updated description', 7], array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, array_values($this->db('SELECT title, description, category_id FROM documents WHERE id = 1')[0])));
        $this->request('PUT', '/documents/1.json', ['document' => ['title' => 'Json title']], 'jsmith');
        $this->assertStatus(204);
        self::assertSame('Json title', $this->dbOne('SELECT title FROM documents WHERE id = 1'));
    }

    public function testUpdateFailures(): void
    {
        $this->form('PUT', '/documents/1.xml', ['document' => ['title' => '']], 'jsmith');
        $this->assertStatus(422);
        self::assertContains('Title cannot be blank', self::xpath(simplexml_load_string($this->body()), '/errors/error'));
        $data = $this->json('PUT', '/documents/1.json', ['document' => ['title' => '']], 'jsmith');
        $this->assertErrors($data, 'Title cannot be blank');
        $this->request('PUT', '/documents/1.json', ['document' => ['title' => 'Hack']], 'rhill');
        $this->assertStatus(403);
        self::assertSame('Specs', $this->dbOne('SELECT title FROM documents WHERE id = 1'));
    }

    public function testDestroy(): void
    {
        $this->request('DELETE', '/documents/1.json', null, 'rhill');
        $this->assertStatus(403);
        $this->request('DELETE', '/documents/1.xml', null, 'jsmith');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(0, $this->rows('documents'));
    }
}

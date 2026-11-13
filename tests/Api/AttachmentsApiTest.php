<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Port of api_test/attachments_test.rb.
 * Seeded attachment 1: notes.txt (12 bytes, text/plain) on issue 13 by jsmith.
 */
final class AttachmentsApiTest extends ApiTestCase
{
    /** Attachment record on issue 18 (private project) without file */
    private function privateAttachment(): int
    {
        $this->conn()->executeStatement("INSERT INTO attachments (id, container_id, container_type, filename, disk_filename, filesize, content_type, digest, downloads, created_on, description, disk_directory, author_id) VALUES (50, 18, 'Issue', 'secret.txt', 'missing_secret.txt', 6, 'text/plain', '', 0, datetime('now'), 'secret', '2026/11', 5)");

        return 50;
    }

    public function testShowXml(): void
    {
        $xml = $this->xml('GET', '/attachments/1.xml');
        $this->assertXmlResponse();
        self::assertSame('attachment', $xml->getName());
        self::assertSame('1', (string) $xml->id);
        self::assertSame('notes.txt', (string) $xml->filename);
        self::assertSame('12', (string) $xml->filesize);
        self::assertSame('text/plain', (string) $xml->content_type);
        self::assertSame('http://localhost/attachments/download/1/notes.txt', (string) $xml->content_url);
        self::assertSame('John Smith', (string) $xml->author['name']);
        self::assertCount(0, $xml->thumbnail_url);
    }

    public function testShowJson(): void
    {
        $data = $this->json('GET', '/attachments/1.json', null, null);
        $this->assertJsonResponse();
        self::assertSame(['id', 'filename', 'filesize', 'content_type', 'description', 'content_url', 'author', 'created_on'], array_keys($data['attachment']));
        self::assertNull($data['attachment']['description']);
        self::assertSame(['id' => 5, 'name' => 'John Smith'], $data['attachment']['author']);
    }

    public function testShowImageIncludesThumbnailUrl(): void
    {
        $this->conn()->executeStatement("INSERT INTO attachments (id, container_id, container_type, filename, disk_filename, filesize, content_type, digest, downloads, created_on, description, disk_directory, author_id) VALUES (51, 13, 'Issue', 'image.png', 'missing_image.png', 10, 'image/png', '', 0, datetime('now'), NULL, '2026/11', 5)");
        $data = $this->json('GET', '/attachments/51.json');
        $this->assertStatus(200);
        self::assertSame('http://localhost/attachments/thumbnail/51', $data['attachment']['thumbnail_url']);
    }

    public function testShowDeniedForInvisibleContainer(): void
    {
        $id = $this->privateAttachment();
        $this->request('GET', '/attachments/'.$id.'.xml', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/attachments/'.$id.'.json', null, 'anna');
        $this->assertStatus(403);
        $this->request('GET', '/attachments/'.$id.'.json', null, 'jsmith');
        $this->assertStatus(200);
        $this->request('GET', '/attachments/999.json');
        $this->assertStatus(404);
    }

    public function testDownload(): void
    {
        $this->request('GET', '/attachments/download/1/notes.txt?key='.self::JSMITH_KEY, null, null);
        $this->assertStatus(200);
        self::assertSame("hello world\n", $this->client->getInternalResponse()->getContent());
        $this->request('GET', '/attachments/download/1/notes.txt', null, null);
        $this->assertStatus(200);
    }

    public function testDownloadDeniedWithoutCredentials(): void
    {
        $id = $this->privateAttachment();
        $this->request('GET', '/attachments/download/'.$id.'/secret.txt', null, null);
        self::assertContains($this->response()->getStatusCode(), [302, 401]);
        if (302 === $this->response()->getStatusCode()) {
            self::assertStringContainsString('/login', (string) $this->response()->headers->get('Location'));
        }
    }

    public function testDestroyXmlAndJson(): void
    {
        $this->request('DELETE', '/attachments/1.xml');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(0, $this->rows('attachments', 'id = 1'));
        // journalized on the issue
        self::assertSame(1, $this->rows('journal_details', "property = 'attachment' AND prop_key = '1' AND old_value = 'notes.txt'"));
        $this->request('DELETE', '/attachments/1.json');
        $this->assertStatus(404);
    }

    public function testDestroyPermissions(): void
    {
        // rhill (Reporter) cannot edit issue 13
        $this->request('DELETE', '/attachments/1.json', null, 'rhill');
        $this->assertStatus(403);
        $this->request('DELETE', '/attachments/1.json', null, null);
        $this->assertStatus(401);
        $id = $this->privateAttachment();
        $this->request('DELETE', '/attachments/'.$id.'.json', null, 'anna');
        $this->assertStatus(403);
        self::assertSame(2, $this->rows('attachments'));
    }

    public function testUpdate(): void
    {
        $this->request('PATCH', '/attachments/1.json', ['attachment' => ['filename' => 'renamed.txt', 'description' => 'updated']]);
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(['renamed.txt', 'updated'], array_values($this->db('SELECT filename, description FROM attachments WHERE id = 1')[0]));
        $this->request('PUT', '/attachments/1.xml', '<attachment><description>xml</description></attachment>');
        $this->assertStatus(204);
        self::assertSame('xml', $this->dbOne('SELECT description FROM attachments WHERE id = 1'));
    }

    public function testUpdateFailure(): void
    {
        $data = $this->json('PATCH', '/attachments/1.json', ['attachment' => ['filename' => '', 'description' => 'updated']]);
        $this->assertJsonResponse(422);
        self::assertContains('File cannot be blank', $data['errors']);
        $this->request('PATCH', '/attachments/1.json', ['attachment' => ['description' => 'x']], 'rhill');
        $this->assertStatus(403);
        self::assertSame('notes.txt', $this->dbOne('SELECT filename FROM attachments WHERE id = 1'));
    }

    public function testUploadXmlReturnsToken(): void
    {
        $before = $this->rows('attachments');
        $xml = $this->xml('POST', '/uploads.xml', 'File content', 'jsmith', ['CONTENT_TYPE' => 'application/octet-stream']);
        $this->assertXmlResponse(201);
        self::assertSame($before + 1, $this->rows('attachments'));
        $token = (string) $xml->token;
        $id = (int) $xml->id;
        self::assertMatchesRegularExpression('/^\d+\.[0-9a-f]+$/', $token);
        self::assertSame($id, (int) explode('.', $token)[0]);
        $row = $this->db('SELECT * FROM attachments WHERE id = ?', [$id])[0];
        self::assertNull($row['container_id']);
        self::assertSame(5, (int) $row['author_id']);
        self::assertSame(12, (int) $row['filesize']);
        self::assertSame('application/octet-stream', $row['content_type']);
        self::assertNotSame('', (string) $row['filename']);
        self::assertMatchesRegularExpression('/^\d+_[0-9a-z]+/', (string) $row['disk_filename']);
        // content type detected from the content (plain text without filename hint => octet-stream)
        $this->request('POST', '/uploads.json?filename=readme.md&content_type=image/png', '# Title', 'jsmith', ['CONTENT_TYPE' => 'application/octet-stream']);
        $this->assertStatus(201);
        self::assertSame('text/markdown', $this->dbOne('SELECT content_type FROM attachments ORDER BY id DESC LIMIT 1'));
        $this->request('POST', '/uploads.json?filename=pic.bin', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 'jsmith', ['CONTENT_TYPE' => 'application/octet-stream']);
        self::assertSame('image/png', $this->dbOne('SELECT content_type FROM attachments ORDER BY id DESC LIMIT 1'));
    }

    public function testUploadJsonReturnsToken(): void
    {
        $this->request('POST', '/uploads.json', 'File content', 'jsmith', ['CONTENT_TYPE' => 'application/octet-stream']);
        $this->assertJsonResponse(201);
        $upload = json_decode($this->body(), true)['upload'];
        self::assertArrayHasKey('token', $upload);
        self::assertArrayHasKey('id', $upload);
        self::assertSame(1, $this->rows('attachments', 'id = ? AND container_id IS NULL', [$upload['id']]));
    }

    public function testUploadFilenameParamAndNulSanitizing(): void
    {
        $this->request('POST', '/uploads.xml?filename=test.txt', 'File content', 'jsmith', ['CONTENT_TYPE' => 'application/octet-stream']);
        $this->assertStatus(201);
        $row = $this->db('SELECT filename, disk_filename FROM attachments ORDER BY id DESC LIMIT 1')[0];
        self::assertSame('test.txt', $row['filename']);
        self::assertStringEndsWith('_test.txt', (string) $row['disk_filename']);

        $this->request('POST', '/uploads.json?filename=hello%00world.txt', 'File content', 'jsmith', ['CONTENT_TYPE' => 'application/octet-stream']);
        $this->assertStatus(201);
        self::assertSame('hello_world.txt', $this->dbOne('SELECT filename FROM attachments ORDER BY id DESC LIMIT 1'));
    }

    public function testUploadRejectsOtherContentTypes(): void
    {
        $before = $this->rows('attachments');
        $this->request('POST', '/uploads.xml', 'PNG DATA', 'jsmith', ['CONTENT_TYPE' => 'image/png']);
        $this->assertStatus(406);
        self::assertSame($before, $this->rows('attachments'));
    }

    public function testUploadTooBig(): void
    {
        $this->setting('attachment_max_size', '1');
        $before = $this->rows('attachments');
        $xml = $this->xml('POST', '/uploads.xml', str_repeat('x', 2048), 'jsmith', ['CONTENT_TYPE' => 'application/octet-stream']);
        $this->assertStatus(422);
        self::assertSame('This file cannot be uploaded because it exceeds the maximum allowed file size (1024)', (string) $xml->error);
        self::assertSame($before, $this->rows('attachments'));
    }

    public function testUploadExtensionNotAllowed(): void
    {
        $this->setting('attachment_extensions_denied', 'exe');
        $this->request('POST', '/uploads.json?filename=virus.exe', 'MZ', 'jsmith', ['CONTENT_TYPE' => 'application/octet-stream']);
        $this->assertStatus(422);
        self::assertSame(['errors' => ['Attachment extension .exe is not allowed']], json_decode($this->body(), true));
    }

    public function testUploadEmptyFile(): void
    {
        $this->request('POST', '/uploads.json', '', 'jsmith', ['CONTENT_TYPE' => 'application/octet-stream']);
        $this->assertStatus(201);
        $token = json_decode($this->body(), true)['upload']['token'];
        $row = $this->db('SELECT filesize, digest FROM attachments WHERE id = ?', [(int) explode('.', $token)[0]])[0];
        self::assertSame(0, (int) $row['filesize']);
        self::assertNotSame('', (string) $row['digest']);
    }

    public function testUploadRequiresLogin(): void
    {
        $this->request('POST', '/uploads.json', 'x', null, ['CONTENT_TYPE' => 'application/octet-stream']);
        $this->assertStatus(401);
    }
}

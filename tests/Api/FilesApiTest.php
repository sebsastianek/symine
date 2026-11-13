<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Port of api_test/files_test.rb. ecommerce has the files module; internal has not.
 */
final class FilesApiTest extends ApiTestCase
{
    private function upload(string $content = 'File content', string $query = ''): string
    {
        $this->request('POST', '/uploads.json'.$query, $content, 'jsmith', ['CONTENT_TYPE' => 'application/octet-stream']);
        $this->assertStatus(201);

        return json_decode($this->body(), true)['upload']['token'];
    }

    public function testIndexEmpty(): void
    {
        $data = $this->json('GET', '/projects/ecommerce/files.json');
        $this->assertJsonResponse();
        self::assertSame(['files' => []], $data);
        $xml = $this->xml('GET', '/projects/1/files.xml');
        self::assertSame('files', $xml->getName());
        self::assertSame('array', (string) $xml['type']);
    }

    public function testCreateJsonAndList(): void
    {
        $token = $this->upload();
        $this->json('POST', '/projects/1/files.json', ['file' => ['token' => $token]]);
        $this->assertStatus(204);
        $row = $this->db('SELECT container_id, container_type FROM attachments ORDER BY id DESC LIMIT 1')[0];
        self::assertSame([1, 'Project'], [(int) $row['container_id'], $row['container_type']]);

        $data = $this->json('GET', '/projects/ecommerce/files.json');
        self::assertCount(1, $data['files']);
        $f = $data['files'][0];
        self::assertSame(['id', 'filename', 'filesize', 'content_type', 'description', 'content_url', 'author', 'created_on', 'digest', 'downloads'], array_keys($f));
        self::assertSame(12, $f['filesize']);
        self::assertSame(0, $f['downloads']);
        self::assertSame(hash('sha256', 'File content'), $f['digest']);
        self::assertSame(['id' => 5, 'name' => 'John Smith'], $f['author']);
    }

    public function testCreateXml(): void
    {
        $token = $this->upload();
        $this->request('POST', '/projects/1/files.xml', '<file><token>'.$token.'</token></file>');
        $this->assertStatus(204);
        $xml = $this->xml('GET', '/projects/1/files.xml');
        self::assertCount(1, $xml->file);
        self::assertSame('Project', $this->dbOne('SELECT container_type FROM attachments ORDER BY id DESC LIMIT 1'));
    }

    public function testCreateWithoutTokenIs400(): void
    {
        $this->json('POST', '/projects/1/files.json', ['file' => ['filename' => 'project_file.zip']]);
        $this->assertStatus(400);
        $this->json('POST', '/projects/1/files.json', ['file' => ['token' => '999.abcdef']]);
        $this->assertStatus(400);
    }

    public function testCreateWithFilenameAndDescription(): void
    {
        $token = $this->upload();
        $this->json('POST', '/projects/1/files.json', ['file' => ['filename' => 'New filename', 'description' => 'New description', 'token' => $token]]);
        $this->assertStatus(204);
        $row = $this->db('SELECT filename, description FROM attachments ORDER BY id DESC LIMIT 1')[0];
        self::assertSame(['New filename', 'New description'], [$row['filename'], $row['description']]);
    }

    public function testCreateAttachedToVersion(): void
    {
        $token = $this->upload();
        $this->json('POST', '/projects/1/files.json', ['file' => ['version_id' => 1, 'token' => $token]]);
        $this->assertStatus(204);
        self::assertSame('Version', $this->dbOne('SELECT container_type FROM attachments ORDER BY id DESC LIMIT 1'));
        $data = $this->json('GET', '/projects/1/files.json');
        self::assertSame(['id' => 1, 'name' => '1.0'], $data['files'][0]['version']);
        $xml = $this->xml('GET', '/projects/1/files.xml');
        self::assertSame('1.0', (string) $xml->file->version['name']);
    }

    public function testPermissions(): void
    {
        $token = $this->upload();
        // rhill (Reporter) has view_files but not manage_files
        $this->json('POST', '/projects/1/files.json', ['file' => ['token' => $token]], 'rhill');
        $this->assertStatus(403);
        $this->json('GET', '/projects/1/files.json', null, 'rhill');
        $this->assertStatus(200);
        $this->json('POST', '/projects/1/files.json', ['file' => ['token' => $token]], null);
        $this->assertStatus(401);
        // the files module is not enabled on internal
        $this->json('GET', '/projects/internal/files.json');
        $this->assertStatus(403);
        $this->json('GET', '/projects/internal/files.json', null, 'anna');
        $this->assertStatus(403);
        $this->json('GET', '/projects/nope/files.json');
        $this->assertStatus(404);
        self::assertSame(0, $this->rows('attachments', "container_type = 'Project'"));
    }
}

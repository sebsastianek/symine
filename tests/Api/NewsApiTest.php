<?php

declare(strict_types=1);

namespace App\Tests\Api;

/** Port of api_test/news_test.rb. Seeded news #1 "Release 0.9" on eCommerce by jsmith, comment #1 by rhill. */
final class NewsApiTest extends ApiTestCase
{
    use AdminApiHelpers;

    public function testIndexXml(): void
    {
        $xml = $this->xml('GET', '/news.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('array', (string) $xml['type']);
        self::assertSame(['1', '0', '25'], [(string) $xml['total_count'], (string) $xml['offset'], (string) $xml['limit']]);
        self::assertSame(['1'], self::xpath($xml, '/news/news/id'));
        self::assertSame(['Release 0.9'], self::xpath($xml, '/news/news/title'));
        self::assertSame(['eCommerce'], self::xpath($xml, '/news/news/project/@name'));
    }

    public function testIndexJson(): void
    {
        $data = $this->json('GET', '/news.json', null, null);
        $this->assertJsonResponse();
        self::assertSame([['id' => 1, 'project' => ['id' => 1, 'name' => 'eCommerce'], 'author' => ['id' => 5, 'name' => 'John Smith'], 'title' => 'Release 0.9', 'summary' => 'We shipped', 'description' => 'Details...']], array_map(static fn ($n) => array_diff_key($n, ['created_on' => 1]), $data['news']));
        $this->assertPaging($data, 1, 0, 25);
        $data = $this->json('GET', '/projects/ecommerce/news.json', null, 'jsmith');
        self::assertSame([1], self::ids($data['news']));
        $data = $this->json('GET', '/projects/checkout/news.json', null, 'jsmith');
        self::assertSame([], $data['news']);
        self::assertSame(0, $data['total_count']);
    }

    public function testIndexPaging(): void
    {
        foreach (['Second', 'Third'] as $i => $title) {
            $this->conn()->executeStatement("INSERT INTO news (title, summary, description, created_on, project_id, author_id) VALUES (?, '', 'x', ?, 1, 5)", [$title, '2026-11-08 10:0'.$i.':00']);
        }
        $data = $this->json('GET', '/news.json?limit=2', null, null);
        self::assertSame(['Third', 'Second'], array_column($data['news'], 'title'));
        $this->assertPaging($data, 3, 0, 2);
        $data = $this->json('GET', '/news.json?offset=2&limit=2', null, null);
        self::assertSame(['Release 0.9'], array_column($data['news'], 'title'));
        $this->assertPaging($data, 3, 2, 2);
    }

    public function testIndexVisibility(): void
    {
        $this->conn()->executeStatement("INSERT INTO enabled_modules (project_id, name) VALUES (2, 'news')");
        $this->conn()->executeStatement("INSERT INTO news (title, summary, description, created_on, project_id, author_id) VALUES ('Private', '', 'x', '2026-11-08 10:00:00', 2, 5)");
        $data = $this->json('GET', '/news.json', null, null);
        self::assertSame(['Release 0.9'], array_column($data['news'], 'title'));
        $data = $this->json('GET', '/news.json', null, 'jsmith');
        self::assertSame(['Private', 'Release 0.9'], array_column($data['news'], 'title'));
        $this->request('GET', '/projects/internal/news.json', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/projects/internal/news.json', null, 'rhill');
        $this->assertStatus(403);
    }

    public function testShowXmlAndJson(): void
    {
        $xml = $this->xml('GET', '/news/1.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('1', (string) $xml->id);
        self::assertSame('Release 0.9', (string) $xml->title);
        self::assertSame('Details...', (string) $xml->description);
        self::assertSame('John Smith', (string) $xml->author['name']);
        $data = $this->json('GET', '/news/1.json', null, 'jsmith');
        $this->assertJsonResponse();
        self::assertSame(1, $data['news']['id']);
        self::assertArrayNotHasKey('comments', $data['news']);
        $this->request('GET', '/news/999.json', null, 'jsmith');
        $this->assertStatus(404);
    }

    public function testShowWithComments(): void
    {
        $xml = $this->xml('GET', '/news/1.xml?include=comments', null, null);
        self::assertSame('array', (string) $xml->comments['type']);
        self::assertSame(['1'], self::xpath($xml, '/news/comments/comment/@id'));
        self::assertSame(['Robert Hill'], self::xpath($xml, '/news/comments/comment/author/@name'));
        self::assertSame(['Great!'], self::xpath($xml, '/news/comments/comment/content'));
        $data = $this->json('GET', '/news/1.json?include=comments', null, null);
        self::assertSame([['id' => 1, 'author' => ['id' => 7, 'name' => 'Robert Hill'], 'content' => 'Great!']], $data['news']['comments']);
    }

    public function testCreateXmlPayload(): void
    {
        $payload = <<<'XML'
            <?xml version="1.0" encoding="UTF-8" ?>
            <news>
              <title>NewsXmlApiTest</title>
              <summary>News XML-API Test</summary>
              <description>This is the description</description>
            </news>
            XML;
        $this->request('POST', '/projects/1/news.xml', $payload, 'jsmith');
        $this->assertStatus(204);
        $row = $this->db("SELECT summary, description, author_id, project_id FROM news WHERE title = 'NewsXmlApiTest'")[0];
        self::assertSame(['News XML-API Test', 'This is the description', 5, 1], [$row['summary'], $row['description'], (int) $row['author_id'], (int) $row['project_id']]);
    }

    public function testCreateJson(): void
    {
        $this->request('POST', '/projects/1/news.json', ['news' => ['title' => 'NewsJsonApiTest', 'summary' => 'News JSON-API Test', 'description' => 'This is the description']], 'jsmith');
        $this->assertStatus(204);
        self::assertSame('News JSON-API Test', $this->dbOne("SELECT summary FROM news WHERE title = 'NewsJsonApiTest'"));
        // POST /news.json: the project is the top level project_id parameter (find_project_by_project_id)
        $this->request('POST', '/news.json', ['news' => ['project_id' => 'checkout', 'title' => 'Global', 'description' => 'x']], 'jsmith');
        $this->assertStatus(404);
        $this->request('POST', '/news.json', ['project_id' => 'checkout', 'news' => ['title' => 'Global', 'description' => 'x']], 'jsmith');
        $this->assertStatus(204);
        self::assertSame(3, (int) $this->dbOne("SELECT project_id FROM news WHERE title = 'Global'"));
    }

    public function testCreateFailures(): void
    {
        $this->form('POST', '/projects/1/news.xml', ['news' => ['title' => '']], 'jsmith');
        $this->assertStatus(422);
        self::assertContains('Title cannot be blank', self::xpath(simplexml_load_string($this->body()), '/errors/error'));
        $data = $this->json('POST', '/projects/1/news.json', ['news' => ['title' => '']], 'jsmith');
        $this->assertErrors($data, 'Title cannot be blank', 'Description cannot be blank');
        self::assertSame(1, $this->rows('news'));
    }

    public function testCreatePermissions(): void
    {
        $this->request('POST', '/projects/1/news.json', ['news' => ['title' => 'Nope', 'description' => 'x']], 'rhill');
        $this->assertStatus(403);
        $this->request('POST', '/projects/1/news.json', ['news' => ['title' => 'Nope', 'description' => 'x']], null);
        $this->assertStatus(401);
        self::assertSame(1, $this->rows('news'));
    }

    public function testCreateWithAttachment(): void
    {
        $token = $this->upload('test_create_with_attachment');
        $this->form('POST', '/projects/1/news.xml', ['news' => ['title' => 'News XML-API with Attachment', 'description' => 'desc'], 'attachments' => [['token' => $token, 'filename' => 'test.txt', 'content_type' => 'text/plain']]], 'jsmith');
        $this->assertStatus(204);
        $newsId = (int) $this->dbOne("SELECT id FROM news WHERE title = 'News XML-API with Attachment'");
        $att = $this->db("SELECT filename, content_type, filesize, author_id FROM attachments WHERE container_type = 'News' AND container_id = ?", [$newsId]);
        self::assertSame([['filename' => 'test.txt', 'content_type' => 'text/plain', 'filesize' => \strlen('test_create_with_attachment'), 'author_id' => 5]], array_map(static fn ($r) => ['filename' => $r['filename'], 'content_type' => $r['content_type'], 'filesize' => (int) $r['filesize'], 'author_id' => (int) $r['author_id']], $att));
        $data = $this->json('GET', '/news/'.$newsId.'.json?include=attachments', null, 'jsmith');
        self::assertSame('test.txt', $data['news']['attachments'][0]['filename']);
    }

    public function testCreateWithMultipleUploadsXmlAndJson(): void
    {
        $t1 = $this->upload('File content 1');
        $t2 = $this->upload('File content 2');
        $payload = "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n<news><title>News XML-API with attachments</title><description>News with multiple attachments</description>"
            ."<uploads type=\"array\"><upload><token>$t1</token><filename>test1.txt</filename></upload><upload><token>$t2</token><filename>test2.txt</filename></upload></uploads></news>";
        $this->request('POST', '/projects/1/news.xml', $payload, 'jsmith');
        $this->assertStatus(204);
        $id = (int) $this->dbOne("SELECT id FROM news WHERE title = 'News XML-API with attachments'");
        self::assertSame(2, $this->rows('attachments', "container_type = 'News' AND container_id = ?", [$id]));

        $t3 = $this->upload('File content 3');
        $t4 = $this->upload('File content 4');
        $this->request('POST', '/projects/1/news.json', ['news' => ['title' => 'News JSON-API with attachments', 'description' => 'd', 'uploads' => [['token' => $t3, 'filename' => 'test3.txt', 'content_type' => 'text/plain'], ['token' => $t4, 'filename' => 'test4.txt']]]], 'jsmith');
        $this->assertStatus(204);
        $id = (int) $this->dbOne("SELECT id FROM news WHERE title = 'News JSON-API with attachments'");
        self::assertSame(['test3.txt', 'test4.txt'], array_column($this->db("SELECT filename FROM attachments WHERE container_type = 'News' AND container_id = ? ORDER BY id", [$id]), 'filename'));
    }

    public function testUpdate(): void
    {
        $this->request('PUT', '/news/1.xml', '<news><title>NewsUpdateXmlApiTest</title><summary>News Update XML-API Test</summary><description>update description via xml api</description></news>', 'jsmith');
        $this->assertStatus(204);
        self::assertSame(['NewsUpdateXmlApiTest', 'News Update XML-API Test', 'update description via xml api'], array_values($this->db('SELECT title, summary, description FROM news WHERE id = 1')[0]));
        $this->request('PUT', '/news/1.json', ['news' => ['title' => 'NewsUpdateJsonApiTest']], 'jsmith');
        $this->assertStatus(204);
        self::assertSame('NewsUpdateJsonApiTest', $this->dbOne('SELECT title FROM news WHERE id = 1'));
    }

    public function testUpdateFailuresAndPermissions(): void
    {
        $this->form('PUT', '/news/1.xml', ['news' => ['title' => '']], 'jsmith');
        $this->assertStatus(422);
        self::assertContains('Title cannot be blank', self::xpath(simplexml_load_string($this->body()), '/errors/error'));
        $data = $this->json('PUT', '/news/1.json', ['news' => ['title' => '']], 'jsmith');
        $this->assertErrors($data, 'Title cannot be blank');
        $this->request('PUT', '/news/1.json', ['news' => ['title' => 'Hacked']], 'rhill');
        $this->assertStatus(403);
        self::assertSame('Release 0.9', $this->dbOne('SELECT title FROM news WHERE id = 1'));
    }

    public function testUpdateWithUploads(): void
    {
        $t1 = $this->upload('File content 1');
        $t2 = $this->upload('File content 2');
        $this->request('PUT', '/news/1.json', ['news' => ['uploads' => [['token' => $t1, 'filename' => 'test1.txt'], ['token' => $t2, 'filename' => 'test2.txt']]]], 'jsmith');
        $this->assertStatus(204);
        self::assertSame(2, $this->rows('attachments', "container_type = 'News' AND container_id = 1"));
    }

    public function testDestroy(): void
    {
        $this->request('DELETE', '/news/1.json', null, 'rhill');
        $this->assertStatus(403);
        $this->request('DELETE', '/news/1.xml', null, 'jsmith');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(0, $this->rows('news'));
        self::assertSame(0, $this->rows('comments', "commented_type = 'News'"));
        $this->request('DELETE', '/news/1.json', null, 'jsmith');
        $this->assertStatus(404);
    }
}

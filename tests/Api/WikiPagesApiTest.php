<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Port of api_test/wiki_pages_test.rb. eCommerce wiki: "Wiki" (start page, v1) and "Checkout_flow"
 * (child of Wiki, v2 "update" by dlopper, v1 by jsmith).
 */
final class WikiPagesApiTest extends ApiTestCase
{
    use AdminApiHelpers;

    public function testIndexXml(): void
    {
        $xml = $this->xml('GET', '/projects/ecommerce/wiki/index.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('array', (string) $xml['type']);
        self::assertSame(['Checkout_flow', 'Wiki'], self::xpath($xml, '/wiki_pages/wiki_page/title'));
        self::assertSame(['Wiki'], self::xpath($xml, '/wiki_pages/wiki_page[title="Checkout_flow"]/parent/@title'));
        self::assertSame(['2'], self::xpath($xml, '/wiki_pages/wiki_page[title="Checkout_flow"]/version'));
        self::assertCount(0, $xml->xpath('/wiki_pages/wiki_page[title="Wiki"]/parent'));
    }

    public function testIndexJson(): void
    {
        $data = $this->json('GET', '/projects/ecommerce/wiki/index.json', null, 'jsmith');
        $this->assertJsonResponse();
        self::assertSame(['title' => 'Checkout_flow', 'parent' => ['title' => 'Wiki'], 'version' => 2, 'project' => ['id' => 1, 'name' => 'eCommerce']], array_diff_key($data['wiki_pages'][0], ['created_on' => 1, 'updated_on' => 1]));
        self::assertArrayNotHasKey('text', $data['wiki_pages'][0]);
    }

    public function testIndexPermissions(): void
    {
        $this->request('GET', '/projects/internal/wiki/index.json', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/projects/internal/wiki/index.json', null, 'rhill');
        $this->assertStatus(403);
        $data = $this->json('GET', '/projects/internal/wiki/index.json', null, 'jsmith');
        $this->assertStatus(200);
        self::assertSame([], $data['wiki_pages']);
        $this->request('GET', '/projects/checkout/wiki/index.json', null, 'jsmith');
        $this->assertStatus(404, 'no wiki (find_wiki)');
    }

    public function testShowXml(): void
    {
        $xml = $this->xml('GET', '/projects/ecommerce/wiki/Checkout_flow.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('Checkout_flow', (string) $xml->title);
        self::assertSame('Wiki', (string) $xml->parent['title']);
        self::assertSame('Version 2 with more text', (string) $xml->text);
        self::assertSame('2', (string) $xml->version);
        self::assertSame('Dave Lopper', (string) $xml->author['name']);
        self::assertSame('update', (string) $xml->comments);
        self::assertNotSame('', (string) $xml->created_on);
        self::assertNotSame('', (string) $xml->updated_on);
    }

    public function testShowJsonWithAttachments(): void
    {
        $token = $this->upload('wiki attachment', 'spec.txt');
        $this->request('PUT', '/projects/ecommerce/wiki/Wiki.json', ['wiki_page' => ['text' => 'Home with file', 'uploads' => [['token' => $token, 'filename' => 'spec.txt', 'content_type' => 'text/plain']]]], 'jsmith');
        $this->assertStatus(204);
        $data = $this->json('GET', '/projects/ecommerce/wiki/Wiki.json?include=attachments', null, 'jsmith');
        $this->assertJsonResponse();
        self::assertSame('Home with file', $data['wiki_page']['text']);
        self::assertSame(2, $data['wiki_page']['version']);
        self::assertSame(['spec.txt'], array_column($data['wiki_page']['attachments'], 'filename'));
        self::assertSame('John Smith', $data['wiki_page']['attachments'][0]['author']['name']);
        $data = $this->json('GET', '/projects/ecommerce/wiki/Wiki.json', null, 'jsmith');
        self::assertArrayNotHasKey('attachments', $data['wiki_page']);
    }

    public function testShowUnknownPage(): void
    {
        $this->request('GET', '/projects/ecommerce/wiki/Invalid_Page.xml', null, 'jsmith');
        $this->assertStatus(404);
        $this->request('GET', '/projects/ecommerce/wiki/Invalid_Page.json', null, null);
        $this->assertStatus(404);
    }

    public function testShowVersion(): void
    {
        $xml = $this->xml('GET', '/projects/ecommerce/wiki/Checkout_flow/1.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('Checkout_flow', (string) $xml->title);
        self::assertSame('1', (string) $xml->version);
        self::assertSame('Version 1', (string) $xml->text);
        self::assertSame('John Smith', (string) $xml->author['name']);
        $data = $this->json('GET', '/projects/ecommerce/wiki/Checkout_flow/2.json', null, null);
        self::assertSame('update', $data['wiki_page']['comments']);
        $this->request('GET', '/projects/ecommerce/wiki/Checkout_flow/9.json', null, null);
        $this->assertStatus(404);
    }

    public function testShowVersionWithoutAuthor(): void
    {
        $this->conn()->executeStatement('UPDATE wiki_content_versions SET author_id = NULL WHERE page_id = 2 AND version = 1');
        $xml = $this->xml('GET', '/projects/ecommerce/wiki/Checkout_flow/1.xml', null, null);
        $this->assertStatus(200);
        self::assertCount(0, $xml->author);
    }

    public function testShowVersionRequiresViewWikiEdits(): void
    {
        $this->removePermissions(2, 'view_wiki_edits');
        $this->request('GET', '/projects/ecommerce/wiki/Checkout_flow/1.xml', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/projects/ecommerce/wiki/Checkout_flow.xml', null, null);
        $this->assertStatus(200, 'the current version stays readable');
    }

    public function testUpdatePage(): void
    {
        $versions = $this->rows('wiki_content_versions');
        $this->form('PUT', '/projects/ecommerce/wiki/Checkout_flow.xml', ['wiki_page' => ['text' => 'New content from API', 'comments' => 'API update']], 'jsmith');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame($versions + 1, $this->rows('wiki_content_versions'));
        $row = $this->db('SELECT text, version, comments, author_id FROM wiki_contents WHERE page_id = 2')[0];
        self::assertSame(['New content from API', 3, 'API update', 5], [$row['text'], (int) $row['version'], $row['comments'], (int) $row['author_id']]);
        self::assertSame(2, $this->rows('wiki_pages'));
    }

    public function testUpdateWithCurrentVersion(): void
    {
        $this->form('PUT', '/projects/ecommerce/wiki/Checkout_flow.xml', ['wiki_page' => ['text' => 'New content from API', 'comments' => 'API update', 'version' => '2']], 'jsmith');
        $this->assertStatus(204);
        self::assertSame(3, (int) $this->dbOne('SELECT version FROM wiki_contents WHERE page_id = 2'));
    }

    public function testUpdateWithStaleVersionIs409(): void
    {
        $versions = $this->rows('wiki_content_versions');
        $this->form('PUT', '/projects/ecommerce/wiki/Checkout_flow.xml', ['wiki_page' => ['text' => 'New content from API', 'comments' => 'API update', 'version' => '1']], 'jsmith');
        $this->assertStatus(409);
        self::assertSame($versions, $this->rows('wiki_content_versions'));
        self::assertSame('Version 2 with more text', $this->dbOne('SELECT text FROM wiki_contents WHERE page_id = 2'));
    }

    public function testUpdateValidation(): void
    {
        $data = $this->json('PUT', '/projects/ecommerce/wiki/Checkout_flow.json', ['wiki_page' => ['text' => 'x', 'comments' => str_repeat('c', 1100)]], 'jsmith');
        $this->assertErrors($data, 'Comment is too long (maximum is 1024 characters)');
    }

    public function testCreatePage(): void
    {
        $pages = $this->rows('wiki_pages');
        $this->form('PUT', '/projects/ecommerce/wiki/New_page_from_API.xml', ['wiki_page' => ['text' => 'New content from API', 'comments' => 'API create']], 'jsmith');
        $this->assertXmlResponse(201);
        self::assertSame($pages + 1, $this->rows('wiki_pages'));
        $xml = simplexml_load_string($this->body());
        self::assertSame('New_page_from_API', (string) $xml->title);
        self::assertSame('1', (string) $xml->version);
        $page = $this->db("SELECT p.parent_id, c.text, c.version, c.comments, c.author_id FROM wiki_pages p JOIN wiki_contents c ON c.page_id = p.id WHERE p.title = 'New_page_from_API'")[0];
        self::assertSame([null, 'New content from API', 1, 'API create', 5], [$page['parent_id'], $page['text'], (int) $page['version'], $page['comments'], (int) $page['author_id']]);
    }

    public function testCreatePageWithAttachment(): void
    {
        $token = $this->upload('testfile content', 'testfile.txt');
        $this->form('PUT', '/projects/ecommerce/wiki/New_page_from_API.xml', ['wiki_page' => ['text' => 'New content from API with Attachments', 'comments' => 'API create with Attachments', 'uploads' => [['token' => $token, 'filename' => 'testfile.txt', 'content_type' => 'text/plain']]]], 'jsmith');
        $this->assertStatus(201);
        $pageId = (int) $this->dbOne("SELECT id FROM wiki_pages WHERE title = 'New_page_from_API'");
        self::assertSame(['testfile.txt'], array_column($this->db("SELECT filename FROM attachments WHERE container_type = 'WikiPage' AND container_id = ?", [$pageId]), 'filename'));
    }

    public function testCreatePageWithParent(): void
    {
        $this->form('PUT', '/projects/ecommerce/wiki/New_subpage_from_API.xml', ['wiki_page' => ['parent_title' => 'Checkout_flow', 'text' => 'New content from API', 'comments' => 'API create']], 'jsmith');
        $this->assertStatus(201);
        self::assertSame(2, (int) $this->dbOne("SELECT parent_id FROM wiki_pages WHERE title = 'New_subpage_from_API'"));
        $data = $this->json('GET', '/projects/ecommerce/wiki/New_subpage_from_API.json', null, 'jsmith');
        self::assertSame(['title' => 'Checkout_flow'], $data['wiki_page']['parent']);
    }

    public function testWritePermissions(): void
    {
        $this->request('PUT', '/projects/ecommerce/wiki/Checkout_flow.json', ['wiki_page' => ['text' => 'x']], 'rhill');
        $this->assertStatus(403);
        $this->request('PUT', '/projects/ecommerce/wiki/Checkout_flow.json', ['wiki_page' => ['text' => 'x']], null);
        $this->assertStatus(401);
        $this->request('PUT', '/projects/ecommerce/wiki/Checkout_flow.json', ['wiki_page' => ['text' => 'by dev']], 'dlopper');
        $this->assertStatus(204);
    }

    public function testProtectedPageRequiresProtectWikiPages(): void
    {
        $this->conn()->executeStatement('UPDATE wiki_pages SET protected = 1 WHERE id = 2');
        // dlopper (Developer) can edit pages but cannot edit protected ones
        $this->request('PUT', '/projects/ecommerce/wiki/Checkout_flow.json', ['wiki_page' => ['text' => 'by dev']], 'dlopper');
        $this->assertStatus(403);
        $this->request('DELETE', '/projects/ecommerce/wiki/Checkout_flow.json', null, 'dlopper');
        $this->assertStatus(403);
        self::assertSame('Version 2 with more text', $this->dbOne('SELECT text FROM wiki_contents WHERE page_id = 2'));
        // jsmith (Manager: protect_wiki_pages) can
        $this->request('PUT', '/projects/ecommerce/wiki/Checkout_flow.json', ['wiki_page' => ['text' => 'by manager']], 'jsmith');
        $this->assertStatus(204);
    }

    public function testDestroy(): void
    {
        $this->request('DELETE', '/projects/ecommerce/wiki/Checkout_flow.xml', null, 'rhill');
        $this->assertStatus(403);
        $this->request('DELETE', '/projects/ecommerce/wiki/Checkout_flow.xml', null, 'jsmith');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(0, $this->rows('wiki_pages', 'id = 2'));
        self::assertSame(0, $this->rows('wiki_content_versions', 'page_id = 2'));
        $this->request('DELETE', '/projects/ecommerce/wiki/Checkout_flow.xml', null, 'jsmith');
        $this->assertStatus(404);
    }
}

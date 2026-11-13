<?php

declare(strict_types=1);

namespace App\Tests\Api;

/** Port of api_test/issue_categories_test.rb. eCommerce categories: #1 UI (assigned to dlopper, used by #13), #2 Backend. */
final class IssueCategoriesApiTest extends ApiTestCase
{
    use AdminApiHelpers;

    public function testIndex(): void
    {
        $xml = $this->xml('GET', '/projects/1/issue_categories.xml', null, 'jsmith');
        $this->assertXmlResponse();
        self::assertSame(['2', '1'], self::xpath($xml, '/issue_categories/issue_category/id'));
        self::assertSame('2', (string) $xml['total_count']);
        self::assertSame(['6'], self::xpath($xml, '/issue_categories/issue_category[id=1]/assigned_to/@id'));
        $data = $this->json('GET', '/projects/ecommerce/issue_categories.json', null, null);
        $this->assertJsonResponse();
        self::assertSame(['issue_categories' => [
            ['id' => 2, 'project' => ['id' => 1, 'name' => 'eCommerce'], 'name' => 'Backend'],
            ['id' => 1, 'project' => ['id' => 1, 'name' => 'eCommerce'], 'name' => 'UI', 'assigned_to' => ['id' => 6, 'name' => 'Dave Lopper']],
        ], 'total_count' => 2], $data);
    }

    public function testIndexAndShowRequireViewIssues(): void
    {
        $this->removePermissions(1, 'view_issues');
        $this->request('GET', '/projects/1/issue_categories.json', null, 'anna');
        $this->assertStatus(403);
        $this->request('GET', '/issue_categories/1.json', null, 'anna');
        $this->assertStatus(403);
        $this->request('GET', '/projects/1/issue_categories.json', null, 'rhill');
        $this->assertStatus(200);
        $this->request('GET', '/projects/internal/issue_categories.json', null, null);
        $this->assertStatus(401);
    }

    public function testShow(): void
    {
        $xml = $this->xml('GET', '/issue_categories/2.xml', null, 'jsmith');
        $this->assertXmlResponse();
        self::assertSame('2', (string) $xml->id);
        self::assertSame('Backend', (string) $xml->name);
        $data = $this->json('GET', '/issue_categories/1.json', null, 'rhill');
        self::assertSame(['id' => 6, 'name' => 'Dave Lopper'], $data['issue_category']['assigned_to']);
        $this->request('GET', '/issue_categories/999.json', null, 'jsmith');
        $this->assertStatus(404);
    }

    public function testCreateDeniedWithoutManageCategories(): void
    {
        $this->form('POST', '/projects/1/issue_categories.xml', ['issue_category' => ['name' => 'API']], 'rhill');
        $this->assertStatus(403);
        self::assertSame(2, $this->rows('issue_categories'));
    }

    public function testCreate(): void
    {
        $this->form('POST', '/projects/1/issue_categories.xml', ['issue_category' => ['name' => 'API', 'assigned_to_id' => 5]], 'jsmith');
        $this->assertXmlResponse(201);
        $id = $this->lastId('issue_categories');
        self::assertSame((string) $id, (string) simplexml_load_string($this->body())->id);
        self::assertSame(['API', 5, 1], [$this->dbOne('SELECT name FROM issue_categories WHERE id = ?', [$id]), (int) $this->dbOne('SELECT assigned_to_id FROM issue_categories WHERE id = ?', [$id]), (int) $this->dbOne('SELECT project_id FROM issue_categories WHERE id = ?', [$id])]);
        $data = $this->json('POST', '/projects/1/issue_categories.json', ['issue_category' => ['name' => 'Json']], 'dlopper');
        $this->assertJsonResponse(201);
        self::assertSame('Json', $data['issue_category']['name']);
    }

    public function testCreateInvalid(): void
    {
        $this->form('POST', '/projects/1/issue_categories.xml', ['issue_category' => ['name' => '']], 'jsmith');
        $this->assertStatus(422);
        self::assertSame(['Name cannot be blank'], self::xpath(simplexml_load_string($this->body()), '/errors/error'));
        $data = $this->json('POST', '/projects/1/issue_categories.json', ['issue_category' => ['name' => 'UI']], 'jsmith');
        $this->assertErrors($data, 'Name has already been taken');
        self::assertSame(2, $this->rows('issue_categories'));
    }

    public function testUpdate(): void
    {
        $this->form('PUT', '/issue_categories/2.xml', ['issue_category' => ['name' => 'API Update']], 'jsmith');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame('API Update', $this->dbOne('SELECT name FROM issue_categories WHERE id = 2'));
        $this->form('PUT', '/issue_categories/2.xml', ['issue_category' => ['name' => '']], 'jsmith');
        $this->assertStatus(422);
        self::assertSame(['Name cannot be blank'], self::xpath(simplexml_load_string($this->body()), '/errors/error'));
        $this->request('PUT', '/issue_categories/2.json', ['issue_category' => ['name' => 'X']], 'rhill');
        $this->assertStatus(403);
    }

    public function testDestroy(): void
    {
        $this->request('DELETE', '/issue_categories/2.xml', null, 'jsmith');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(0, $this->rows('issue_categories', 'id = 2'));
    }

    public function testDestroyUsedCategoryNullifiesOrReassigns(): void
    {
        $this->form('DELETE', '/issue_categories/1.xml', ['reassign_to_id' => 2], 'jsmith');
        $this->assertStatus(204);
        self::assertSame(2, (int) $this->dbOne('SELECT category_id FROM issues WHERE id = 13'));
        $this->request('DELETE', '/issue_categories/2.json', null, 'jsmith');
        $this->assertStatus(204);
        self::assertNull($this->dbOne('SELECT category_id FROM issues WHERE id = 13'));
    }
}

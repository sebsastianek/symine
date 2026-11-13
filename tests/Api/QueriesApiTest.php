<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Port of api_test/queries_test.rb: GET /queries lists the visible issue queries ordered by name.
 * Seeded queries: 1-4 global public issue queries, 5-6 project queries, 7 time entry query, 8 "Open bugs" (ecommerce, public).
 */
final class QueriesApiTest extends ApiTestCase
{
    public function testIndexXml(): void
    {
        $xml = $this->xml('GET', '/queries.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('array', (string) $xml['type']);
        self::assertSame('5', (string) $xml['total_count']);
        self::assertSame(['1', '8', '2', '3', '4'], array_map(static fn ($q) => (string) $q->id, iterator_to_array($xml->query, false)));
        self::assertSame('Open bugs', (string) $xml->query[1]->name);
        self::assertSame('true', (string) $xml->query[1]->is_public);
        self::assertSame('1', (string) $xml->query[1]->project_id);
        self::assertSame('', (string) $xml->query[0]->project_id);
    }

    public function testIndexJson(): void
    {
        $data = $this->json('GET', '/queries.json');
        $this->assertJsonResponse();
        self::assertSame(['id' => 1, 'name' => 'Issues assigned to me', 'is_public' => true, 'project_id' => null], $data['queries'][0]);
        self::assertSame(['id' => 8, 'name' => 'Open bugs', 'is_public' => true, 'project_id' => 1], $data['queries'][1]);
        $this->assertPaging($data, 5, 0, 25);
    }

    public function testPrivateQueriesAreOnlyVisibleToTheirOwner(): void
    {
        $this->conn()->executeStatement("INSERT INTO queries (id, project_id, name, filters, user_id, column_names, sort_criteria, group_by, type, visibility, options) VALUES (20, NULL, 'A private query', '{}', 6, NULL, NULL, NULL, 'IssueQuery', 0, NULL)");
        $this->conn()->executeStatement("INSERT INTO queries (id, project_id, name, filters, user_id, column_names, sort_criteria, group_by, type, visibility, options) VALUES (21, 2, 'Internal query', '{}', 5, NULL, NULL, NULL, 'IssueQuery', 2, NULL)");
        $data = $this->json('GET', '/queries.json', null, 'dlopper');
        self::assertSame([20, 1, 8, 2, 3, 4], self::ids($data['queries']));
        self::assertFalse($data['queries'][0]['is_public']);
        $data = $this->json('GET', '/queries.json', null, 'jsmith');
        self::assertSame([21, 1, 8, 2, 3, 4], self::ids($data['queries']), 'public query of the private project visible to its members');
        $data = $this->json('GET', '/queries.json', null, null);
        self::assertSame([1, 8, 2, 3, 4], self::ids($data['queries']));
        // Query.visible: administrators do not see the private queries of other users
        $data = $this->json('GET', '/queries.json', null, 'admin');
        self::assertSame([21, 1, 8, 2, 3, 4], self::ids($data['queries']));
    }

    public function testRoleVisibility(): void
    {
        // visibility 1 = roles: only members with the Manager role
        $this->conn()->executeStatement("INSERT INTO queries (id, project_id, name, filters, user_id, column_names, sort_criteria, group_by, type, visibility, options) VALUES (22, 1, 'Managers only', '{}', 5, NULL, NULL, NULL, 'IssueQuery', 1, NULL)");
        $this->conn()->executeStatement('INSERT INTO queries_roles (query_id, role_id) VALUES (22, 3)');
        self::assertContains(22, self::ids($this->json('GET', '/queries.json', null, 'jsmith')['queries']));
        self::assertNotContains(22, self::ids($this->json('GET', '/queries.json', null, 'rhill')['queries']));
        self::assertNotContains(22, self::ids($this->json('GET', '/queries.json', null, null)['queries']));
    }

    public function testPaging(): void
    {
        $data = $this->json('GET', '/queries.json?limit=2&offset=1');
        self::assertSame([8, 2], self::ids($data['queries']));
        $this->assertPaging($data, 5, 1, 2);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Port of api_test/issue_relations_test.rb.
 * Seeded relations: 1 = 13 blocks 19, 2 = 15 precedes 16 (delay 1), 3 = 13 relates 17 (17 is private).
 * issue_relations#index/create/destroy need manage_issue_relations (Redmine permission map).
 */
final class IssueRelationsApiTest extends ApiTestCase
{
    public function testIndexXml(): void
    {
        $xml = $this->xml('GET', '/issues/13/relations.xml');
        $this->assertXmlResponse();
        self::assertSame('array', (string) $xml['type']);
        self::assertSame(['3', '1'], array_map(static fn ($r) => (string) $r->id, iterator_to_array($xml->relation, false)));
        self::assertSame('17', (string) $xml->relation[0]->issue_to_id);
        self::assertSame('relates', (string) $xml->relation[0]->relation_type);
        self::assertSame('', (string) $xml->relation[0]->delay);
    }

    public function testIndexJson(): void
    {
        $data = $this->json('GET', '/issues/15/relations.json');
        $this->assertJsonResponse();
        self::assertSame([['id' => 2, 'issue_id' => 15, 'issue_to_id' => 16, 'relation_type' => 'precedes', 'delay' => 1]], $data['relations']);
        $data = $this->json('GET', '/issues/16/relations.json');
        self::assertSame([2], self::ids($data['relations']), 'relations from both sides are listed');
        $data = $this->json('GET', '/issues/18/relations.json');
        self::assertSame([], $data['relations']);
    }

    public function testIndexDoesNotReturnRelationsToInvisibleIssues(): void
    {
        // dlopper (Developer, manage_issue_relations) cannot see private issue 17
        $data = $this->json('GET', '/issues/13/relations.json', null, 'dlopper');
        $this->assertStatus(200);
        self::assertSame([1], self::ids($data['relations']));
        $data = $this->json('GET', '/issues/13/relations.json', null, 'jsmith');
        self::assertSame([3, 1], self::ids($data['relations']));
    }

    public function testIndexPermissions(): void
    {
        $this->request('GET', '/issues/13/relations.json', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/issues/13/relations.json', null, 'rhill');
        $this->assertStatus(403);
        $this->request('GET', '/issues/18/relations.json', null, 'anna');
        $this->assertStatus(403);
        $this->request('GET', '/issues/999/relations.json');
        $this->assertStatus(404);
    }

    public function testCreateXml(): void
    {
        $xml = $this->xml('POST', '/issues/14/relations.xml', '<relation><issue_to_id>19</issue_to_id><relation_type>relates</relation_type></relation>');
        $this->assertXmlResponse(201);
        $id = (int) $xml->id;
        self::assertSame(['issue_from_id' => 14, 'issue_to_id' => 19, 'relation_type' => 'relates'], array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $this->db('SELECT issue_from_id, issue_to_id, relation_type FROM issue_relations WHERE id = ?', [$id])[0]));
        self::assertSame('14', (string) $xml->issue_id);
    }

    public function testCreateJsonWithNumericIssueToId(): void
    {
        $data = $this->json('POST', '/issues/14/relations.json', ['relation' => ['issue_to_id' => 19, 'relation_type' => 'duplicates']]);
        $this->assertJsonResponse(201);
        self::assertSame(['issue_id' => 14, 'issue_to_id' => 19, 'relation_type' => 'duplicates', 'delay' => null], array_diff_key($data['relation'], ['id' => 0]));
        self::assertStringEndsWith('/relations/'.$data['relation']['id'], (string) $this->response()->headers->get('Location'));
        // journalized on both issues
        self::assertSame(1, $this->rows('journal_details', "property = 'relation' AND prop_key = 'duplicates' AND value = '19'"));
        self::assertSame(1, $this->rows('journal_details', "property = 'relation' AND prop_key = 'duplicated' AND value = '14'"));
    }

    public function testCreateWithFormParamsAndDelay(): void
    {
        $this->request('POST', '/issues/19/relations.xml', ['relation' => ['issue_to_id' => '14', 'relation_type' => 'precedes', 'delay' => '3']], 'jsmith', [], true);
        $this->assertStatus(201);
        self::assertSame(3, (int) $this->dbOne("SELECT delay FROM issue_relations WHERE issue_from_id = 19 AND issue_to_id = 14"));
    }

    public function testCreateFailures(): void
    {
        $xml = $this->xml('POST', '/issues/14/relations.xml', '<relation><issue_to_id>19</issue_to_id><relation_type>foo</relation_type></relation>');
        $this->assertStatus(422);
        self::assertStringContainsString('Relation type is not included in the list', (string) $xml->error);

        $data = $this->json('POST', '/issues/13/relations.json', ['relation' => ['issue_to_id' => 19, 'relation_type' => 'relates']]);
        $this->assertErrors($data, 'Related issue has already been taken');
        $data = $this->json('POST', '/issues/13/relations.json', ['relation' => ['issue_to_id' => 13, 'relation_type' => 'relates']]);
        $this->assertErrors($data, 'Related issue is invalid');
        $data = $this->json('POST', '/issues/13/relations.json', ['relation' => ['issue_to_id' => 999, 'relation_type' => 'relates']]);
        $this->assertStatus(422);
        $data = $this->json('POST', '/issues/14/relations.json', ['relation' => ['issue_to_id' => 15, 'relation_type' => 'relates']]);
        $this->assertErrors($data, 'An issue cannot be linked to one of its subtasks');
        $data = $this->json('POST', '/issues/16/relations.json', ['relation' => ['issue_to_id' => 15, 'relation_type' => 'precedes']]);
        $this->assertErrors($data, 'This relation would create a circular dependency');
        self::assertSame(3, $this->rows('issue_relations'));
    }

    public function testCreatePermissions(): void
    {
        $this->json('POST', '/issues/14/relations.json', ['relation' => ['issue_to_id' => 19, 'relation_type' => 'relates']], 'rhill');
        $this->assertStatus(403);
        $this->json('POST', '/issues/14/relations.json', ['relation' => ['issue_to_id' => 19, 'relation_type' => 'relates']], null);
        $this->assertStatus(401);
        $this->json('POST', '/issues/18/relations.json', ['relation' => ['issue_to_id' => 19, 'relation_type' => 'relates']], 'anna');
        $this->assertStatus(403);
        self::assertSame(3, $this->rows('issue_relations'));
    }

    public function testShow(): void
    {
        $xml = $this->xml('GET', '/relations/2.xml');
        $this->assertXmlResponse();
        self::assertSame('relation', $xml->getName());
        self::assertSame('2', (string) $xml->id);
        self::assertSame('1', (string) $xml->delay);
        $data = $this->json('GET', '/relations/2.json', null, null);
        self::assertSame(['relation' => ['id' => 2, 'issue_id' => 15, 'issue_to_id' => 16, 'relation_type' => 'precedes', 'delay' => 1]], $data);
    }

    public function testShowOfRelationToInvisibleIssue(): void
    {
        $this->request('GET', '/relations/3.json', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/relations/3.json', null, 'anna');
        $this->assertStatus(403);
        $this->request('GET', '/relations/3.json', null, 'jsmith');
        $this->assertStatus(200);
        $this->request('GET', '/relations/999.json');
        $this->assertStatus(404);
    }

    public function testDestroy(): void
    {
        $this->request('DELETE', '/relations/2.xml');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(0, $this->rows('issue_relations', 'id = 2'));
        $this->request('DELETE', '/relations/2.json');
        $this->assertStatus(404);
    }

    public function testDestroyPermissions(): void
    {
        $this->request('DELETE', '/relations/2.json', null, 'rhill');
        $this->assertStatus(403);
        $this->request('DELETE', '/relations/3.json', null, 'dlopper');
        $this->assertStatus(403);
        $this->request('DELETE', '/relations/2.json', null, null);
        $this->assertStatus(401);
        self::assertSame(3, $this->rows('issue_relations'));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Port of api_test/journals_test.rb (PUT /journals/:id) plus journal visibility in issue payloads.
 * Seeded journals: 13/14 (child_id details of issue 14), 15 (private notes by dlopper on 13), 16 (notes on 19).
 */
final class JournalsApiTest extends ApiTestCase
{
    public function testUpdateNotesXml(): void
    {
        $this->request('PUT', '/journals/16.xml', '<journal><notes>changed notes</notes></journal>', 'admin');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame('changed notes', $this->dbOne('SELECT notes FROM journals WHERE id = 16'));
        self::assertSame(1, (int) $this->dbOne('SELECT updated_by_id FROM journals WHERE id = 16'));
    }

    public function testUpdateNotesJson(): void
    {
        $this->json('PUT', '/journals/16.json', ['journal' => ['notes' => 'changed notes']], 'admin');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame('changed notes', $this->dbOne('SELECT notes FROM journals WHERE id = 16'));
    }

    public function testUpdateWithFormParams(): void
    {
        $this->request('PUT', '/journals/16.json', ['journal' => ['notes' => 'form notes']], 'jsmith', [], true);
        $this->assertStatus(204);
        self::assertSame('form notes', $this->dbOne('SELECT notes FROM journals WHERE id = 16'));
    }

    public function testEmptyNotesWithoutDetailsDoNotDestroyTheJournal(): void
    {
        $this->conn()->executeStatement("INSERT INTO journals (id, journalized_type, journalized_id, user_id, notes, created_on, private_notes) VALUES (50, 'Issue', 14, 5, 'Only notes', datetime('now'), 0)");
        $before = $this->rows('journals');
        $this->request('PUT', '/journals/50.xml', '<journal><notes></notes></journal>', 'admin');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame($before, $this->rows('journals'));
        self::assertSame('', (string) $this->dbOne('SELECT notes FROM journals WHERE id = 50'));

        $this->conn()->executeStatement("UPDATE journals SET notes = 'again' WHERE id = 50");
        $this->json('PUT', '/journals/50.json', ['journal' => ['notes' => '']], 'admin');
        $this->assertStatus(204);
        self::assertSame($before, $this->rows('journals'));
    }

    public function testPrivateNotesFlag(): void
    {
        // jsmith (Manager) has set_notes_private
        $this->json('PUT', '/journals/16.json', ['journal' => ['notes' => 'now private', 'private_notes' => '1']]);
        $this->assertStatus(204);
        self::assertSame(1, (int) $this->dbOne('SELECT private_notes FROM journals WHERE id = 16'));
        $data = $this->json('GET', '/issues/19.json?include=journals', null, null);
        self::assertSame([], array_values(array_filter($data['issue']['journals'], static fn ($j) => 'now private' === $j['notes'])));
    }

    public function testPermissions(): void
    {
        // rhill (Reporter) has no edit_issue_notes
        $this->json('PUT', '/journals/16.json', ['journal' => ['notes' => 'x']], 'rhill');
        $this->assertStatus(403);
        // anonymous
        $this->json('PUT', '/journals/16.json', ['journal' => ['notes' => 'x']], null);
        $this->assertStatus(401);
        // private journal 15 is not visible to rhill
        $this->json('PUT', '/journals/15.json', ['journal' => ['notes' => 'x']], 'rhill');
        self::assertContains($this->response()->getStatusCode(), [403, 404]);
        // dlopper (Developer) cannot edit notes of others (no edit_issue_notes / edit_own_issue_notes)
        $this->json('PUT', '/journals/16.json', ['journal' => ['notes' => 'x']], 'dlopper');
        $this->assertStatus(403);
        // unknown journal
        $this->json('PUT', '/journals/999.json', ['journal' => ['notes' => 'x']], 'admin');
        $this->assertStatus(404);
        self::assertSame('Resolved by resetting password', $this->dbOne('SELECT notes FROM journals WHERE id = 16'));
    }

    public function testUserCanEditOwnNotesWithEditOwnIssueNotes(): void
    {
        $perms = json_decode((string) $this->dbOne('SELECT permissions FROM roles WHERE id = 4'), true);
        $perms[] = 'edit_own_issue_notes';
        $this->conn()->executeStatement('UPDATE roles SET permissions = ? WHERE id = 4', [json_encode($perms)]);
        $this->json('PUT', '/journals/15.json', ['journal' => ['notes' => 'edited by author']], 'dlopper');
        $this->assertStatus(204);
        self::assertSame('edited by author', $this->dbOne('SELECT notes FROM journals WHERE id = 15'));
        $this->json('PUT', '/journals/16.json', ['journal' => ['notes' => 'x']], 'dlopper');
        $this->assertStatus(403);
    }

    public function testJournalsInIssuePayloadJsonAndXml(): void
    {
        $data = $this->json('GET', '/issues/14.json?include=journals');
        $journals = $data['issue']['journals'];
        self::assertSame([13, 14], self::ids($journals));
        self::assertSame(['property' => 'attr', 'name' => 'child_id', 'old_value' => null, 'new_value' => '15'], $journals[0]['details'][0]);
        self::assertSame('', $journals[0]['notes']);
        self::assertFalse($journals[0]['private_notes']);
        // updated_by is only rendered for edited journals (issues/show.api.rsb)
        self::assertSame(['id', 'user', 'notes', 'created_on', 'updated_on', 'private_notes', 'details'], array_keys($journals[0]));

        $xml = $this->xml('GET', '/issues/14.xml?include=journals');
        $j = $xml->journals->journal[1];
        self::assertSame('14', (string) $j['id']);
        self::assertSame('child_id', (string) $j->details->detail['name']);
        self::assertSame('attr', (string) $j->details->detail['property']);
        self::assertSame('16', (string) $j->details->detail->new_value);
    }

    public function testUpdatedByAfterEdit(): void
    {
        $this->json('PUT', '/journals/16.json', ['journal' => ['notes' => 'edited']], 'admin');
        $data = $this->json('GET', '/issues/19.json?include=journals');
        $j = $data['issue']['journals'][0];
        self::assertSame('edited', $j['notes']);
        self::assertSame(['id' => 1, 'name' => 'Redmine Admin'], $j['updated_by']);
    }
}

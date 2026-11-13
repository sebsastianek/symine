<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Port of api_test/time_entries_test.rb.
 * Seeded entries: 1 = 2.5h by dlopper on issue 13 (2026-11-06, Investigation), 2 = 1h by jsmith on ecommerce (2026-11-07, Meeting).
 * Activities: 8 Design, 9 Development.
 */
final class TimeEntriesApiTest extends ApiTestCase
{
    public function testIndexXml(): void
    {
        $xml = $this->xml('GET', '/time_entries.xml');
        $this->assertXmlResponse();
        self::assertSame('array', (string) $xml['type']);
        self::assertSame('2', (string) $xml['total_count']);
        self::assertSame(['2', '1'], array_map(static fn ($t) => (string) $t->id, iterator_to_array($xml->time_entry, false)));
        self::assertSame('2.5', (string) $xml->time_entry[1]->hours);
        self::assertSame('1.0', (string) $xml->time_entry[0]->hours);
        self::assertSame('13', (string) $xml->time_entry[1]->issue['id']);
        self::assertSame('Design', (string) $xml->time_entry[1]->activity['name']);
    }

    public function testIndexJsonStructure(): void
    {
        $data = $this->json('GET', '/time_entries.json');
        $this->assertJsonResponse();
        $this->assertPaging($data, 2, 0, 25);
        $e = $data['time_entries'][1];
        self::assertSame(['id', 'project', 'issue', 'user', 'activity', 'hours', 'comments', 'spent_on', 'created_on', 'updated_on'], array_keys($e));
        self::assertSame(['id' => 1, 'name' => 'eCommerce'], $e['project']);
        self::assertSame(['id' => 13], $e['issue']);
        self::assertSame(['id' => 6, 'name' => 'Dave Lopper'], $e['user']);
        self::assertSame(['id' => 8, 'name' => 'Design'], $e['activity']);
        self::assertSame(2.5, $e['hours']);
        self::assertSame('Investigation', $e['comments']);
        self::assertSame('2026-11-06', $e['spent_on']);
        self::assertArrayNotHasKey('issue', $data['time_entries'][0]);
    }

    public function testIndexPagingAndLimit(): void
    {
        $xml = $this->xml('GET', '/time_entries.xml?limit=1');
        self::assertCount(1, $xml->time_entry);
        self::assertSame('1', (string) $xml['limit']);
        $data = $this->json('GET', '/time_entries.json?limit=1&offset=1');
        self::assertSame([1], self::ids($data['time_entries']));
        $this->assertPaging($data, 2, 1, 1);
        $data = $this->json('GET', '/time_entries.json?limit=1&page=2');
        self::assertSame([1], self::ids($data['time_entries']));
        $data = $this->json('GET', '/time_entries.json?limit=500');
        self::assertSame(100, $data['limit']);
    }

    public function testIndexFilters(): void
    {
        self::assertSame([2], self::ids($this->json('GET', '/time_entries.json?user_id=me')['time_entries']));
        self::assertSame([1], self::ids($this->json('GET', '/time_entries.json?user_id=6')['time_entries']));
        self::assertSame([1], self::ids($this->json('GET', '/time_entries.json?issue_id=13')['time_entries']));
        self::assertSame([2], self::ids($this->json('GET', '/time_entries.json?from=2026-11-07&to=2026-11-07')['time_entries']));
        self::assertSame([2, 1], self::ids($this->json('GET', '/projects/ecommerce/time_entries.json')['time_entries']));
        self::assertSame([], $this->json('GET', '/projects/internal/time_entries.json')['time_entries']);
        self::assertSame([1], self::ids($this->json('GET', '/time_entries.json?activity_id=8&spent_on=2026-11-06')['time_entries']));
    }

    public function testIndexVisibility(): void
    {
        $data = $this->json('GET', '/time_entries.json', null, null);
        $this->assertStatus(200);
        self::assertSame([2, 1], self::ids($data['time_entries']), 'anonymous has view_time_entries on public projects');
        $this->conn()->executeStatement("UPDATE roles SET time_entries_visibility = 'own' WHERE id = 1");
        $data = $this->json('GET', '/time_entries.json', null, 'anna');
        self::assertSame([], $data['time_entries']);
        $this->request('GET', '/projects/internal/time_entries.json', null, 'anna');
        $this->assertStatus(403);
        $this->request('GET', '/projects/internal/time_entries.json', null, null);
        $this->assertStatus(401);
    }

    public function testShow(): void
    {
        $xml = $this->xml('GET', '/time_entries/1.xml');
        $this->assertXmlResponse();
        self::assertSame('1', (string) $xml->id);
        self::assertSame('2.5', (string) $xml->hours);
        self::assertSame('Dave Lopper', (string) $xml->user['name']);
        $data = $this->json('GET', '/time_entries/1.json');
        self::assertSame(['id' => 1, 'project' => ['id' => 1, 'name' => 'eCommerce'], 'issue' => ['id' => 13], 'user' => ['id' => 6, 'name' => 'Dave Lopper'], 'activity' => ['id' => 8, 'name' => 'Design'], 'hours' => 2.5, 'comments' => 'Investigation', 'spent_on' => '2026-11-06'], array_diff_key($data['time_entry'], ['created_on' => 1, 'updated_on' => 1]));
    }

    public function testShowOnClosedProject(): void
    {
        $this->conn()->executeStatement('UPDATE projects SET status = 5 WHERE id = 1');
        $xml = $this->xml('GET', '/time_entries/2.xml');
        $this->assertStatus(200);
        self::assertSame('2', (string) $xml->id);
    }

    public function testShowInvalidIdAndNonVisible(): void
    {
        $this->request('GET', '/time_entries/999.xml');
        $this->assertStatus(404);
        $this->conn()->executeStatement("UPDATE roles SET time_entries_visibility = 'own' WHERE id = 1");
        $this->request('GET', '/time_entries/1.xml', null, 'anna');
        $this->assertStatus(403);
        $this->conn()->executeStatement("UPDATE time_entries SET project_id = 2, issue_id = NULL WHERE id = 2");
        $this->request('GET', '/time_entries/2.json', null, 'rhill');
        $this->assertStatus(403);
        $this->request('GET', '/time_entries/2.json', null, null);
        $this->assertStatus(401);
    }

    public function testCreateWithIssueIdXml(): void
    {
        $xml = $this->xml('POST', '/time_entries.xml', '<time_entry><issue_id>14</issue_id><spent_on>2010-12-02</spent_on><hours>3.5</hours><activity_id>9</activity_id></time_entry>');
        $this->assertXmlResponse(201);
        $id = (int) $xml->id;
        $row = $this->db('SELECT project_id, issue_id, user_id, author_id, hours, activity_id, spent_on FROM time_entries WHERE id = ?', [$id])[0];
        self::assertSame([1, 14, 5, 5, 3.5, 9, '2010-12-02'], [(int) $row['project_id'], (int) $row['issue_id'], (int) $row['user_id'], (int) $row['author_id'], (float) $row['hours'], (int) $row['activity_id'], substr((string) $row['spent_on'], 0, 10)]);
        self::assertSame('14', (string) $xml->issue['id']);
    }

    public function testCreateWithProjectIdJson(): void
    {
        $data = $this->json('POST', '/time_entries.json', ['time_entry' => ['project_id' => 1, 'spent_on' => '2010-12-02', 'hours' => '1:30', 'activity_id' => 8, 'comments' => 'API']]);
        $this->assertJsonResponse(201);
        self::assertSame(1.5, $data['time_entry']['hours']);
        self::assertSame(['id' => 1, 'name' => 'eCommerce'], $data['time_entry']['project']);
        self::assertSame('API', $data['time_entry']['comments']);
        self::assertArrayNotHasKey('issue', $data['time_entry']);
    }

    public function testCreateOnNestedRoutes(): void
    {
        $this->json('POST', '/issues/13/time_entries.json', ['time_entry' => ['hours' => 1, 'activity_id' => 8, 'spent_on' => '2026-11-01']]);
        $this->assertStatus(201);
        $this->json('POST', '/projects/checkout/time_entries.json', ['time_entry' => ['hours' => 1, 'activity_id' => 8, 'spent_on' => '2026-11-01']]);
        $this->assertStatus(201);
        self::assertSame(1, $this->rows('time_entries', 'project_id = 3'));
    }

    public function testCreateValidationErrors(): void
    {
        $xml = $this->xml('POST', '/time_entries.xml', '<time_entry><project_id>1</project_id><spent_on>2010-12-02</spent_on><activity_id>8</activity_id></time_entry>');
        $this->assertXmlResponse(422);
        self::assertSame('Hours cannot be blank', (string) $xml->error);

        $data = $this->json('POST', '/time_entries.json', ['time_entry' => ['project_id' => 1, 'hours' => 1, 'activity_id' => 999]]);
        $this->assertErrors($data, 'Activity is not included in the list');
        $data = $this->json('POST', '/time_entries.json', ['time_entry' => ['project_id' => 1, 'hours' => 'abc', 'activity_id' => 8]]);
        $this->assertErrors($data, 'Hours is invalid');
        $data = $this->json('POST', '/time_entries.json', ['time_entry' => ['project_id' => 1, 'hours' => 1, 'activity_id' => 8, 'spent_on' => 'nope']]);
        $this->assertErrors($data, 'Date is not a valid date');
        $data = $this->json('POST', '/time_entries.json', ['time_entry' => ['project_id' => 1, 'hours' => 1, 'activity_id' => 8, 'comments' => str_repeat('a', 1025)]]);
        $this->assertErrors($data, 'Comment is too long (maximum is 1024 characters)');
        self::assertSame(2, $this->rows('time_entries'));
    }

    public function testCreateWithoutProjectOrIssueIs422Or404(): void
    {
        $this->json('POST', '/time_entries.json', ['time_entry' => ['hours' => 1, 'activity_id' => 8]]);
        self::assertContains($this->response()->getStatusCode(), [403, 422]);
        $this->json('POST', '/time_entries.json', ['time_entry' => ['issue_id' => 999, 'hours' => 1, 'activity_id' => 8]]);
        self::assertContains($this->response()->getStatusCode(), [404, 422]);
        self::assertSame(2, $this->rows('time_entries'));
    }

    public function testCreateForOtherUser(): void
    {
        // Manager has log_time_for_other_users in the seeded roles
        $data = $this->json('POST', '/time_entries.json', ['time_entry' => ['project_id' => 1, 'spent_on' => '2010-12-02', 'user_id' => 7, 'hours' => 3.5, 'activity_id' => 8]]);
        $this->assertStatus(201);
        self::assertSame(['id' => 7, 'name' => 'Robert Hill'], $data['time_entry']['user']);
        $row = $this->db('SELECT user_id, author_id FROM time_entries WHERE id = ?', [$data['time_entry']['id']])[0];
        self::assertSame([7, 5], [(int) $row['user_id'], (int) $row['author_id']]);

        $xml = $this->xml('POST', '/time_entries.xml', '<time_entry><issue_id>13</issue_id><spent_on>2010-12-02</spent_on><user_id>6</user_id><hours>3.5</hours><activity_id>8</activity_id></time_entry>');
        $this->assertStatus(201);
        self::assertSame('6', (string) $xml->user['id']);
    }

    public function testCreateForOtherUserWithoutPermission(): void
    {
        // dlopper (Developer) cannot log time for other users
        $data = $this->json('POST', '/time_entries.json', ['time_entry' => ['project_id' => 1, 'user_id' => 7, 'hours' => 1, 'activity_id' => 8]], 'dlopper');
        $this->assertErrors($data, 'User is invalid');
        // a user that is not allowed to log time on the project
        $data = $this->json('POST', '/time_entries.json', ['time_entry' => ['project_id' => 1, 'user_id' => 9, 'hours' => 1, 'activity_id' => 8]]);
        $this->assertErrors($data, 'User is invalid');
    }

    public function testCreatePermissions(): void
    {
        $this->json('POST', '/time_entries.json', ['time_entry' => ['project_id' => 1, 'hours' => 1, 'activity_id' => 8]], null);
        $this->assertStatus(401);
        // anna (Non member role) has no log_time
        $this->json('POST', '/time_entries.json', ['time_entry' => ['project_id' => 1, 'hours' => 1, 'activity_id' => 8]], 'anna');
        $this->assertStatus(403);
        $this->json('POST', '/time_entries.json', ['time_entry' => ['project_id' => 2, 'hours' => 1, 'activity_id' => 8]], 'rhill');
        $this->assertStatus(403);
        self::assertSame(2, $this->rows('time_entries'));
    }

    public function testUpdate(): void
    {
        $this->request('PUT', '/time_entries/2.xml', '<time_entry><comments>API Update</comments></time_entry>');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame('API Update', $this->dbOne('SELECT comments FROM time_entries WHERE id = 2'));
        $this->json('PUT', '/time_entries/2.json', ['time_entry' => ['hours' => 4.25, 'issue_id' => 14]]);
        $this->assertStatus(204);
        self::assertSame(4.25, (float) $this->dbOne('SELECT hours FROM time_entries WHERE id = 2'));
        self::assertSame(14, (int) $this->dbOne('SELECT issue_id FROM time_entries WHERE id = 2'));
    }

    public function testUpdateValidationErrors(): void
    {
        $xml = $this->xml('PUT', '/time_entries/2.xml', '<time_entry><hours></hours><comments>API Update</comments></time_entry>');
        $this->assertXmlResponse(422);
        self::assertSame('Hours cannot be blank', (string) $xml->error);
        $data = $this->json('PUT', '/time_entries/2.json', ['time_entry' => ['hours' => -1]]);
        $this->assertErrors($data, 'Hours is invalid');
        self::assertSame('Meeting', $this->dbOne('SELECT comments FROM time_entries WHERE id = 2'));
    }

    public function testUpdateWithoutPermissions(): void
    {
        // dlopper (Developer) has no edit_time_entries and entry 2 belongs to jsmith
        $this->request('PUT', '/time_entries/2.xml', '<time_entry><hours>2.3</hours><comments>API Update</comments></time_entry>', 'dlopper');
        $this->assertStatus(403);
        $this->json('PUT', '/time_entries/2.json', ['time_entry' => ['hours' => 2]], null);
        $this->assertStatus(401);
        $this->json('PUT', '/time_entries/999.json', ['time_entry' => ['hours' => 2]]);
        $this->assertStatus(404);
        self::assertSame(1.0, (float) $this->dbOne('SELECT hours FROM time_entries WHERE id = 2'));
    }

    public function testDestroy(): void
    {
        $this->request('DELETE', '/time_entries/2.xml');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(0, $this->rows('time_entries', 'id = 2'));
        $this->request('DELETE', '/time_entries/2.json');
        $this->assertStatus(404);
    }

    public function testDestroyPermissions(): void
    {
        $this->request('DELETE', '/time_entries/2.json', null, 'dlopper');
        $this->assertStatus(403);
        $this->request('DELETE', '/time_entries/1.json', null, 'rhill');
        $this->assertStatus(403);
        $this->request('DELETE', '/time_entries/1.json', null, null);
        $this->assertStatus(401);
        self::assertSame(2, $this->rows('time_entries'));
    }

    public function testOnlyVisibleCustomFieldsAreReturned(): void
    {
        $conn = $this->conn();
        $conn->executeStatement("INSERT INTO custom_fields (id, type, name, field_format, visible, is_required, is_for_all, is_filter, position, searchable, editable, multiple, description) VALUES (20, 'TimeEntryCustomField', 'Visible field', 'string', 0, 0, 0, 0, 1, 0, 1, 0, ''), (21, 'TimeEntryCustomField', 'Non visible field', 'string', 0, 0, 0, 0, 2, 0, 1, 0, '')");
        $conn->executeStatement('INSERT INTO custom_fields_roles (custom_field_id, role_id) VALUES (20, 3), (21, 4)');
        $conn->executeStatement("INSERT INTO custom_values (customized_type, customized_id, custom_field_id, value) VALUES ('TimeEntry', 2, 20, 'value1'), ('TimeEntry', 2, 21, 'value2')");
        $xml = $this->xml('GET', '/time_entries/2.xml');
        $this->assertStatus(200);
        $cf = $xml->xpath('//custom_field[@id="20"]');
        self::assertCount(1, $cf);
        self::assertSame('Visible field', (string) $cf[0]['name']);
        self::assertSame('value1', (string) $cf[0]->value);
        self::assertCount(0, $xml->xpath('//custom_field[@id="21"]'));
        $data = $this->json('GET', '/time_entries/2.json');
        self::assertSame([['id' => 20, 'name' => 'Visible field', 'value' => 'value1']], $data['time_entry']['custom_fields']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Port of api_test/issues_test.rb on the seeded data.
 *
 * Seeded issues: 13 Cart (bug, In Progress, ecommerce, assigned to group 10, version 1, category 1),
 * 14 Wishlist (parent of 15 & 16), 17 Safari (private, checkout, author rhill, assigned jsmith),
 * 18 Internal (private project internal), 19 Login (closed).
 */
final class IssuesApiParityTest extends ApiTestCase
{
    // ---------------------------------------------------------------- index

    public function testIndexContainsMetadata(): void
    {
        $xml = $this->xml('GET', '/issues.xml');
        $this->assertXmlResponse();
        self::assertSame('array', (string) $xml['type']);
        self::assertSame('6', (string) $xml['total_count']);
        self::assertSame('25', (string) $xml['limit']);
        self::assertSame('0', (string) $xml['offset']);
    }

    public function testIndexNometaParamAndHeader(): void
    {
        $xml = $this->xml('GET', '/issues.xml?nometa=1');
        self::assertSame('array', (string) $xml['type']);
        self::assertNull($xml['total_count']);
        self::assertNull($xml['limit']);
        self::assertNull($xml['offset']);
        $data = $this->json('GET', '/issues.json', null, 'jsmith', ['HTTP_X_REDMINE_NOMETA' => '1']);
        self::assertArrayHasKey('issues', $data);
        self::assertArrayNotHasKey('total_count', $data);
        self::assertArrayNotHasKey('offset', $data);
    }

    public function testIndexDefaultsToOpenIssuesVisibleToTheUser(): void
    {
        $data = $this->json('GET', '/issues.json');
        $this->assertJsonResponse();
        self::assertSame([18, 17, 16, 15, 14, 13], self::ids($data['issues']));
        $this->assertPaging($data, 6, 0, 25);

        $data = $this->json('GET', '/issues.json', null, null);
        self::assertSame([16, 15, 14, 13], self::ids($data['issues']));
        $data = $this->json('GET', '/issues.json', null, 'anna');
        self::assertSame([16, 15, 14, 13], self::ids($data['issues']));
        $data = $this->json('GET', '/issues.json', null, 'rhill');
        self::assertSame([17, 16, 15, 14, 13], self::ids($data['issues']), 'rhill sees the private issue he authored');
        $data = $this->json('GET', '/issues.json', null, 'admin');
        self::assertSame([18, 17, 16, 15, 14, 13], self::ids($data['issues']));
    }

    public function testIndexStatusFilters(): void
    {
        $data = $this->json('GET', '/issues.json?status_id=*');
        self::assertSame([19, 18, 17, 16, 15, 14, 13], self::ids($data['issues']));
        $data = $this->json('GET', '/issues.json?status_id=closed');
        self::assertSame([19], self::ids($data['issues']));
        $data = $this->json('GET', '/issues.json?status_id=5');
        self::assertSame([5], array_unique(array_map(static fn ($i) => $i['status']['id'], $data['issues'])));
        self::assertSame([19], self::ids($data['issues']));
        $data = $this->json('GET', '/issues.json?status_id=2');
        self::assertSame([13], self::ids($data['issues']));
    }

    public function testIndexStatusIsClosedFlag(): void
    {
        $xml = $this->xml('GET', '/issues.xml');
        self::assertSame('false', (string) $xml->issue[0]->status['is_closed']);
        self::assertSame('false', (string) $xml->issue[0]->is_private);
        $xml = $this->xml('GET', '/issues.xml?status_id=5');
        self::assertSame('true', (string) $xml->issue[0]->status['is_closed']);
    }

    public function testIndexProjectFilterIncludesSubprojects(): void
    {
        $data = $this->json('GET', '/issues.json?project_id=1');
        self::assertSame([17, 16, 15, 14, 13], self::ids($data['issues']));
        $data = $this->json('GET', '/issues.json?project_id=ecommerce&subproject_id=!*');
        self::assertSame([16, 15, 14, 13], self::ids($data['issues']));
        $data = $this->json('GET', '/projects/ecommerce/issues.json?status_id=*');
        self::assertSame([19, 17, 16, 15, 14, 13], self::ids($data['issues']));
        $data = $this->json('GET', '/projects/checkout/issues.json');
        self::assertSame([17], self::ids($data['issues']));
    }

    public function testIndexOnPrivateOrUnknownProject(): void
    {
        $data = $this->json('GET', '/projects/internal/issues.json');
        self::assertSame([18], self::ids($data['issues']));
        $this->json('GET', '/projects/internal/issues.json', null, 'rhill');
        $this->assertStatus(403);
        $this->request('GET', '/projects/internal/issues.json', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/projects/nope/issues.json');
        $this->assertStatus(404);
    }

    public function testIndexAttributeFilters(): void
    {
        self::assertSame([16, 15, 14], self::ids($this->json('GET', '/issues.json?tracker_id=2')['issues']));
        self::assertSame([17], self::ids($this->json('GET', '/issues.json?assigned_to_id=me')['issues']));
        self::assertSame([13], self::ids($this->json('GET', '/issues.json?assigned_to_id=10')['issues']));
        self::assertSame([17, 14], self::ids($this->json('GET', '/issues.json?author_id=7&status_id=*')['issues']));
        self::assertSame([14], self::ids($this->json('GET', '/issues.json?author_id=7&status_id=*', null, null)['issues']));
        self::assertSame([16, 15], self::ids($this->json('GET', '/issues.json?parent_id=14')['issues']));
        self::assertSame([14, 13], self::ids($this->json('GET', '/issues.json?issue_id=13,14,19')['issues']));
        self::assertSame([13], self::ids($this->json('GET', '/issues.json?fixed_version_id=1')['issues']));
        self::assertSame([13], self::ids($this->json('GET', '/issues.json?priority_id=3')['issues']));
        self::assertSame([16, 15, 14, 13], self::ids($this->json('GET', '/issues.json?watcher_id=me')['issues']));
        self::assertSame([17], self::ids($this->json('GET', '/issues.json?is_private=1')['issues']));
    }

    public function testIndexCustomFieldFilter(): void
    {
        $data = $this->json('GET', '/issues.json?set_filter=1&f[]=cf_1&op[cf_1]==&v[cf_1][]=PostgreSQL');
        self::assertSame([13], self::ids($data['issues']));
        $data = $this->json('GET', '/issues.json?cf_1=PostgreSQL');
        self::assertSame([13], self::ids($data['issues']));
        $xml = $this->xml('GET', '/issues.xml?cf_1=MySQL');
        self::assertSame('0', (string) $xml['total_count']);
        self::assertCount(0, $xml->issue);
    }

    public function testIndexSort(): void
    {
        $data = $this->json('GET', '/issues.json?sort=priority:desc,id');
        self::assertSame([17, 14, 16, 13, 18, 15], self::ids($data['issues']));
        $data = $this->json('GET', '/issues.json?status_id=*&sort=id');
        self::assertSame([13, 14, 15, 16, 17, 18, 19], self::ids($data['issues']));
    }

    public function testIndexPaging(): void
    {
        $xml = $this->xml('GET', '/issues.xml?offset=2&limit=3');
        self::assertSame('6', (string) $xml['total_count']);
        self::assertSame('3', (string) $xml['limit']);
        self::assertSame('2', (string) $xml['offset']);
        self::assertCount(3, $xml->issue);
        self::assertSame('16', (string) $xml->issue[0]->id);

        $data = $this->json('GET', '/issues.json?limit=2&page=2');
        self::assertSame([16, 15], self::ids($data['issues']));
        $this->assertPaging($data, 6, 2, 2);

        $data = $this->json('GET', '/issues.json?limit=150');
        $this->assertPaging($data, 6, 0, 100);

        $data = $this->json('GET', '/issues.json?offset=10');
        self::assertSame([], $data['issues']);
        $this->assertPaging($data, 6, 10, 25);
    }

    public function testIndexIncludeRelationsAndAttachments(): void
    {
        $data = $this->json('GET', '/issues.json?status_id=*&include=relations,attachments&sort=id');
        $byId = array_column($data['issues'], null, 'id');
        self::assertSame([3, 1], self::ids($byId[13]['relations']));
        self::assertSame([], $byId[14]['relations']);
        self::assertSame(['notes.txt'], array_column($byId[13]['attachments'], 'filename'));
        self::assertSame([], $byId[14]['attachments']);

        $xml = $this->xml('GET', '/issues.xml?include=relations&issue_id=15');
        self::assertCount(1, $xml->issue->relations->relation);
        self::assertSame('2', (string) $xml->issue->relations->relation['id']);
        self::assertSame('precedes', (string) $xml->issue->relations->relation['relation_type']);
        self::assertSame('1', (string) $xml->issue->relations->relation['delay']);
    }

    public function testIndexInvalidQueryIs422(): void
    {
        $xml = $this->xml('GET', '/issues.xml?set_filter=1&f[]=start_date&op[start_date]==');
        $this->assertXmlResponse(422);
        self::assertSame('Start date cannot be blank', (string) $xml->error);
        $data = $this->json('GET', '/issues.json?set_filter=1&f[]=start_date&op[start_date]==');
        $this->assertErrors($data, 'Start date cannot be blank');
    }

    public function testIndexSpentHoursAndTimestampFilter(): void
    {
        $data = $this->json('GET', '/issues.json?issue_id=13,14');
        $byId = array_column($data['issues'], null, 'id');
        self::assertSame(2.5, $byId[13]['spent_hours']);
        self::assertSame(2.5, $byId[13]['total_spent_hours']);
        self::assertSame(0.0, $byId[14]['spent_hours']);
        self::assertSame(6.0, $byId[14]['total_estimated_hours']);

        $this->conn()->executeStatement("UPDATE issues SET updated_on = '2014-01-02 10:25:00' WHERE id = 15");
        $this->conn()->executeStatement("UPDATE issues SET updated_on = '2014-01-02 12:13:00' WHERE id = 16");
        $data = $this->json('GET', '/issues.json?set_filter=1&f[]=updated_on&op[updated_on]=%3C%3D&v[updated_on][]=2014-01-02T12:00:00Z');
        self::assertSame([15], self::ids($data['issues']));
        $data = $this->json('GET', '/issues.json?set_filter=1&f[]=updated_on&op[updated_on]=%3E%3C&v[updated_on][]=2014-01-02T08:00:00Z&v[updated_on][]=2014-01-02T23:00:00Z');
        self::assertSame([16, 15], self::ids($data['issues']));
    }

    public function testIndexWithoutViewTimeEntriesHasNoSpentHours(): void
    {
        $perms = json_decode((string) $this->dbOne('SELECT permissions FROM roles WHERE id = 2'), true);
        $this->conn()->executeStatement('UPDATE roles SET permissions = ? WHERE id = 2', [json_encode(array_values(array_diff($perms, ['view_time_entries'])))]);
        $data = $this->json('GET', '/issues.json', null, null);
        self::assertArrayNotHasKey('spent_hours', $data['issues'][0]);
        self::assertArrayNotHasKey('total_spent_hours', $data['issues'][0]);
        $xml = $this->xml('GET', '/issues.xml', null, null);
        self::assertCount(0, $xml->issue[0]->spent_hours);
    }

    // ---------------------------------------------------------------- show

    public function testShowJsonAttributes(): void
    {
        $data = $this->json('GET', '/issues/13.json');
        $this->assertJsonResponse();
        $i = $data['issue'];
        self::assertSame(13, $i['id']);
        self::assertSame(['id' => 1, 'name' => 'eCommerce'], $i['project']);
        self::assertSame(['id' => 1, 'name' => 'Bug'], $i['tracker']);
        self::assertSame(['id' => 3, 'name' => 'High'], $i['priority']);
        self::assertSame(['id' => 5, 'name' => 'John Smith'], $i['author']);
        self::assertSame(['id' => 10, 'name' => 'Developers'], $i['assigned_to']);
        self::assertSame(['id' => 1, 'name' => 'UI'], $i['category']);
        self::assertSame(['id' => 1, 'name' => '1.0'], $i['fixed_version']);
        self::assertSame('2026-11-02', $i['start_date']);
        self::assertSame('2026-11-12', $i['due_date']);
        self::assertSame(30, $i['done_ratio']);
        self::assertFalse($i['is_private']);
        self::assertSame(8.0, $i['estimated_hours']);
        self::assertSame(8.0, $i['total_estimated_hours']);
        self::assertSame(2.5, $i['spent_hours']);
        self::assertSame(2.5, $i['total_spent_hours']);
        self::assertNull($i['closed_on']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $i['created_on']);
        self::assertSame([
            ['id' => 1, 'name' => 'Database', 'value' => 'PostgreSQL'],
            ['id' => 2, 'name' => 'Platforms', 'multiple' => true, 'value' => ['Linux', 'macOS']],
            ['id' => 4, 'name' => 'Searchable', 'value' => '1'],
            ['id' => 7, 'name' => 'Severity', 'value' => null],
        ], $i['custom_fields']);
        self::assertArrayNotHasKey('journals', $i);
    }

    public function testShowXmlAttributes(): void
    {
        $xml = $this->xml('GET', '/issues/13.xml');
        $this->assertXmlResponse();
        self::assertSame('issue', $xml->getName());
        self::assertSame('13', (string) $xml->id);
        self::assertSame('eCommerce', (string) $xml->project['name']);
        self::assertSame('In Progress', (string) $xml->status['name']);
        self::assertSame('false', (string) $xml->is_private);
        self::assertSame('8.0', (string) $xml->estimated_hours);
        self::assertSame('Database', (string) $xml->custom_fields->custom_field[0]['name']);
        self::assertSame('PostgreSQL', (string) $xml->custom_fields->custom_field[0]->value);
        self::assertSame('true', (string) $xml->custom_fields->custom_field[1]['multiple']);
        self::assertSame(['Linux', 'macOS'], array_map('strval', iterator_to_array($xml->custom_fields->custom_field[1]->value->value, false)));
    }

    public function testShowEmptyMultiCustomFieldIsEmptyArray(): void
    {
        $data = $this->json('GET', '/issues/17.json');
        $byId = array_column($data['issue']['custom_fields'], null, 'id');
        self::assertSame([], $byId[2]['value']);
        $xml = $this->xml('GET', '/issues/17.xml');
        $cf = $xml->xpath('//custom_field[@id="2"]')[0];
        self::assertSame('array', (string) $cf->value['type']);
        self::assertCount(0, $cf->value->value);
    }

    public function testShowFloatsWithNoSpentTime(): void
    {
        $this->request('GET', '/issues/14.json');
        self::assertStringContainsString('"spent_hours":0.0', $this->body());
        self::assertStringContainsString('"total_spent_hours":0.0', $this->body());
        self::assertStringContainsString('"total_estimated_hours":6.0', $this->body());
    }

    public function testShowJournalsHidePrivateNotesWithoutPermission(): void
    {
        // journal 15 (private notes, status change) of issue 13
        $data = $this->json('GET', '/issues/13.json?include=journals');
        $j = $data['issue']['journals'];
        self::assertSame([15], self::ids($j));
        self::assertSame('Started working on it', $j[0]['notes']);
        self::assertTrue($j[0]['private_notes']);
        self::assertSame(['id' => 6, 'name' => 'Dave Lopper'], $j[0]['user']);
        self::assertSame([
            ['property' => 'attr', 'name' => 'status_id', 'old_value' => '1', 'new_value' => '2'],
            ['property' => 'attr', 'name' => 'done_ratio', 'old_value' => '0', 'new_value' => '30'],
        ], $j[0]['details']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $j[0]['created_on']);

        // rhill (Reporter) and anonymous cannot view private notes: the journal has details, so it stays without notes
        foreach (['rhill', null] as $who) {
            $data = $this->json('GET', '/issues/13.json?include=journals', null, $who);
            $this->assertStatus(200);
            foreach ($data['issue']['journals'] as $journal) {
                self::assertNotSame('Started working on it', $journal['notes'], 'private notes leaked to '.($who ?? 'anonymous'));
            }
        }
        $xml = $this->xml('GET', '/issues/13.xml?include=journals', null, 'rhill');
        self::assertStringNotContainsString('Started working on it', $xml->asXML());
    }

    public function testShowJournalsXml(): void
    {
        $xml = $this->xml('GET', '/issues/19.xml?include=journals');
        self::assertSame('array', (string) $xml->journals['type']);
        $journal = $xml->journals->journal[0];
        self::assertSame('16', (string) $journal['id']);
        self::assertSame('John Smith', (string) $journal->user['name']);
        self::assertSame('Resolved by resetting password', (string) $journal->notes);
        self::assertSame('false', (string) $journal->private_notes);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $journal->created_on);
        self::assertSame('status_id', (string) $journal->details->detail['name']);
        self::assertSame('5', (string) $journal->details->detail->new_value);
    }

    public function testShowChildren(): void
    {
        $data = $this->json('GET', '/issues/14.json?include=children');
        self::assertSame([
            ['id' => 15, 'tracker' => ['id' => 2, 'name' => 'Feature'], 'subject' => 'Wishlist sharing'],
            ['id' => 16, 'tracker' => ['id' => 2, 'name' => 'Feature'], 'subject' => 'Wishlist emails'],
        ], $data['issue']['children']);
        $xml = $this->xml('GET', '/issues/14.xml?include=children');
        self::assertSame('array', (string) $xml->children['type']);
        self::assertCount(2, $xml->children->issue);
        self::assertSame('15', (string) $xml->children->issue[0]['id']);
        $data = $this->json('GET', '/issues/15.json');
        self::assertSame(['id' => 14], $data['issue']['parent']);
    }

    public function testShowChildrenExcludeInvisibleDescendants(): void
    {
        $this->conn()->executeStatement('UPDATE issues SET is_private = 1 WHERE id = 16');
        $data = $this->json('GET', '/issues/14.json?include=children', null, 'anna');
        self::assertSame([15], self::ids($data['issue']['children']));
        $data = $this->json('GET', '/issues/14.json?include=children', null, 'jsmith');
        self::assertSame([15, 16], self::ids($data['issue']['children']));
    }

    public function testShowAttachmentsRelationsWatchers(): void
    {
        $data = $this->json('GET', '/issues/13.json?include=attachments,relations,watchers');
        $a = $data['issue']['attachments'][0];
        self::assertSame(1, $a['id']);
        self::assertSame('notes.txt', $a['filename']);
        self::assertSame(12, $a['filesize']);
        self::assertSame('text/plain', $a['content_type']);
        self::assertSame('http://localhost/attachments/download/1/notes.txt', $a['content_url']);
        self::assertSame(['id' => 5, 'name' => 'John Smith'], $a['author']);
        self::assertSame([3, 1], self::ids($data['issue']['relations']));
        self::assertSame([['id' => 5, 'name' => 'John Smith'], ['id' => 7, 'name' => 'Robert Hill']], $data['issue']['watchers']);

        // watchers need view_issue_watchers
        $data = $this->json('GET', '/issues/13.json?include=watchers', null, 'rhill');
        self::assertArrayNotHasKey('watchers', $data['issue']);
    }

    public function testShowAllowedStatuses(): void
    {
        $data = $this->json('GET', '/issues/13.json?include=allowed_statuses');
        self::assertSame(['New', 'In Progress', 'Resolved', 'Feedback', 'Closed', 'Rejected'], array_column($data['issue']['allowed_statuses'], 'name'));
        self::assertSame(['id' => 5, 'name' => 'Closed', 'is_closed' => true], $data['issue']['allowed_statuses'][4]);
        $data = $this->json('GET', '/issues/13.json?include=allowed_statuses', null, 'rhill');
        self::assertSame(['In Progress', 'Closed'], array_column($data['issue']['allowed_statuses'], 'name'));
        $xml = $this->xml('GET', '/issues/13.xml?include=allowed_statuses', null, 'dlopper');
        self::assertSame('array', (string) $xml->allowed_statuses['type']);
        self::assertSame(['In Progress', 'Resolved', 'Feedback', 'Closed'], array_map(static fn ($s) => (string) $s['name'], iterator_to_array($xml->allowed_statuses->status, false)));
    }

    public function testShowVisibleSpentHoursOnly(): void
    {
        // Non member role restricted to own time entries: anna only sees her own hours
        $this->conn()->executeStatement("UPDATE roles SET time_entries_visibility = 'own' WHERE id = 1");
        $this->conn()->executeStatement("INSERT INTO time_entries (project_id, issue_id, user_id, author_id, hours, activity_id, spent_on, tyear, tmonth, tweek, created_on, updated_on, comments) VALUES (1, 15, 9, 9, 5.5, 8, '2026-11-07', 2026, 11, 45, datetime('now'), datetime('now'), '')");
        $data = $this->json('GET', '/issues/14.json', null, 'anna');
        self::assertSame(0.0, $data['issue']['spent_hours']);
        self::assertSame(5.5, $data['issue']['total_spent_hours']);
        $data = $this->json('GET', '/issues/13.json', null, 'anna');
        self::assertSame(0.0, $data['issue']['spent_hours']);
        $data = $this->json('GET', '/issues/13.json', null, 'jsmith');
        self::assertSame(2.5, $data['issue']['spent_hours']);
    }

    /** @return iterable<string, array{0: int, 1: ?string, 2: int}> */
    public static function visibilityProvider(): iterable
    {
        // private issue 17 (checkout, author rhill, assigned to jsmith)
        yield '17 anonymous' => [17, null, 401];
        yield '17 anna' => [17, 'anna', 403];
        yield '17 rhill (author)' => [17, 'rhill', 200];
        yield '17 dlopper' => [17, 'dlopper', 403];
        yield '17 jsmith' => [17, 'jsmith', 200];
        yield '17 admin' => [17, 'admin', 200];
        // issue 18 of the private project
        yield '18 anonymous' => [18, null, 401];
        yield '18 anna' => [18, 'anna', 403];
        yield '18 rhill' => [18, 'rhill', 403];
        yield '18 jsmith' => [18, 'jsmith', 200];
        yield '18 admin' => [18, 'admin', 200];
        // public
        yield '13 anonymous' => [13, null, 200];
        yield '13 anna' => [13, 'anna', 200];
        yield 'unknown' => [999, 'jsmith', 404];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('visibilityProvider')]
    public function testShowVisibility(int $id, ?string $as, int $status): void
    {
        $this->request('GET', '/issues/'.$id.'.json', null, $as);
        $this->assertStatus($status);
        $this->request('GET', '/issues/'.$id.'.xml', null, $as);
        $this->assertStatus($status);
    }

    public function testShowIssueOfArchivedProjectIs403(): void
    {
        $this->conn()->executeStatement('UPDATE issues SET project_id = 4 WHERE id = 16');
        $this->request('GET', '/issues/16.json', null, 'jsmith');
        $this->assertStatus(403);
    }

    // ---------------------------------------------------------------- create

    public function testCreateXml(): void
    {
        $xml = $this->xml('POST', '/issues.xml', '<?xml version="1.0" encoding="UTF-8" ?><issue><project_id>1</project_id><tracker_id>2</tracker_id><status_id>3</status_id><category_id>2</category_id><subject>API test</subject></issue>');
        $this->assertXmlResponse(201);
        $id = (int) $xml->id;
        $row = $this->db('SELECT project_id, tracker_id, status_id, category_id, subject, author_id, priority_id FROM issues WHERE id = ?', [$id])[0];
        // status_id 3 is ignored: no workflow transition from "new issue" (old_status_id 0) in the seeded workflows
        self::assertSame(['project_id' => 1, 'tracker_id' => 2, 'status_id' => 1, 'category_id' => 2, 'subject' => 'API test', 'author_id' => 5, 'priority_id' => 2], array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $row));
        self::assertStringEndsWith('/issues/'.$id, (string) $this->response()->headers->get('Location'));
    }

    public function testCreateJson(): void
    {
        $data = $this->json('POST', '/issues.json', ['issue' => ['project_id' => 1, 'tracker_id' => 2, 'status_id' => 3, 'subject' => 'API test', 'priority_id' => 4, 'start_date' => '2026-01-01', 'estimated_hours' => 3.5, 'description' => 'Hello']]);
        $this->assertJsonResponse(201);
        $i = $data['issue'];
        self::assertSame('API test', $i['subject']);
        self::assertSame(['id' => 1, 'name' => 'New', 'is_closed' => false], $i['status'], 'default status (status_id not allowed)');
        self::assertSame(['id' => 4, 'name' => 'Urgent'], $i['priority']);
        self::assertSame('2026-01-01', $i['start_date']);
        self::assertSame(3.5, $i['estimated_hours']);
        self::assertSame('Hello', $i['description']);
        self::assertSame(['id' => 5, 'name' => 'John Smith'], $i['author']);
    }

    public function testCreateAcceptsProjectIdentifierAndFormParams(): void
    {
        $data = $this->json('POST', '/issues.json', ['issue' => ['project_id' => 'checkout', 'subject' => 'By identifier']]);
        $this->assertStatus(201);
        self::assertSame(['id' => 3, 'name' => 'Checkout'], $data['issue']['project']);

        $this->request('POST', '/issues.xml', ['issue' => ['project_id' => 1, 'subject' => 'Form params', 'tracker_id' => 2, 'watcher_user_ids' => [6, 7]]], 'jsmith', [], true);
        $this->assertStatus(201);
        $id = (int) $this->dbOne("SELECT id FROM issues WHERE subject = 'Form params'");
        // the author is an auto watcher (pref auto_watch_on issue_created)
        self::assertSame([5, 6, 7], array_map('intval', array_column($this->db("SELECT user_id FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = ? ORDER BY user_id", [$id]), 'user_id')));
    }

    public function testCreateWithWatchersParentAndCustomFields(): void
    {
        $data = $this->json('POST', '/projects/ecommerce/issues.json', ['issue' => [
            'tracker_id' => 1, 'subject' => 'Child bug', 'parent_issue_id' => 14, 'watcher_user_ids' => [6, 7],
            'custom_fields' => [['id' => 1, 'value' => 'Oracle'], ['id' => 2, 'value' => ['Windows', 'Linux']]],
        ]]);
        $this->assertStatus(201);
        $id = $data['issue']['id'];
        self::assertSame(['id' => 14], $data['issue']['parent']);
        $byId = array_column($data['issue']['custom_fields'], null, 'id');
        self::assertSame('Oracle', $byId[1]['value']);
        self::assertEqualsCanonicalizing(['Windows', 'Linux'], $byId[2]['value']);
        $watchers = $this->json('GET', '/issues/'.$id.'.json?include=watchers')['issue']['watchers'];
        self::assertEqualsCanonicalizing([5, 6, 7], self::ids($watchers));

        // custom_field_values hash
        $data = $this->json('POST', '/issues.json', ['issue' => ['project_id' => '1', 'subject' => 'Multivalued', 'tracker_id' => 1, 'custom_field_values' => ['2' => ['Linux', 'Windows']]]]);
        $this->assertStatus(201);
        $byId = array_column($data['issue']['custom_fields'], null, 'id');
        self::assertEqualsCanonicalizing(['Linux', 'Windows'], $byId[2]['value']);
    }

    public function testCreateWithUploadedFiles(): void
    {
        $this->request('POST', '/uploads.json?filename=test.txt', 'File content', 'jsmith', ['CONTENT_TYPE' => 'application/octet-stream']);
        $this->assertStatus(201);
        $token = json_decode($this->body(), true)['upload']['token'];
        $this->request('POST', '/uploads.xml', 'Second file', 'jsmith', ['CONTENT_TYPE' => 'application/octet-stream']);
        $this->assertStatus(201);
        $token2 = (string) simplexml_load_string($this->body())->token;

        $data = $this->json('POST', '/issues.json', ['issue' => ['project_id' => 1, 'subject' => 'Uploaded files', 'uploads' => [
            ['token' => $token, 'filename' => 'test.txt', 'content_type' => 'text/plain', 'description' => 'First'],
            ['token' => $token2, 'filename' => 'second.txt', 'content_type' => 'text/plain'],
        ]]]);
        $this->assertStatus(201);
        $id = $data['issue']['id'];
        $atts = $this->json('GET', '/issues/'.$id.'.json?include=attachments')['issue']['attachments'];
        self::assertSame(['test.txt', 'second.txt'], array_column($atts, 'filename'));
        self::assertSame('First', $atts[0]['description']);
        self::assertSame(12, $atts[0]['filesize']);
        self::assertSame(5, $atts[0]['author']['id']);

        // a token can only be used once
        $this->json('POST', '/issues.json', ['issue' => ['project_id' => 1, 'subject' => 'Reuse', 'uploads' => [['token' => $token, 'filename' => 'test.txt']]]]);
        self::assertContains($this->response()->getStatusCode(), [201, 422]);
        self::assertSame(2, $this->rows('attachments', "container_type = 'Issue' AND container_id = ?", [$id]));
    }

    public function testCreateValidationErrors(): void
    {
        $data = $this->json('POST', '/issues.json', ['issue' => ['project_id' => 1]]);
        $this->assertErrors($data, 'Subject cannot be blank');
        $xml = $this->xml('POST', '/issues.xml', '<issue><project_id>1</project_id></issue>');
        $this->assertStatus(422);
        self::assertSame('errors', $xml->getName());
        self::assertContains('Subject cannot be blank', array_map('strval', iterator_to_array($xml->error, false)));

        $data = $this->json('POST', '/issues.json', ['issue' => ['project_id' => 1, 'subject' => 'x', 'start_date' => '2026-02-01', 'due_date' => '2026-01-01']]);
        $this->assertErrors($data, 'Due date must be greater than start date');
        $data = $this->json('POST', '/issues.json', ['issue' => ['project_id' => 1, 'subject' => 'x', 'custom_fields' => [['id' => 1, 'value' => 'DB2']]]]);
        $this->assertErrors($data, 'Database is not included in the list');
        $data = $this->json('POST', '/issues.json', ['issue' => ['project_id' => 1, 'subject' => 'x', 'parent_issue_id' => 999]]);
        $this->assertErrors($data, 'Parent task is invalid');
        $data = $this->json('POST', '/issues.json', ['issue' => ['project_id' => 1, 'subject' => str_repeat('a', 256)]]);
        $this->assertErrors($data, 'Subject is too long (maximum is 255 characters)');
        self::assertSame(7, $this->rows('issues'));
    }

    public function testCreateWithInvalidProjectIs422(): void
    {
        foreach ([['project_id' => 999], ['project_id' => 999, 'assigned_to_id' => 1], ['project_id' => 999, 'fixed_version_id' => 1]] as $attrs) {
            $this->json('POST', '/issues.json', ['issue' => $attrs + ['subject' => 'API']]);
            $this->assertStatus(422, json_encode($attrs));
        }
        $this->request('POST', '/projects/nope/issues.json', ['issue' => ['subject' => 'x']]);
        $this->assertStatus(404);
    }

    public function testCreatePermissions(): void
    {
        $this->json('POST', '/issues.json', ['issue' => ['project_id' => 1, 'subject' => 'anon']], null);
        $this->assertStatus(401);
        // anna (non member): Non member role has add_issues on public projects
        $data = $this->json('POST', '/issues.json', ['issue' => ['project_id' => 1, 'subject' => 'by anna']], 'anna');
        $this->assertStatus(201);
        self::assertSame(9, $data['issue']['author']['id']);
        // rhill is not a member of the private project
        $this->json('POST', '/projects/internal/issues.json', ['issue' => ['subject' => 'x']], 'rhill');
        $this->assertStatus(403);
        // locked user
        $this->json('POST', '/issues.json', ['issue' => ['project_id' => 1, 'subject' => 'x']], 'locked');
        $this->assertStatus(401);
    }

    public function testCreateUsesTrackerPrivateByDefault(): void
    {
        $this->conn()->executeStatement('UPDATE trackers SET private_by_default = 1 WHERE id = 1');
        $data = $this->json('POST', '/issues.json', ['issue' => ['project_id' => 1, 'tracker_id' => 1, 'subject' => 'private by default']]);
        $this->assertStatus(201);
        self::assertTrue($data['issue']['is_private']);
    }

    public function testCreateOmittedCustomFieldGetsDefault(): void
    {
        $this->conn()->executeStatement("UPDATE custom_fields SET default_value = 'MySQL' WHERE id = 1");
        $data = $this->json('POST', '/issues.json', ['issue' => ['project_id' => 1, 'tracker_id' => 1, 'subject' => 'API', 'custom_field_values' => new \stdClass()]]);
        $this->assertStatus(201);
        self::assertSame('MySQL', array_column($data['issue']['custom_fields'], null, 'id')[1]['value']);
        $data = $this->json('POST', '/issues.json', ['issue' => ['project_id' => 1, 'tracker_id' => 1, 'subject' => 'API', 'custom_field_values' => ['1' => '']]]);
        $this->assertStatus(201);
        self::assertContains(array_column($data['issue']['custom_fields'], null, 'id')[1]['value'], ['', null]);
    }

    public function testCopyFrom(): void
    {
        // link_copied_issue = ask: no relation without link_copy
        $data = $this->json('POST', '/issues.json?copy_from=13', ['issue' => ['project_id' => 1, 'subject' => 'Not linked']]);
        $this->assertStatus(201);
        self::assertSame(0, $this->rows('issue_relations', "relation_type = 'copied_to' AND issue_to_id = ?", [$data['issue']['id']]));
        $data = $this->json('POST', '/issues.json?copy_from=13&link_copy=1', ['issue' => ['project_id' => 1, 'subject' => 'Copied']]);
        $this->assertStatus(201);
        self::assertSame('Copied', $data['issue']['subject']);
        self::assertSame(['id' => 1, 'name' => 'Bug'], $data['issue']['tracker']);
        self::assertSame('PostgreSQL', array_column($data['issue']['custom_fields'], null, 'id')[1]['value']);
        self::assertSame(1, $this->rows('issue_relations', "relation_type = 'copied_to' AND issue_from_id = 13 AND issue_to_id = ?", [$data['issue']['id']]));
    }

    // ---------------------------------------------------------------- update

    public function testUpdateXml(): void
    {
        $this->request('PUT', '/issues/14.xml', '<issue><subject>API update</subject><notes>A new note</notes></issue>');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame('API update', $this->dbOne('SELECT subject FROM issues WHERE id = 14'));
        $j = $this->db("SELECT id, notes, user_id FROM journals WHERE journalized_id = 14 ORDER BY id DESC LIMIT 1")[0];
        self::assertSame('A new note', $j['notes']);
        self::assertSame(1, $this->rows('journal_details', "journal_id = ? AND prop_key = 'subject' AND old_value = 'Wishlist' AND value = 'API update'", [$j['id']]));
    }

    public function testUpdateJsonWithCustomFields(): void
    {
        $this->json('PUT', '/issues/13.json', ['issue' => ['custom_fields' => [['id' => 1, 'value' => 'Oracle'], ['id' => 2, 'value' => ['Windows']]]]]);
        $this->assertStatus(204);
        $cfs = array_column($this->json('GET', '/issues/13.json')['issue']['custom_fields'], null, 'id');
        self::assertSame('Oracle', $cfs[1]['value']);
        self::assertSame(['Windows'], $cfs[2]['value']);
        // omitted custom fields keep their value
        $this->json('PUT', '/issues/13.json', ['issue' => ['subject' => 'Changed']]);
        $cfs = array_column($this->json('GET', '/issues/13.json')['issue']['custom_fields'], null, 'id');
        self::assertSame('Oracle', $cfs[1]['value']);
    }

    public function testUpdateNotesOnlyAndPrivateNotes(): void
    {
        $this->json('PUT', '/issues/14.json', ['issue' => ['notes' => 'Notes only']]);
        $this->assertStatus(204);
        $this->json('PUT', '/issues/14.json', ['issue' => ['notes' => 'Secret', 'private_notes' => true]]);
        $this->assertStatus(204);
        $j = $this->db('SELECT notes, private_notes FROM journals WHERE journalized_id = 14 ORDER BY id DESC LIMIT 2');
        self::assertSame(['Secret', 1], [$j[0]['notes'], (int) $j[0]['private_notes']]);
        self::assertSame(['Notes only', 0], [$j[1]['notes'], (int) $j[1]['private_notes']]);
        $data = $this->json('GET', '/issues/14.json?include=journals', null, 'rhill');
        self::assertNotContains('Secret', array_column($data['issue']['journals'], 'notes'));
        $data = $this->json('GET', '/issues/14.json?include=journals', null, 'dlopper');
        self::assertContains('Secret', array_column($data['issue']['journals'], 'notes'));
    }

    public function testUpdateWithProjectChange(): void
    {
        $this->json('PUT', '/issues/14.json', ['issue' => ['project_id' => 3, 'subject' => 'Project changed']]);
        $this->assertStatus(204);
        self::assertSame(3, (int) $this->dbOne('SELECT project_id FROM issues WHERE id = 14'));
    }

    public function testUpdateFailures(): void
    {
        $data = $this->json('PUT', '/issues/14.json', ['issue' => ['subject' => '']]);
        $this->assertErrors($data, 'Subject cannot be blank');
        $xml = $this->xml('PUT', '/issues/14.xml', '<issue><subject></subject></issue>');
        $this->assertStatus(422);
        self::assertSame('Subject cannot be blank', (string) $xml->error);
        $data = $this->json('PUT', '/issues/14.json', ['issue' => ['assigned_to_id' => 9]]);
        $this->assertErrors($data, 'Assignee is invalid');
        $data = $this->json('PUT', '/issues/14.json', ['issue' => ['fixed_version_id' => 2]]);
        $this->assertErrors($data, 'Target version is not included in the list');
        $data = $this->json('PUT', '/issues/14.json', ['issue' => ['parent_issue_id' => 15]]);
        $this->assertErrors($data, 'Parent task is invalid');
        self::assertSame('Wishlist', $this->dbOne('SELECT subject FROM issues WHERE id = 14'));
    }

    public function testUpdateStaleLockVersionIs409(): void
    {
        $this->json('PUT', '/issues/14.json', ['issue' => ['subject' => 'stale', 'lock_version' => 99]]);
        self::assertContains($this->response()->getStatusCode(), [409, 422]);
        self::assertSame('Wishlist', $this->dbOne('SELECT subject FROM issues WHERE id = 14'));
    }

    public function testUpdateDisallowedStatusTransitionIsIgnored(): void
    {
        // Reporter (rhill) can only move Cart (In Progress) to In Progress/Closed
        $this->json('PUT', '/issues/13.json', ['issue' => ['status_id' => 3, 'notes' => 'try resolve']], 'rhill');
        $this->assertStatus(204);
        self::assertSame(2, (int) $this->dbOne('SELECT status_id FROM issues WHERE id = 13'));
        // and cannot edit attributes (notes only)
        $this->json('PUT', '/issues/13.json', ['issue' => ['subject' => 'hacked', 'notes' => 'hi']], 'rhill');
        self::assertSame('Cart total is wrong with discounts', $this->dbOne('SELECT subject FROM issues WHERE id = 13'));
        // developer: due date is required by the workflow field permission of the (new) status In Progress
        $data = $this->json('PUT', '/issues/13.json', ['issue' => ['due_date' => '']], 'dlopper');
        $this->assertErrors($data, 'Due date cannot be blank');
        // the rule does not apply once the status is Resolved
        $this->json('PUT', '/issues/13.json', ['issue' => ['status_id' => 3, 'due_date' => '']], 'dlopper');
        $this->assertStatus(204);
        self::assertSame(3, (int) $this->dbOne('SELECT status_id FROM issues WHERE id = 13'));
    }

    public function testUpdatePermissions(): void
    {
        $this->json('PUT', '/issues/14.json', ['issue' => ['subject' => 'x']], null);
        $this->assertStatus(401);
        // anna (non member) has add_issue_notes but no edit_issues: only notes are saved
        $this->json('PUT', '/issues/14.json', ['issue' => ['subject' => 'x', 'notes' => 'from anna']], 'anna');
        $this->assertStatus(204);
        self::assertSame('Wishlist', $this->dbOne('SELECT subject FROM issues WHERE id = 14'));
        self::assertSame(1, $this->rows('journals', "journalized_id = 14 AND notes = 'from anna'"));
        $this->json('PUT', '/issues/18.json', ['issue' => ['subject' => 'x']], 'anna');
        $this->assertStatus(403);
        $this->json('PUT', '/issues/999.json', ['issue' => ['subject' => 'x']]);
        $this->assertStatus(404);
    }

    public function testUpdateWithUploadedFile(): void
    {
        $this->request('POST', '/uploads.json', 'Attachment content', 'jsmith', ['CONTENT_TYPE' => 'application/octet-stream']);
        $token = json_decode($this->body(), true)['upload']['token'];
        $this->json('PUT', '/issues/14.json', ['issue' => ['notes' => 'Attachment added', 'uploads' => [['token' => $token, 'filename' => 'test.txt', 'content_type' => 'text/plain']]]]);
        $this->assertStatus(204);
        $atts = $this->json('GET', '/issues/14.json?include=attachments')['issue']['attachments'];
        self::assertSame(['test.txt'], array_column($atts, 'filename'));
        self::assertSame(18, $atts[0]['filesize']);
        $detail = $this->db("SELECT d.property, d.value FROM journal_details d JOIN journals j ON j.id = d.journal_id WHERE j.journalized_id = 14 AND d.property = 'attachment'");
        self::assertSame('test.txt', $detail[0]['value']);
    }

    // ---------------------------------------------------------------- delete

    public function testDeleteXmlAndJson(): void
    {
        $this->request('DELETE', '/issues/16.xml');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
        self::assertSame(0, $this->rows('issues', 'id = 16'));
        $this->request('DELETE', '/issues/15.json');
        $this->assertStatus(204);
        $this->request('DELETE', '/issues/15.json');
        $this->assertStatus(404);
    }

    public function testDeleteCascadesToTimeEntriesWhenRequested(): void
    {
        $this->request('DELETE', '/issues/13.json');
        $this->assertStatus(204);
        self::assertSame(0, $this->rows('issues', 'id = 13'));
        // time entries of the deleted issue are destroyed by default through the API
        self::assertSame(0, $this->rows('time_entries', 'issue_id = 13'));
    }

    public function testDeletePermissions(): void
    {
        $this->request('DELETE', '/issues/14.json', null, 'dlopper');
        $this->assertStatus(403);
        $this->request('DELETE', '/issues/14.json', null, 'rhill');
        $this->assertStatus(403);
        $this->request('DELETE', '/issues/14.json', null, null);
        $this->assertStatus(401);
        $this->request('DELETE', '/issues/18.json', null, 'anna');
        $this->assertStatus(403);
        self::assertSame(7, $this->rows('issues'));
        $this->request('DELETE', '/issues/14.json', null, 'admin');
        $this->assertStatus(204);
        self::assertSame(0, $this->rows('issues', 'id IN (14, 15, 16)'), 'subtasks are deleted with their parent');
    }

    // ---------------------------------------------------------------- watchers

    public function testAddAndRemoveWatcherXml(): void
    {
        $this->request('POST', '/issues/14/watchers.xml', '<user_id>6</user_id>');
        $this->assertStatus(204);
        self::assertSame(1, $this->rows('watchers', "watchable_type = 'Issue' AND watchable_id = 14 AND user_id = 6"));
        $this->request('DELETE', '/issues/14/watchers/6.xml');
        $this->assertStatus(204);
        self::assertSame(0, $this->rows('watchers', "watchable_type = 'Issue' AND watchable_id = 14 AND user_id = 6"));
    }

    public function testWatchersRequirePermission(): void
    {
        $this->json('POST', '/issues/14/watchers.json', ['user_id' => 6], 'rhill');
        $this->assertStatus(403);
        $this->request('DELETE', '/issues/13/watchers/7.json', null, 'rhill');
        $this->assertStatus(403);
        self::assertSame(1, $this->rows('watchers', "watchable_type = 'Issue' AND watchable_id = 13 AND user_id = 7"));
    }
}

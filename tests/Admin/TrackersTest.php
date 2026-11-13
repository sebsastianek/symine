<?php

declare(strict_types=1);

namespace App\Tests\Admin;

final class TrackersTest extends AdminWebTestCase
{
    use WorkflowAdminTestTrait;

    public function testPagesAccess(): void
    {
        $this->assertAdminOnly(['/trackers', '/trackers/new', '/trackers/new?copy=1', '/trackers/1/edit', '/trackers/fields']);
    }

    public function testCreateWithWorkflowCopy(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/trackers', [
            'tracker' => [
                'name' => 'Task', 'default_status_id' => '2', 'is_in_roadmap' => '0', 'private_by_default' => '1',
                'description' => 'Small things',
                'core_fields' => ['assigned_to_id', 'due_date', ''],
                'custom_field_ids' => ['1', ''],
                'project_ids' => ['1', '3', ''],
            ],
            'copy_workflow_from' => '1',
        ]);
        self::assertTrue($r->isRedirect('/trackers'), (string) $r->getStatusCode());
        $t = $this->db()->fetchAssociative("SELECT * FROM trackers WHERE name = 'Task'");
        self::assertSame(2, (int) $t['default_status_id']);
        self::assertSame(0, (int) $t['is_in_roadmap']);
        self::assertSame(1, (int) $t['private_by_default']);
        self::assertSame(4, (int) $t['position']);
        // all core fields but assigned_to_id (bit 0) and due_date (bit 5) are disabled
        self::assertSame(1023 & ~1 & ~(1 << 5), (int) $t['fields_bits']);
        $id = (int) $t['id'];
        self::assertSame(['1'], array_map('strval', $this->db()->fetchFirstColumn('SELECT custom_field_id FROM custom_fields_trackers WHERE tracker_id = ?', [$id])));
        self::assertSame(['1', '3'], array_map('strval', $this->db()->fetchFirstColumn('SELECT project_id FROM projects_trackers WHERE tracker_id = ? ORDER BY project_id', [$id])));
        self::assertSame($this->transitionRows(1, 4), $this->transitionRows($id, 4));
        self::assertNotEmpty($this->transitionRows($id, 3));
    }

    public function testCreateValidation(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/trackers', ['tracker' => ['name' => 'Bug', 'default_status_id' => '']]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Name has already been taken', (string) $r->getContent());
        self::assertStringContainsString('Default status cannot be blank', (string) $r->getContent());
    }

    public function testUpdate(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/trackers/3', ['tracker' => ['name' => 'Support request', 'default_status_id' => '1', 'core_fields' => [''], 'custom_field_ids' => [''], 'project_ids' => ['2', '']]], 'PATCH');
        self::assertTrue($r->isRedirect('/trackers'));
        $t = $this->db()->fetchAssociative('SELECT * FROM trackers WHERE id = 3');
        self::assertSame('Support request', $t['name']);
        self::assertSame(1023, (int) $t['fields_bits']);
        self::assertSame(['2'], array_map('strval', $this->db()->fetchFirstColumn('SELECT project_id FROM projects_trackers WHERE tracker_id = 3')));
        self::assertSame([], $this->db()->fetchFirstColumn('SELECT custom_field_id FROM custom_fields_trackers WHERE tracker_id = 3'));
        // reorder
        $token = $this->csrfToken();
        $this->client->request('PUT', '/trackers/3', ['tracker' => ['position' => '1']], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_X-CSRF-Token' => $token]);
        $this->assertStatus(200);
        self::assertSame([1 => 2, 2 => 3, 3 => 1], array_map('intval', $this->db()->fetchAllKeyValue('SELECT id, position FROM trackers ORDER BY id')));
    }

    public function testDestroy(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/trackers/1', [], 'DELETE');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('This tracker contains issues and cannot be deleted.', (string) $r->getContent());
        $this->post('/trackers', ['tracker' => ['name' => 'Temp', 'default_status_id' => '1', 'project_ids' => ['1']], 'copy_workflow_from' => '1']);
        $id = (int) $this->db()->fetchOne("SELECT id FROM trackers WHERE name = 'Temp'");
        $r = $this->post('/trackers/'.$id, [], 'DELETE');
        self::assertTrue($r->isRedirect('/trackers'));
        self::assertFalse($this->db()->fetchOne('SELECT id FROM trackers WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM workflows WHERE tracker_id = ?', [$id]));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM projects_trackers WHERE tracker_id = ?', [$id]));
    }

    public function testFieldsSummary(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/trackers/fields');
        self::assertGreaterThan(0, $crawler->filter('table.tracker-summary input.tracker-1.core-field-assigned_to_id')->count());
        $r = $this->post('/trackers/fields', ['trackers' => [
            '1' => ['core_fields' => ['assigned_to_id', ''], 'custom_field_ids' => ['3', '']],
            '2' => ['core_fields' => [''], 'custom_field_ids' => ['']],
        ]]);
        self::assertTrue($r->isRedirect('/trackers/fields'));
        self::assertSame(1022, (int) $this->db()->fetchOne('SELECT fields_bits FROM trackers WHERE id = 1'));
        self::assertSame(1023, (int) $this->db()->fetchOne('SELECT fields_bits FROM trackers WHERE id = 2'));
        self::assertSame(['3'], array_map('strval', $this->db()->fetchFirstColumn('SELECT custom_field_id FROM custom_fields_trackers WHERE tracker_id = 1')));
    }

    public function testApi(): void
    {
        $r = $this->api('GET', '/trackers.json');
        $data = json_decode((string) $r->getContent(), true);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame(['id' => 1, 'name' => 'New'], $data['trackers'][0]['default_status']);
        self::assertSame('Bug', $data['trackers'][0]['name']);
        self::assertContains('assigned_to_id', $data['trackers'][0]['enabled_standard_fields']);
        self::assertArrayHasKey('description', $data['trackers'][0]);
    }
}

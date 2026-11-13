<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Port of api_test/trackers_test.rb, issue_statuses_test.rb, enumerations_test.rb, roles_test.rb,
 * custom_fields_test.rb and custom_fields_attribute_test.rb on the seeded configuration.
 */
final class ReferenceDataApiTest extends ApiTestCase
{
    use AdminApiHelpers;

    public function testTrackers(): void
    {
        $xml = $this->xml('GET', '/trackers.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('array', (string) $xml['type']);
        self::assertSame(['1', '2', '3'], self::xpath($xml, '/trackers/tracker/id'));
        self::assertSame(['New'], self::xpath($xml, '/trackers/tracker[id=1]/default_status/@name'));
        self::assertContains('assigned_to_id', self::xpath($xml, '/trackers/tracker[id=1]/enabled_standard_fields/field'));
        $data = $this->json('GET', '/trackers.json', null, 'jsmith');
        $this->assertJsonResponse();
        self::assertSame(['id', 'name', 'default_status', 'description', 'enabled_standard_fields'], array_keys($data['trackers'][0]));
        self::assertSame(['Bug', 'Feature', 'Support'], array_column($data['trackers'], 'name'));
    }

    public function testTrackerDisabledFieldsAreNotListed(): void
    {
        $this->conn()->executeStatement("UPDATE trackers SET fields_bits = 1 WHERE id = 2"); // assigned_to_id disabled
        $data = $this->json('GET', '/trackers.json', null, 'jsmith');
        self::assertNotContains('assigned_to_id', $data['trackers'][1]['enabled_standard_fields']);
        self::assertContains('category_id', $data['trackers'][1]['enabled_standard_fields']);
    }

    public function testIssueStatuses(): void
    {
        $xml = $this->xml('GET', '/issue_statuses.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame(['1', '2', '3', '4', '5', '6'], self::xpath($xml, '/issue_statuses/issue_status/id'));
        self::assertSame(['true'], self::xpath($xml, '/issue_statuses/issue_status[id=5]/is_closed'));
        $data = $this->json('GET', '/issue_statuses.json', null, 'jsmith');
        self::assertSame(['id' => 6, 'name' => 'Rejected', 'is_closed' => true, 'description' => null], $data['issue_statuses'][5]);
    }

    public function testEnumerations(): void
    {
        $this->conn()->executeStatement("INSERT INTO enumerations (id, name, position, is_default, type, active) VALUES (20, 'Inactive Priority', 6, 0, 'IssuePriority', 0)");
        $xml = $this->xml('GET', '/enumerations/issue_priorities.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame(['Low', 'Normal', 'High', 'Urgent', 'Immediate', 'Inactive Priority'], self::xpath($xml, '/issue_priorities/issue_priority/name'));
        self::assertSame(['true'], self::xpath($xml, '/issue_priorities/issue_priority[id=3]/active'));
        self::assertSame(['false'], self::xpath($xml, '/issue_priorities/issue_priority[id=20]/active'));
        self::assertSame(['true'], self::xpath($xml, '/issue_priorities/issue_priority[id=2]/is_default'));

        $data = $this->json('GET', '/enumerations/time_entry_activities.json', null, 'jsmith');
        self::assertSame([['id' => 8, 'name' => 'Design', 'is_default' => false, 'active' => true], ['id' => 9, 'name' => 'Development', 'is_default' => false, 'active' => true]], $data['time_entry_activities']);
        $data = $this->json('GET', '/enumerations/document_categories.json', null, 'jsmith');
        self::assertSame(['User documentation', 'Technical documentation'], array_column($data['document_categories'], 'name'));
    }

    public function testEnumerationsExcludeProjectOverrides(): void
    {
        $this->conn()->executeStatement("INSERT INTO enumerations (id, name, position, is_default, type, active, project_id, parent_id) VALUES (21, 'Design', 1, 0, 'TimeEntryActivity', 0, 1, 8)");
        $data = $this->json('GET', '/enumerations/time_entry_activities.json', null, 'jsmith');
        self::assertSame([8, 9], self::ids($data['time_entry_activities']));
    }

    public function testInvalidEnumerationIs404(): void
    {
        $this->request('GET', '/enumerations/invalid_subclass.xml', null, null);
        $this->assertStatus(404);
        $this->request('GET', '/enumerations/invalid_subclass.json', null, 'jsmith');
        $this->assertStatus(404);
    }

    public function testRolesIndex(): void
    {
        $xml = $this->xml('GET', '/roles.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('array', (string) $xml['type']);
        self::assertSame(['3', '4', '5'], self::xpath($xml, '/roles/role/id'));
        self::assertSame(['Developer'], self::xpath($xml, '/roles/role[id=4]/name'));
        $data = $this->json('GET', '/roles.json', null, null);
        self::assertSame(['roles' => [['id' => 3, 'name' => 'Manager'], ['id' => 4, 'name' => 'Developer'], ['id' => 5, 'name' => 'Reporter']]], $data);
    }

    public function testRoleShow(): void
    {
        $xml = $this->xml('GET', '/roles/3.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('Manager', (string) $xml->name);
        self::assertSame('true', (string) $xml->assignable);
        self::assertSame('all', (string) $xml->issues_visibility);
        self::assertSame('all', (string) $xml->time_entries_visibility);
        self::assertSame('all', (string) $xml->users_visibility);
        self::assertSame('array', (string) $xml->permissions['type']);
        $perms = json_decode((string) $this->dbOne('SELECT permissions FROM roles WHERE id = 3'), true);
        self::assertCount(\count($perms), $xml->permissions->permission);
        self::assertContains('view_issues', self::xpath($xml, '/role/permissions/permission'));
        $data = $this->json('GET', '/roles/5.json', null, 'jsmith');
        self::assertSame(['id', 'name', 'assignable', 'issues_visibility', 'time_entries_visibility', 'users_visibility', 'permissions'], array_keys($data['role']));
        self::assertSame('Reporter', $data['role']['name']);
        $this->request('GET', '/roles/99.json', null, null);
        $this->assertStatus(404);
    }

    public function testCustomFieldsRequireAdmin(): void
    {
        $this->request('GET', '/custom_fields.json', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/custom_fields.json', null, 'jsmith');
        $this->assertStatus(403);
    }

    public function testCustomFieldsXml(): void
    {
        $xml = $this->xml('GET', '/custom_fields.xml', null, 'admin');
        $this->assertXmlResponse();
        self::assertSame(['1', '2', '3', '4', '5', '6', '7'], self::xpath($xml, '/custom_fields/custom_field/id'));
        $db = $xml->xpath('/custom_fields/custom_field[id=1]')[0];
        self::assertSame('Database', (string) $db->name);
        self::assertSame('issue', (string) $db->customized_type);
        self::assertSame('true', (string) $db->is_for_all);
        self::assertSame(['MySQL', 'PostgreSQL', 'Oracle'], self::xpath($db, 'possible_values/possible_value/value'));
        self::assertSame(['MySQL', 'PostgreSQL', 'Oracle'], self::xpath($db, 'possible_values/possible_value/label'));
        self::assertSame('array', (string) $db->trackers['type']);
        self::assertSame('array', (string) $db->roles['type']);
        self::assertSame('array', (string) $db->projects['type']);
        self::assertSame('true', (string) $db->visible);
        self::assertSame('true', (string) $db->editable);
        // non issue custom fields have no projects nor trackers
        $customer = $xml->xpath('/custom_fields/custom_field[id=5]')[0];
        self::assertSame('project', (string) $customer->customized_type);
        self::assertCount(0, $customer->projects);
        self::assertCount(0, $customer->trackers);
    }

    public function testCustomFieldsProjectsAndRoles(): void
    {
        $this->conn()->executeStatement('UPDATE custom_fields SET is_for_all = 0 WHERE id = 1');
        $data = $this->json('GET', '/custom_fields.json', null, 'admin');
        $fields = array_column($data['custom_fields'], null, 'id');
        self::assertFalse($fields[1]['is_for_all']);
        self::assertSame([['id' => 1, 'name' => 'eCommerce']], $fields[1]['projects']);
        self::assertSame([], $fields[2]['projects']);
        // Budget is visible to Manager only
        self::assertFalse($fields[3]['visible']);
        self::assertSame([['id' => 3, 'name' => 'Manager']], $fields[3]['roles']);
        // user custom fields do not support role visibility
        self::assertArrayNotHasKey('roles', $fields[6]);
        self::assertSame('user', $fields[6]['customized_type']);
    }

    public function testEnumerationCustomFieldValuesAndLabels(): void
    {
        $data = $this->json('GET', '/custom_fields.json', null, 'admin');
        $severity = array_column($data['custom_fields'], null, 'id')[7];
        $enums = $this->db('SELECT id, name FROM custom_field_enumerations WHERE custom_field_id = 7 ORDER BY position');
        self::assertSame(array_map(static fn ($r) => ['value' => (string) $r['id'], 'label' => $r['name']], $enums), $severity['possible_values']);
    }

    public function testDateCustomFieldWithOffsetDefault(): void
    {
        $this->conn()->executeStatement("INSERT INTO custom_fields (id, name, field_format, type, position, is_for_all, visible, editable, default_value, format_store) VALUES (22, 'Deadline', 'date', 'IssueCustomField', 8, 1, 1, 1, '-3', ?)", [json_encode(['default_value_mode' => 'date_offset'])]);
        $xml = $this->xml('GET', '/custom_fields.xml', null, 'admin');
        $field = $xml->xpath('/custom_fields/custom_field[id=22]')[0];
        self::assertSame('date_offset', (string) $field->default_value_mode);
        self::assertSame('-3', (string) $field->default_value);
    }

    /** custom_fields_attribute_test.rb: custom_field_values given as a hash on group creation */
    public function testCustomFieldValuesAttributeOnGroups(): void
    {
        $this->conn()->executeStatement("INSERT INTO custom_fields (id, name, field_format, type, position, visible, editable) VALUES (30, 'Count', 'int', 'GroupCustomField', 1, 1, 1), (31, 'Flag', 'bool', 'GroupCustomField', 2, 1, 1)");
        $this->conn()->executeStatement("INSERT INTO custom_fields (id, name, field_format, type, position, visible, editable, multiple, possible_values, default_value) VALUES (32, 'Multi', 'list', 'GroupCustomField', 3, 1, 1, 1, ?, 'V2')", [json_encode(['V1', 'V2', 'V3'])]);
        $this->request('POST', '/groups.json', '{"group":{"name":"Foo","custom_field_values":{"30":"52","31":"1"}}}', 'admin');
        $this->assertStatus(201);
        $this->request('POST', '/groups.json', '{"group":{"name":"Bar","custom_field_values":{"30":52,"31":1,"32":["V1","V3"]}}}', 'admin');
        $this->assertStatus(201);
        foreach (['Foo', 'Bar'] as $name) {
            $id = (int) $this->dbOne("SELECT id FROM users WHERE type = 'Group' AND lastname = ?", [$name]);
            self::assertSame('52', $this->dbOne("SELECT value FROM custom_values WHERE customized_type = 'Principal' AND customized_id = ? AND custom_field_id = 30", [$id]), $name);
            self::assertSame('1', $this->dbOne("SELECT value FROM custom_values WHERE customized_type = 'Principal' AND customized_id = ? AND custom_field_id = 31", [$id]), $name);
        }
        $id = (int) $this->dbOne("SELECT id FROM users WHERE type = 'Group' AND lastname = 'Bar'");
        self::assertSame(['V1', 'V3'], array_column($this->db("SELECT value FROM custom_values WHERE customized_type = 'Principal' AND customized_id = ? AND custom_field_id = 32 ORDER BY value", [$id]), 'value'));
    }
}

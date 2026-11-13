<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\CustomField;
use App\Entity\IssueCustomField;

final class CustomFieldsTest extends AdminWebTestCase
{
    use LoginWithModernHashTrait;

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    public function testPagesForAdminAndNonAdmin(): void
    {
        $urls = ['/custom_fields', '/custom_fields/new', '/custom_fields/new?type=IssueCustomField', '/custom_fields/new?type=UserCustomField',
            '/custom_fields/1/edit', '/custom_fields/7/edit', '/custom_fields/7/enumerations', '/custom_fields/new?type=IssueCustomField&copy=1'];
        $this->loginAs('admin');
        foreach ($urls as $url) {
            $this->get($url);
            $this->assertStatus(200, $url);
        }
        foreach (['string', 'text', 'link', 'int', 'float', 'date', 'list', 'bool', 'enumeration', 'user', 'version', 'attachment', 'progressbar'] as $format) {
            $this->get('/custom_fields/new?type=IssueCustomField&custom_field[field_format]='.$format);
            $this->assertStatus(200, $format);
        }
        $this->loginAs('jsmith');
        foreach ($urls as $url) {
            $this->get($url);
            $this->assertStatus(403, $url);
        }
    }

    public function testIndexListsFieldsByTab(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/custom_fields?tab=IssueCustomField');
        $this->assertStatus(200);
        self::assertSame(1, $crawler->filter('#tab-IssueCustomField.selected')->count());
        self::assertGreaterThan(0, $crawler->filter('#tab-content-IssueCustomField table.custom_fields td.name a')->count());
        self::assertStringContainsString('Database', $crawler->filter('#tab-content-IssueCustomField')->text());
    }

    public function testCreateIssueCustomField(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/custom_fields', [
            'type' => 'IssueCustomField',
            'custom_field' => [
                'field_format' => 'list', 'name' => 'OS', 'description' => 'Operating system',
                'possible_values' => "Linux\nmacOS\n\nWindows", 'default_value' => 'Linux', 'edit_tag_style' => 'check_box', 'url_pattern' => '',
                'multiple' => '1', 'is_required' => '1', 'is_filter' => '1', 'searchable' => '1',
                'visible' => '0', 'role_ids' => ['3', ''], 'tracker_ids' => ['1', '2', ''], 'is_for_all' => '0', 'project_ids' => ['1', ''],
            ],
        ]);
        self::assertTrue($r->isRedirect(), (string) $r->getContent());
        self::assertSame('/custom_fields?tab=IssueCustomField', $r->headers->get('Location'));
        $this->em->clear();
        /** @var IssueCustomField $cf */
        $cf = $this->em->getRepository(IssueCustomField::class)->findOneBy(['name' => 'OS']);
        self::assertNotNull($cf);
        self::assertSame(['Linux', 'macOS', 'Windows'], $cf->getPossibleValues());
        self::assertTrue($cf->multiple);
        self::assertTrue($cf->isRequired);
        self::assertTrue((bool) $cf->searchable);
        self::assertFalse($cf->visible);
        self::assertSame('check_box', $cf->getFormatOption('edit_tag_style'));
        self::assertSame([3], array_map(static fn ($r) => $r->id, $cf->roles->toArray()));
        self::assertEqualsCanonicalizing([1, 2], array_map(static fn ($t) => $t->id, $cf->trackers->toArray()));
        self::assertSame(['1'], array_map('strval', $this->db()->fetchFirstColumn('SELECT project_id FROM custom_fields_projects WHERE custom_field_id = ?', [$cf->id])));
        $max = (int) $this->db()->fetchOne("SELECT MAX(position) FROM custom_fields WHERE type = 'IssueCustomField' AND id <> ?", [$cf->id]);
        self::assertSame($max + 1, $cf->position);
    }

    public function testCreateValidationErrors(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/custom_fields', ['type' => 'IssueCustomField', 'custom_field' => ['field_format' => 'list', 'name' => '', 'possible_values' => '', 'visible' => '0']]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('errorExplanation', (string) $r->getContent());
        // duplicate name in same type
        $r = $this->post('/custom_fields', ['type' => 'IssueCustomField', 'custom_field' => ['field_format' => 'string', 'name' => 'Database']]);
        self::assertSame(422, $r->getStatusCode());
        // same name allowed in another type
        $r = $this->post('/custom_fields', ['type' => 'UserCustomField', 'custom_field' => ['field_format' => 'string', 'name' => 'Database']]);
        self::assertTrue($r->isRedirect());
        // invalid default value for int
        $r = $this->post('/custom_fields', ['type' => 'ProjectCustomField', 'custom_field' => ['field_format' => 'int', 'name' => 'Size', 'default_value' => 'abc']]);
        self::assertSame(422, $r->getStatusCode());
    }

    public function testUpdateKeepsFormatAndHandlesMultiplicity(): void
    {
        $this->loginAs('admin');
        // Platforms (id 2) is a multiple list field
        $this->db()->executeStatement("INSERT INTO custom_values (customized_type, customized_id, custom_field_id, value) VALUES ('Issue', 1, 2, 'Linux'), ('Issue', 1, 2, 'Windows')");
        $r = $this->post('/custom_fields/2', ['custom_field' => ['field_format' => 'string', 'name' => 'Platform', 'multiple' => '0', 'possible_values' => "Linux\nWindows"]], 'PUT');
        self::assertTrue($r->isRedirect(), (string) $r->getContent());
        $this->em->clear();
        $cf = $this->em->find(CustomField::class, 2);
        self::assertSame('list', $cf->fieldFormat, 'format can not be changed');
        self::assertSame('Platform', $cf->name);
        self::assertFalse($cf->multiple);
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM custom_values WHERE custom_field_id = 2 AND customized_type = 'Issue' AND customized_id = 1"));
    }

    public function testReorderViaXhr(): void
    {
        $this->loginAs('admin');
        $token = $this->csrfToken();
        $this->client->request('POST', '/custom_fields/4', ['_method' => 'PUT', 'custom_field' => ['position' => '1']], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_X-CSRF-Token' => $token]);
        $this->assertStatus(200);
        $positions = $this->db()->fetchAllKeyValue("SELECT id, position FROM custom_fields WHERE type = 'IssueCustomField' ORDER BY position");
        self::assertSame(4, (int) array_key_first($positions));
        self::assertSame(range(1, \count($positions)), array_map('intval', array_values($positions)));
    }

    public function testDestroyDeletesValues(): void
    {
        $this->loginAs('admin');
        $this->db()->executeStatement("INSERT INTO custom_values (customized_type, customized_id, custom_field_id, value) VALUES ('Issue', 1, 3, '12.5')");
        $r = $this->post('/custom_fields/3', [], 'DELETE');
        self::assertSame('/custom_fields?tab=IssueCustomField', $r->headers->get('Location'));
        self::assertFalse((bool) $this->db()->fetchOne('SELECT 1 FROM custom_fields WHERE id = 3'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM custom_values WHERE custom_field_id = 3'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM custom_fields_trackers WHERE custom_field_id = 3'));
    }

    public function testEnumerationValues(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/custom_fields/7/enumerations', ['custom_field_enumeration' => ['name' => 'Blocker']]);
        self::assertSame('/custom_fields/7/enumerations', $r->headers->get('Location'));
        $id = (int) $this->db()->fetchOne("SELECT id FROM custom_field_enumerations WHERE custom_field_id = 7 AND name = 'Blocker'");
        self::assertGreaterThan(0, $id);
        // update_each: rename + deactivate + reorder
        $ids = array_map('intval', $this->db()->fetchFirstColumn('SELECT id FROM custom_field_enumerations WHERE custom_field_id = 7 ORDER BY position'));
        $params = [];
        foreach ($ids as $i => $eid) {
            $params[$eid] = ['name' => $eid === $id ? 'Showstopper' : 'V'.$eid, 'active' => $eid === $id ? '0' : '1', 'position' => (string) (\count($ids) - $i)];
        }
        $r = $this->post('/custom_fields/7/enumerations', ['custom_field_enumerations' => $params], 'PUT');
        self::assertTrue($r->isRedirect());
        $row = $this->db()->fetchAssociative('SELECT name, active, position FROM custom_field_enumerations WHERE id = ?', [$id]);
        self::assertSame('Showstopper', $row['name']);
        self::assertFalse((bool) $row['active']);
        self::assertSame(1, (int) $row['position']);
        // destroy in use => reassign form, then reassign
        $first = $ids[0];
        $this->db()->executeStatement("INSERT INTO custom_values (customized_type, customized_id, custom_field_id, value) VALUES ('Issue', 1, 7, ?)", [(string) $first]);
        $r = $this->post('/custom_fields/7/enumerations/'.$first, [], 'DELETE');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('reassign_to_id', (string) $r->getContent());
        $r = $this->post('/custom_fields/7/enumerations/'.$first, ['reassign_to_id' => (string) $id], 'DELETE');
        self::assertTrue($r->isRedirect());
        self::assertSame((string) $id, $this->db()->fetchOne("SELECT value FROM custom_values WHERE custom_field_id = 7 AND customized_id = 1 AND customized_type = 'Issue'"));
        self::assertFalse((bool) $this->db()->fetchOne('SELECT 1 FROM custom_field_enumerations WHERE id = ?', [$first]));
    }

    public function testApiIndex(): void
    {
        $r = $this->api('GET', '/custom_fields.json');
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        $data = json_decode((string) $r->getContent(), true);
        $byName = array_column($data['custom_fields'], null, 'name');
        self::assertSame('issue', $byName['Database']['customized_type']);
        self::assertSame([['value' => 'MySQL', 'label' => 'MySQL'], ['value' => 'PostgreSQL', 'label' => 'PostgreSQL'], ['value' => 'Oracle', 'label' => 'Oracle']], $byName['Database']['possible_values']);
        self::assertSame('user', $byName['Phone']['customized_type']);
        self::assertArrayNotHasKey('roles', $byName['Phone']);
        self::assertSame([['id' => 3, 'name' => 'Manager']], $byName['Budget']['roles']);
        $r = $this->api('GET', '/custom_fields.xml');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('<custom_fields type="array">', (string) $r->getContent());
        $r = $this->api('GET', '/custom_fields.json', null, 'jsmith', 'password123');
        self::assertSame(403, $r->getStatusCode());
    }
}

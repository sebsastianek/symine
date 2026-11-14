<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\ImportNotificationSuppressor;

final class IssueImportTest extends ImportWebTestCase
{
    private const string CSV = <<<'CSV'
        Tracker,Subject,Description,Priority,Category,Assignee,Target version,Private,Start date,Due date,Estimated time,% Done,Database,Parent task,Unique ID,Blocks
        Bug,First imported,Desc 1,High,UI,jsmith,1.0,no,2026-01-10,2026-01-20,3.5,20,PostgreSQL,,A,
        Feature,Child of first,,Low,NewCat,dlopper,NewVer,yes,2026-01-11,,,,,A,B,
        Bug,Blocker,,,,,,,,,,,,,C,A
        Bug,,missing subject,,,,,,,,,,,,D,
        Bug,Bad date,,,,,,,notadate,,,,,,E,
        Bug,Child before parent,,,,,,,,,,,,G,F,
        Support,Parent later,,,,,,,,,,,,,G,

        CSV;

    public function testFullWizard(): void
    {
        $this->login('jsmith');
        $this->client->request('GET', '/issues/imports/new');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('Import issues', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $this->client->getCrawler()->filter('form[action="/imports"] input[type=file][name=file]'));
        $this->client->request('GET', '/projects/ecommerce/issues/imports/new');
        self::assertSame(200, $this->code());
        self::assertSame('ecommerce', $this->client->getCrawler()->filter('input[name=project_id]')->attr('value'));

        $id = $this->upload('IssueImport', self::CSV, 'ecommerce');
        $import = $this->import($id);
        self::assertSame('IssueImport', $import->type);
        self::assertSame(',', $import->settings['separator']);
        self::assertSame('"', $import->settings['wrapper']);
        self::assertSame(1, $import->settings['mapping']['project_id']);
        self::assertSame('0', $import->settings['notifications']);
        self::assertFileExists(\dirname(__DIR__, 2).'/var/imports/'.$id);

        // settings
        $this->client->request('GET', "/imports/$id/settings");
        self::assertSame(200, $this->code());
        self::assertCount(1, $this->client->getCrawler()->filter('select[name="import_settings[encoding]"] option[value="UTF-8"]'));
        $this->postSettings($id, []);
        self::assertSame(302, $this->code());
        self::assertStringEndsWith("/imports/$id/mapping", $this->location());
        self::assertSame(7, $this->import($id)->totalItems);

        // mapping: auto-mapped by header names (field labels)
        $crawler = $this->client->request('GET', "/imports/$id/mapping");
        self::assertSame(200, $this->code());
        $selected = static fn (string $field) => $crawler->filter("select#import_mapping_$field option[selected]")->count() ? $crawler->filter("select#import_mapping_$field option[selected]")->attr('value') : null;
        self::assertSame('1', $selected('subject'));
        self::assertSame('5', $selected('assigned_to'));
        self::assertSame('6', $selected('fixed_version'));
        self::assertSame('13', $selected('parent_issue_id'));
        self::assertSame('14', $selected('unique_id'));
        self::assertSame('15', $selected('relation_blocks'));
        self::assertSame('12', $selected('cf_1'));
        self::assertSame('1', $crawler->filter('#import_mapping_project_id option[selected]')->attr('value'));
        self::assertGreaterThan(0, $crawler->filter('#import_mapping_tracker option[value="value:1"]')->count());
        self::assertSame(4, $crawler->filter('table.sample-data tr')->count());

        // ajax update of the fields mapping (project/tracker change)
        $this->client->request('POST', "/imports/$id/mapping.js", ['_token' => $this->csrf(), 'import_settings' => ['mapping' => ['project_id' => '1', 'tracker' => 'value:2']]]);
        self::assertSame(200, $this->code());
        self::assertStringStartsWith("$('#fields-mapping').html(", (string) $this->client->getResponse()->getContent());

        // "previous" goes back to settings
        $this->client->request('POST', "/imports/$id/mapping", ['_token' => $this->csrf(), 'previous' => '', 'import_settings' => ['mapping' => ['project_id' => '1']]]);
        self::assertStringEndsWith("/imports/$id/settings", $this->location());

        $this->postMapping($id, [
            'project_id' => '1', 'tracker' => '0', 'subject' => '1', 'description' => '2', 'priority' => '3', 'category' => '4',
            'assigned_to' => '5', 'fixed_version' => '6', 'is_private' => '7', 'start_date' => '8', 'due_date' => '9',
            'estimated_hours' => '10', 'done_ratio' => '11', 'cf_1' => '12', 'parent_issue_id' => '13', 'unique_id' => '14',
            'relation_blocks' => '15', 'create_categories' => '1', 'create_versions' => '1',
        ]);

        $html = $this->runImport($id);
        self::assertStringContainsString('5 items have been imported', $html);
        self::assertStringContainsString('2 out of 7 items could not be imported', $html);
        self::assertStringContainsString('Subject cannot be blank', $html);
        self::assertStringContainsString('Start date is not a valid date', $html);

        $items = $this->items($id);
        self::assertCount(7, $items);
        self::assertNull($items[3]['obj_id']);
        self::assertNull($items[4]['obj_id']);
        $import = $this->import($id);
        self::assertTrue($import->finished);
        self::assertFileDoesNotExist(\dirname(__DIR__, 2).'/var/imports/'.$id);

        $conn = $this->em->getConnection();
        $first = $conn->fetchAssociative('SELECT * FROM issues WHERE id = ?', [$items[0]['obj_id']]);
        self::assertSame('First imported', $first['subject']);
        self::assertSame('Desc 1', $first['description']);
        self::assertSame(1, (int) $first['project_id']);
        self::assertSame(1, (int) $first['tracker_id']);
        // priority of a parent is derived from its subtasks (parent_issue_priority = derived)
        self::assertSame(1, (int) $first['priority_id']);
        self::assertSame(1, (int) $first['category_id']);
        self::assertSame(5, (int) $first['assigned_to_id']);
        self::assertSame(1, (int) $first['fixed_version_id']);
        self::assertSame(5, (int) $first['author_id']);
        self::assertSame(3.5, (float) $first['estimated_hours']);
        self::assertSame('PostgreSQL', $conn->fetchOne("SELECT value FROM custom_values WHERE customized_type = 'Issue' AND customized_id = ? AND custom_field_id = 1", [$first['id']]));

        $child = $conn->fetchAssociative('SELECT * FROM issues WHERE id = ?', [$items[1]['obj_id']]);
        self::assertSame(2, (int) $child['tracker_id']);
        self::assertSame((int) $first['id'], (int) $child['parent_id']);
        self::assertSame(6, (int) $child['assigned_to_id']);
        self::assertSame(1, (int) $child['is_private']);
        self::assertSame('NewCat', $conn->fetchOne('SELECT name FROM issue_categories WHERE id = ?', [$child['category_id']]));
        self::assertSame('NewVer', $conn->fetchOne('SELECT name FROM versions WHERE id = ?', [$child['fixed_version_id']]));

        // "Blocker" (C) blocks A
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM issue_relations WHERE issue_from_id = ? AND issue_to_id = ? AND relation_type = 'blocks'", [$items[2]['obj_id'], $first['id']]));
        // F's parent is G, imported later (deferred callback)
        self::assertSame($items[6]['obj_id'], (int) $conn->fetchOne('SELECT parent_id FROM issues WHERE id = ?', [$items[5]['obj_id']]));
        self::assertSame(3, (int) $conn->fetchOne('SELECT tracker_id FROM issues WHERE id = ?', [$items[6]['obj_id']]));

        // notifications disabled (issue.notify = false): no "issue added" e-mail for those issues;
        // the suppression only lasts for the request
        foreach (self::getMailerMessages() as $message) {
            self::assertStringNotContainsString('has been reported by', (string) $message->getTextBody());
        }
        $suppressor = static::getContainer()->get(ImportNotificationSuppressor::class);
        self::assertFalse($suppressor->isSuppressed((int) $first['id']));
        self::assertFalse($suppressor->isActive());

        // saved objects listed, finished import cannot be run again
        self::assertStringContainsString('First imported', $html);
        $this->client->request('GET', "/imports/$id/mapping");
        self::assertStringEndsWith("/imports/$id", $this->location());
    }

    public function testMissingTrackerAndChunkedRun(): void
    {
        $this->login('jsmith');
        $rows = "Subject,Tracker\n";
        for ($i = 1; $i <= 12; ++$i) {
            $rows .= "Issue $i,".(12 === $i ? 'Unknown' : 'Bug')."\n";
        }
        $id = $this->upload('IssueImport', $rows);
        $this->postSettings($id, ['notifications' => '1']);
        $this->postMapping($id, ['project_id' => '1', 'tracker' => '1', 'subject' => '0']);
        // first chunk: 5 items
        $this->client->request('POST', "/imports/$id/run", ['_token' => $this->csrf()]);
        self::assertStringEndsWith("/imports/$id/run", $this->location());
        self::assertCount(5, $this->items($id));
        // JS polling flow (run.js), as used by the progress bar page
        $this->client->request('POST', "/imports/$id/run.js", ['_token' => $this->csrf()]);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('text/javascript', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('"10 \/ 12"', (string) $this->client->getResponse()->getContent());
        self::assertCount(10, $this->items($id));
        $this->runImport($id);
        $items = $this->items($id);
        self::assertCount(12, $items);
        self::assertNull($items[11]['obj_id']);
        self::assertSame('Tracker cannot be blank', $items[11]['message']);
        self::assertFalse(static::getContainer()->get(ImportNotificationSuppressor::class)->isSuppressed((int) $items[0]['obj_id']));
    }

    public function testFixedTrackerAndSemicolonFile(): void
    {
        $this->login('jsmith');
        $id = $this->upload('IssueImport', "Sujet;Echeance\n\"Semi; colon\";31.12.2026\n");
        $import = $this->import($id);
        self::assertSame(';', $import->settings['separator']);
        $this->postSettings($id, ['separator' => ';', 'date_format' => '%d.%m.%Y']);
        $this->postMapping($id, ['project_id' => '1', 'tracker' => 'value:2', 'subject' => '0', 'due_date' => '1']);
        $this->runImport($id);
        $items = $this->items($id);
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM issues WHERE id = ?', [$items[0]['obj_id']]);
        self::assertSame('Semi; colon', $row['subject']);
        self::assertSame(2, (int) $row['tracker_id']);
        self::assertSame('2026-12-31', substr((string) $row['due_date'], 0, 10));
    }

    public function testSettingsErrors(): void
    {
        $this->login('jsmith');
        $id = $this->upload('IssueImport', "Subject\n");
        $this->postSettings($id, []);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('The file does not contain any data', (string) $this->client->getResponse()->getContent());

        $id = $this->upload('IssueImport', "Subject\n\xC3\x28 invalid\n");
        $this->postSettings($id, ['encoding' => 'UTF-8']);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('The file is not a valid UTF-8 encoded file', (string) $this->client->getResponse()->getContent());

        $this->postSettings($id, ['encoding' => 'ISO-8859-1']);
        self::assertSame(302, $this->code());

        $id = $this->upload('IssueImport', "Subject\n\"unclosed\n");
        $this->postSettings($id, []);
        self::assertStringContainsString('The file is not a CSV file or does not match the settings below', (string) $this->client->getResponse()->getContent());
    }

    public function testPermissions(): void
    {
        $this->client->request('GET', '/issues/imports/new');
        self::assertContains($this->code(), [302, 401]);

        $this->login('dlopper'); // Developer: no import_issues
        $this->client->request('GET', '/issues/imports/new');
        self::assertSame(403, $this->code());
        $this->client->request('GET', '/users/imports/new');
        self::assertSame(403, $this->code());

        // an import of another user is not found
        $this->login('jsmith');
        $id = $this->upload('IssueImport', "Subject\nA\n");
        $this->login('admin');
        $this->client->request('GET', "/imports/$id/settings");
        self::assertSame(404, $this->code());
    }
}

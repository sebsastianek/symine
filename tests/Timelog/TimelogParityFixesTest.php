<?php

declare(strict_types=1);

namespace App\Tests\Timelog;

use App\Entity\TimeEntryCustomField;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Parity fixes for time tracking (expected results from Redmine's timelog_controller_test.rb,
 * gantts_controller_test.rb and time_entry_import_test.rb / issue_import_test.rb).
 */
final class TimelogParityFixesTest extends TimelogWebTestCase
{
    /** A time entry logged by admin on the private issue #17 (checkout), which only rhill, jsmith and admin can see */
    private function entryOnPrivateIssue(): int
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE issues SET category_id = 1, fixed_version_id = 1 WHERE id = 17');
        $conn->executeStatement("INSERT INTO custom_values (customized_type, customized_id, custom_field_id, value) VALUES ('Issue', 17, 1, 'MySQL')");
        $conn->executeStatement("INSERT INTO time_entries (project_id, user_id, author_id, issue_id, hours, comments, activity_id, spent_on, tyear, tmonth, tweek, created_on, updated_on)"
            ." VALUES (3, 1, 1, 17, 4, 'checkout work', 8, '2026-11-10', 2026, 11, 46, '2026-11-10 10:00:00', '2026-11-10 10:00:00')");

        return (int) $conn->lastInsertId();
    }

    /**
     * timelog_controller_test.rb test_index_should_not_disclose_issue_data: QueryAssociationColumn and
     * QueryAssociationCustomFieldColumn give nothing when the issue is not visible.
     */
    public function testColumnsOfInvisibleIssuesAreNotDisclosed(): void
    {
        $id = $this->entryOnPrivateIssue();
        $url = '/projects/checkout/time_entries?c[]=issue&c[]=issue.tracker&c[]=issue.status&c[]=issue.category&c[]=issue.fixed_version&c[]=issue.cf_1&c[]=hours';

        $this->login('dlopper');
        $this->client->request('GET', '/issues/17');
        self::assertSame(403, $this->code());
        $crawler = $this->client->request('GET', $url);
        self::assertSame(200, $this->code());
        $row = $crawler->filter('tr#time-entry-'.$id);
        self::assertCount(1, $row);
        self::assertSame('#17', trim($row->filter('td.issue')->text()));
        foreach (['td.issue-tracker', 'td.issue-status', 'td.issue-category', 'td.issue-fixed_version', 'td.issue_cf_1'] as $cell) {
            self::assertSame('', trim($row->filter($cell)->text()), $cell);
        }
        self::assertStringNotContainsString('Payment fails on Safari', $this->content());
        $csv = $this->get(str_replace('time_entries?', 'time_entries.csv?', $url));
        self::assertSame(200, $this->code());
        self::assertStringContainsString("\n#17,\"\",\"\",\"\",\"\",\"\",4.00\n", $csv);
        foreach (['Bug', 'MySQL', 'UI'] as $hidden) {
            self::assertStringNotContainsString($hidden, $csv);
        }

        // rhill is the author of the private issue: everything is shown
        $this->login('rhill');
        $crawler = $this->client->request('GET', $url);
        $row = $crawler->filter('tr#time-entry-'.$id);
        self::assertStringContainsString('Payment fails on Safari', $row->filter('td.issue')->text());
        self::assertSame('Bug', trim($row->filter('td.issue-tracker')->text()));
        self::assertSame('New', trim($row->filter('td.issue-status')->text()));
        self::assertSame('UI', trim($row->filter('td.issue-category')->text()));
        self::assertSame('1.0', trim($row->filter('td.issue-fixed_version')->text()));
        self::assertSame('MySQL', trim($row->filter('td.issue_cf_1')->text()));
    }

    /** find_optional_project: params[:project_id] (id or identifier) scopes the list, the report and the API */
    public function testProjectIdParameterScopesTheList(): void
    {
        $this->entryOnPrivateIssue();
        $admin = (string) $this->sql("SELECT value FROM tokens WHERE user_id = 1 AND action = 'api'");
        if ('' === $admin) {
            $this->login('admin');
            $this->get('/my/api_key');
            $admin = (string) $this->sql("SELECT value FROM tokens WHERE user_id = 1 AND action = 'api'");
        }
        $scoped = $this->api('GET', '/projects/ecommerce/time_entries.json', null, $admin);
        self::assertSame(3, $scoped['total_count'], 'ecommerce and its subproject checkout');
        foreach (['ecommerce', '1'] as $param) {
            $data = $this->api('GET', '/time_entries.json?project_id='.$param, null, $admin);
            self::assertSame(3, $data['total_count'], "project_id=$param");
            $projects = array_values(array_unique(array_map(static fn ($e) => $e['project']['name'], $data['time_entries'])));
            sort($projects);
            self::assertSame(['Checkout', 'eCommerce'], $projects);
        }
        self::assertSame(1, $this->api('GET', '/time_entries.json?project_id=checkout', null, $admin)['total_count']);
        self::assertSame(0, $this->api('GET', '/time_entries.json?project_id=internal', null, $admin)['total_count']);
        $this->api('GET', '/time_entries.json?project_id=nope', null, $admin);
        self::assertSame(404, $this->code());

        // HTML: timelog_controller_test.rb test_index_at_project_level (get :index, project_id: 'ecookbook')
        $this->login('jsmith');
        $crawler = $this->client->request('GET', '/time_entries?project_id=ecommerce&c[]=project');
        self::assertSame(200, $this->code());
        self::assertCount(3, $crawler->filter('tr.time-entry'));
        self::assertSame('/projects/ecommerce/time_entries', $crawler->filter('form#query_form')->attr('action'));
        $projects = array_values(array_unique($crawler->filter('table.time-entries tbody td.project')->each(static fn ($td) => trim($td->text()))));
        sort($projects);
        self::assertSame(['Checkout', 'eCommerce'], $projects);
        $csv = $this->get('/time_entries.csv?project_id=checkout');
        self::assertSame(2, substr_count(trim($csv), "\n") + 1, 'header + 1 entry');
        $this->get('/time_entries/report?project_id=ecommerce&criteria[]=project');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('Checkout', $this->content());
        self::assertStringNotContainsString('Internal Tools</a></td>', $this->content());
    }

    /** timelog_controller_test.rb test_new_with_project_id / test_new_with_issue_id and POST with params[:project_id] */
    public function testProjectIdAndIssueIdParametersOfTheLogTimeForm(): void
    {
        $this->login('jsmith');
        $crawler = $this->client->request('GET', '/time_entries/new?project_id=internal');
        self::assertSame(200, $this->code());
        self::assertSame('internal', $crawler->filter('input[type=hidden][name=project_id]')->attr('value'));
        self::assertCount(0, $crawler->filter('input[type=hidden][name=issue_id]'));
        self::assertCount(0, $crawler->filter('select[name="time_entry[project_id]"]'));

        $crawler = $this->client->request('GET', '/time_entries/new?issue_id=13');
        self::assertSame(200, $this->code());
        self::assertSame('13', $crawler->filter('input[type=hidden][name=issue_id]')->attr('value'));
        self::assertSame('13', $crawler->filter('input[name="time_entry[issue_id]"]')->attr('value'));
        self::assertCount(0, $crawler->filter('select[name="time_entry[project_id]"]'));
        $this->client->request('GET', '/time_entries/new?issue_id=99999');
        self::assertSame(404, $this->code());

        // API: project given by the query string or at the top level of the payload
        $created = $this->api('POST', '/time_entries.json?project_id=internal', ['time_entry' => ['hours' => 1, 'activity_id' => 8]]);
        self::assertSame(201, $this->code(), json_encode($created));
        self::assertSame('Internal Tools', $created['time_entry']['project']['name']);
        $created = $this->api('POST', '/time_entries.json', ['project_id' => 'internal', 'time_entry' => ['hours' => 2, 'activity_id' => 8]]);
        self::assertSame(201, $this->code(), json_encode($created));
        self::assertSame(2, $created['time_entry']['project']['id']);
        $created = $this->api('POST', '/time_entries.json', ['issue_id' => 13, 'time_entry' => ['hours' => 2, 'activity_id' => 8]]);
        self::assertSame(201, $this->code(), json_encode($created));
        self::assertSame(13, $created['time_entry']['issue']['id']);
        // and the plain API call still works
        $created = $this->api('POST', '/time_entries.json', ['time_entry' => ['project_id' => 1, 'hours' => 2, 'activity_id' => 8]]);
        self::assertSame(201, $this->code());
    }

    /** gantts_controller.rb: before_action :find_optional_project */
    public function testGanttHonoursTheProjectIdParameter(): void
    {
        $this->login('jsmith');
        $crawler = $this->client->request('GET', '/issues/gantt?project_id=ecommerce');
        self::assertSame(200, $this->code());
        self::assertSame('/projects/ecommerce/issues/gantt', $crawler->filter('form#query_form')->attr('action'));
        // the gantt module is not enabled on "internal"
        $this->client->request('GET', '/issues/gantt?project_id=internal');
        self::assertSame(403, $this->code());
        $this->client->request('GET', '/issues/gantt?project_id=nope');
        self::assertSame(404, $this->code());
        $crawler = $this->client->request('GET', '/issues/gantt');
        self::assertSame(200, $this->code());
        self::assertSame('/issues/gantt', $crawler->filter('form#query_form')->attr('action'));
    }

    /** timelog/_form.html.erb: @time_entry.editable_custom_field_values are shown even when no project is selected yet */
    public function testGlobalLogTimeFormShowsCustomFields(): void
    {
        $visible = new TimeEntryCustomField();
        $visible->name = 'Billable type';
        $visible->fieldFormat = 'list';
        $visible->possibleValues = ['Internal', 'Customer'];
        $visible->position = 1;
        $hidden = new TimeEntryCustomField();
        $hidden->name = 'Internal cost';
        $hidden->fieldFormat = 'string';
        $hidden->visible = false;
        $hidden->position = 2;
        $this->em->persist($visible);
        $this->em->persist($hidden);
        $this->em->flush();

        $this->login('jsmith');
        $crawler = $this->client->request('GET', '/time_entries/new');
        self::assertSame(200, $this->code());
        self::assertCount(1, $crawler->filter('select[name="time_entry[project_id]"]'));
        self::assertCount(1, $crawler->filter('select[name="time_entry[custom_field_values]['.$visible->id.']"]'));
        self::assertCount(0, $crawler->filter('[name="time_entry[custom_field_values]['.$hidden->id.']"]'), 'not visible without a role in a project');
        $crawler = $this->client->request('GET', '/projects/ecommerce/time_entries/new');
        self::assertCount(1, $crawler->filter('select[name="time_entry[custom_field_values]['.$visible->id.']"]'));

        // the value is saved when the project is chosen in the form
        self::assertSame(302, $this->submit('/time_entries', ['time_entry' => ['project_id' => '1', 'hours' => '1h30', 'activity_id' => '8', 'spent_on' => '2026-11-10', 'custom_field_values' => [$visible->id => 'Customer']]]));
        $entryId = (int) $this->sql('SELECT MAX(id) FROM time_entries');
        self::assertSame('Customer', $this->sql("SELECT value FROM custom_values WHERE customized_type = 'TimeEntry' AND customized_id = ? AND custom_field_id = ?", [$entryId, $visible->id]));
        self::assertEqualsWithDelta(1.5, (float) $this->sql('SELECT hours FROM time_entries WHERE id = ?', [$entryId]), 0.001);
    }

    /**
     * Imports a CSV through the wizard and returns the import items (position => [obj_id, message]).
     *
     * @param array<string, string> $mapping
     *
     * @return array<int, array{obj_id: ?int, message: ?string}>
     */
    private function import(string $type, string $csv, array $mapping, string $notifications = '0'): array
    {
        $path = sys_get_temp_dir().'/symine-parity-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, $csv);
        try {
            $this->client->request('POST', '/imports', ['_token' => $this->csrf(), 'type' => $type, 'project_id' => '1'], ['file' => new UploadedFile($path, 'import.csv', 'text/csv', null, true)]);
            self::assertSame(302, $this->code(), $this->content());
            preg_match('#/imports/([0-9a-f]{32})/settings$#', $this->location(), $m);
            $id = $m[1];
            $this->client->request('POST', "/imports/$id/settings", ['_token' => $this->csrf(), 'import_settings' => ['separator' => ',', 'wrapper' => '"', 'encoding' => 'UTF-8', 'date_format' => '%Y-%m-%d', 'notifications' => $notifications]]);
            self::assertStringEndsWith("/imports/$id/mapping", $this->location());
            $this->client->request('POST', "/imports/$id/mapping", ['_token' => $this->csrf(), 'import_settings' => ['mapping' => $mapping]]);
            self::assertStringEndsWith("/imports/$id/run", $this->location());
            for ($i = 0; $i < 20; ++$i) {
                $this->client->request('POST', "/imports/$id/run", ['_token' => $this->csrf()]);
                if (str_ends_with($this->location(), "/imports/$id")) {
                    break;
                }
            }
            $items = [];
            foreach ($this->em->getConnection()->fetchAllAssociative('SELECT ii.position, ii.obj_id, ii.message FROM import_items ii JOIN imports i ON i.id = ii.import_id WHERE i.filename = ? ORDER BY ii.position', [$id]) as $r) {
                $items[(int) $r['position']] = ['obj_id' => null === $r['obj_id'] ? null : (int) $r['obj_id'], 'message' => $r['message']];
            }

            return $items;
        } finally {
            @unlink($path);
        }
    }

    /** TimeEntry#hours= and Issue#estimated_hours= use String#to_hours: "2h30", "1:30", "90m", "1,5" (core_ext/string/conversions.rb) */
    public function testImportsAcceptRedmineHoursFormats(): void
    {
        $this->login('jsmith');
        $formats = ['1h30' => 1.5, '2h30m' => 2.5, '1:15' => 1.25, '45m' => 0.75, '"1,5"' => 1.5, '3h' => 3.0, '2 hours' => 2.0, '0.25' => 0.25];
        $csv = "date,hours,activity,user,comments\n";
        foreach (array_keys($formats) as $i => $f) {
            $csv .= \sprintf("2026-09-%02d,%s,Design,jsmith,parity %d\n", $i + 1, $f, $i);
        }
        $csv .= "2026-09-20,abc,Design,jsmith,bad\n2026-09-21,2d,Design,jsmith,bad\n";
        $items = $this->import('TimeEntryImport', $csv, ['project_id' => '1', 'spent_on' => '0', 'hours' => '1', 'activity' => '2', 'user' => '3', 'comments' => '4']);
        self::assertCount(\count($formats) + 2, $items);
        foreach (array_values($formats) as $i => $expected) {
            self::assertNotNull($items[$i + 1]['obj_id'], 'row '.($i + 1).': '.$items[$i + 1]['message']);
            self::assertEqualsWithDelta($expected, (float) $this->sql('SELECT hours FROM time_entries WHERE id = ?', [$items[$i + 1]['obj_id']]), 0.001, array_keys($formats)[$i]);
        }
        self::assertStringContainsString('Hours is invalid', (string) $items[\count($formats) + 1]['message']);
        self::assertStringContainsString('Hours is invalid', (string) $items[\count($formats) + 2]['message']);

        $csv = "tracker,subject,estimated\nBug,Estimated 2h30,2h30\nBug,Estimated 1:45,1:45\nBug,Estimated 90m,90m\nBug,Estimated junk,junk\n";
        $items = $this->import('IssueImport', $csv, ['project_id' => '1', 'tracker' => '0', 'subject' => '1', 'estimated_hours' => '2']);
        self::assertCount(4, $items);
        foreach ([1 => 2.5, 2 => 1.75, 3 => 1.5] as $position => $expected) {
            self::assertNotNull($items[$position]['obj_id'], (string) $items[$position]['message']);
            self::assertEqualsWithDelta($expected, (float) $this->sql('SELECT estimated_hours FROM issues WHERE id = ?', [$items[$position]['obj_id']]), 0.001);
        }
        self::assertNull($items[4]['obj_id']);
        self::assertStringContainsString('Estimated time is invalid', (string) $items[4]['message']);
    }

    /** @return list<string> "recipient: subject" of the e-mails sent by the last request, sorted */
    private function sent(): array
    {
        $out = [];
        foreach (self::getMailerMessages() as $m) {
            if ($m instanceof \Symfony\Component\Mime\Email) {
                $out[] = $m->getTo()[0]->getAddress().': '.$m->getSubject();
            }
        }
        sort($out);

        return $out;
    }

    /**
     * IssueImport: issue.notify follows the "notifications" setting of the import; relations and parents set by
     * the import do not go through a journal (IssueRelation.create!, child.save!), so they add no history entry
     * and no "issue updated" e-mail.
     */
    public function testIssueImportNotifications(): void
    {
        $this->login('jsmith');
        $csv = "tracker,subject,assignee,parent,relates,description\n"
            ."Bug,Imported child,dlopper,2,,Hello @rhill\n"
            ."Bug,Imported parent,dlopper,,1,\n"
            ."Bug,Imported other,dlopper,,#13,\n";
        $mapping = ['project_id' => '1', 'tracker' => '0', 'subject' => '1', 'assigned_to' => '2', 'parent_issue_id' => '3', 'relation_relates' => '4', 'description' => '5'];

        $items = $this->import('IssueImport', $csv, $mapping, '1');
        self::assertCount(3, $items);
        [$child, $parent, $other] = [$items[1]['obj_id'], $items[2]['obj_id'], $items[3]['obj_id']];
        self::assertNotNull($child, (string) $items[1]['message']);
        self::assertNotNull($parent, (string) $items[2]['message']);
        self::assertNotNull($other, (string) $items[3]['message']);
        self::assertSame($parent, (int) $this->sql('SELECT parent_id FROM issues WHERE id = ?', [$child]));
        self::assertSame(2, (int) $this->sql('SELECT COUNT(*) FROM issue_relations WHERE issue_from_id IN (?, ?) OR issue_to_id IN (?, ?)', [$parent, $other, $parent, $other]));
        // one "issue added" e-mail per issue and recipient (dlopper: assignee, rhill: mentioned), and the
        // "Subtask added" update of the parent (Issue#create_parent_issue_journal); the relations and the
        // child.save! of the deferred parent are not journalized
        self::assertSame([
            "dlopper@example.net: [eCommerce - Bug #$child] (New) Imported child",
            "dlopper@example.net: [eCommerce - Bug #$parent] (New) Imported parent",
            "dlopper@example.net: [eCommerce - Bug #$parent] Imported parent",
            "dlopper@example.net: [eCommerce - Bug #$other] (New) Imported other",
            "rhill@example.net: [eCommerce - Bug #$child] (New) Imported child",
        ], $this->sent());
        $journals = fn (array $ids) => $this->em->getConnection()->fetchAllAssociative(
            "SELECT j.journalized_id AS issue, d.prop_key, d.value FROM journals j LEFT JOIN journal_details d ON d.journal_id = j.id WHERE j.journalized_type = 'Issue' AND j.journalized_id IN (?) ORDER BY j.id",
            [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        self::assertEquals([['issue' => $parent, 'prop_key' => 'child_id', 'value' => (string) $child]], $journals([$child, $parent, $other]));

        // notifications off: Issue#notify = false only silences the "issue added" e-mails of the imported issues
        $before = (int) $this->sql('SELECT MAX(id) FROM journals');
        $items = $this->import('IssueImport', $csv, $mapping, '0');
        self::assertNotNull($items[1]['obj_id']);
        self::assertSame($items[2]['obj_id'], (int) $this->sql('SELECT parent_id FROM issues WHERE id = ?', [$items[1]['obj_id']]));
        self::assertSame(['dlopper@example.net: [eCommerce - Bug #'.$items[2]['obj_id'].'] Imported parent'], $this->sent());
        self::assertSame(1, (int) $this->sql('SELECT COUNT(*) FROM journals WHERE id > ?', [$before]));
    }

    /** TimeEntryImport sends nothing; UserImport sends the account information when notifications are on */
    public function testTimeEntryAndUserImportNotifications(): void
    {
        $this->login('jsmith');
        $items = $this->import('TimeEntryImport', "date,hours,activity,user,issue\n2026-09-01,2,Design,dlopper,13\n", ['project_id' => '1', 'spent_on' => '0', 'hours' => '1', 'activity' => '2', 'user' => '3', 'issue_id' => '4'], '1');
        self::assertNotNull($items[1]['obj_id'], (string) $items[1]['message']);
        self::assertSame([], $this->sent());

        $this->login('admin');
        $users = "login,firstname,lastname,mail,password\nimported1,Imported,One,imported1@example.net,Secret123!x\n";
        $mapping = ['login' => '0', 'firstname' => '1', 'lastname' => '2', 'mail' => '3', 'password' => '4'];
        $items = $this->import('UserImport', $users, $mapping, '1');
        self::assertNotNull($items[1]['obj_id'], (string) $items[1]['message']);
        self::assertSame(['imported1@example.net: Your Redmine Reference account activation'], $this->sent());
        self::assertStringContainsString('Secret123!x', (string) self::getMailerMessages()[0]->getTextBody());
        $items = $this->import('UserImport', str_replace('imported1', 'imported2', $users), $mapping, '0');
        self::assertNotNull($items[1]['obj_id'], (string) $items[1]['message']);
        self::assertSame([], $this->sent());
    }
}

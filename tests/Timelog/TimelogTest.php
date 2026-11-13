<?php

declare(strict_types=1);

namespace App\Tests\Timelog;

use App\Entity\TimeEntry;
use App\Entity\TimeEntryActivity;
use App\Entity\Project;
use App\Service\TimeEntryService;

final class TimelogTest extends TimelogWebTestCase
{
    public function testPagesReturn200ForAdminAndJsmith(): void
    {
        foreach (['admin', 'jsmith'] as $login) {
            $this->login($login);
            $this->assertPagesOk([
                '/time_entries',
                '/projects/ecommerce/time_entries',
                '/projects/ecommerce/time_entries?set_filter=1&f[]=spent_on&op[spent_on]=lw&f[]=user_id&op[user_id]==&v[user_id][]=me&group_by=user',
                '/time_entries?set_filter=1&c[]=project&c[]=spent_on&c[]=tweek&c[]=author&c[]=issue.tracker&c[]=issue.status&c[]=issue.parent&c[]=issue.category&c[]=issue.fixed_version&c[]=issue.cf_1&c[]=project.cf_5&c[]=hours&group_by=activity&sort=issue.status,user',
                '/time_entries?group_by=project&sort=author:desc',
                '/time_entries/report?criteria[]=project&criteria[]=user&criteria[]=activity&columns=week',
                '/projects/ecommerce/time_entries/report?criteria[]=issue&criteria[]=status&criteria[]=version&columns=day',
                '/projects/ecommerce/time_entries/report?criteria[]=tracker&criteria[]=category&columns=year',
                '/time_entries/new',
                '/projects/ecommerce/time_entries/new',
                '/issues/13/time_entries/new',
                '/time_entries/1/edit',
                '/time_entries/2/edit',
                '/time_entries/context_menu?ids[]=1&ids[]=2',
                '/time_entries/context_menu?ids[]=1',
                '/time_entries/bulk_edit?ids[]=1&ids[]=2',
                '/my/page',
            ]);
        }
        self::assertSame(200, $this->code());
    }

    public function testIndexListsEntriesWithTotals(): void
    {
        $this->login('jsmith');
        $html = $this->get('/projects/ecommerce/time_entries');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('id="time-entry-1"', $html);
        self::assertStringContainsString('id="time-entry-2"', $html);
        self::assertStringContainsString('<p class="query-totals"><span class="total-for-hours"><span>Hours:</span> <span class="value">3:30</span>', $html);
        self::assertStringContainsString('Bug #13</a>: Cart total is wrong with discounts', $html);
    }

    public function testFilters(): void
    {
        $this->login('admin');
        $ids = function (string $url): array {
            $data = $this->api('GET', $url, null, self::JSMITH_KEY);
            self::assertSame(200, $this->code(), $url);

            return array_map(static fn ($e) => $e['id'], $data['time_entries']);
        };
        self::assertSame([2, 1], $ids('/time_entries.json'));
        self::assertSame([2], $ids('/time_entries.json?user_id=me'));
        self::assertSame([1], $ids('/time_entries.json?issue_id=~13'));
        self::assertSame([1], $ids('/time_entries.json?issue_id=13'));
        self::assertSame([2], $ids('/time_entries.json?issue_id=!*'));
        self::assertSame([1], $ids('/time_entries.json?from=2026-11-06&to=2026-11-06'));
        self::assertSame([1], $ids('/time_entries.json?set_filter=1&f[]=hours&op[hours]=>%3D&v[hours][]=2'));
        self::assertSame([2], $ids('/time_entries.json?set_filter=1&f[]=comments&op[comments]=~&v[comments][]=meet'));
        self::assertSame([], $ids('/time_entries.json?set_filter=1&f[]=activity_id&op[activity_id]==&v[activity_id][]=9'));
        self::assertSame([1], $ids('/time_entries.json?set_filter=1&f[]=issue.tracker_id&op[issue.tracker_id]==&v[issue.tracker_id][]=1'));
        self::assertSame([2, 1], $ids('/projects/ecommerce/time_entries.json?sort=spent_on:desc'));
        self::assertSame([1, 2], $ids('/time_entries.json?sort=hours:desc'));
        $data = $this->api('GET', '/time_entries.json?limit=1&offset=1');
        self::assertSame(2, $data['total_count']);
        self::assertSame(1, $data['offset']);
        self::assertSame(1, $data['limit']);
        self::assertSame(1, $data['time_entries'][0]['id']);
    }

    public function testCreateFromFormAndValidation(): void
    {
        $this->login('jsmith');
        // blank hours
        $code = $this->submit('/projects/ecommerce/time_entries', ['project_id' => 'ecommerce', 'time_entry' => ['issue_id' => '', 'spent_on' => '2026-11-01', 'hours' => '', 'activity_id' => '9', 'comments' => 'x']]);
        self::assertSame(422, $code);
        self::assertStringContainsString('Hours cannot be blank', $this->content());

        $code = $this->submit('/projects/ecommerce/time_entries', ['project_id' => 'ecommerce', 'time_entry' => ['issue_id' => '13', 'spent_on' => '2026-11-01', 'hours' => '1h30', 'activity_id' => '9', 'comments' => 'From form']]);
        self::assertSame(302, $code);
        self::assertStringEndsWith('/projects/ecommerce/time_entries', $this->location());
        $e = $this->em->getRepository(TimeEntry::class)->findOneBy(['comments' => 'From form']);
        self::assertNotNull($e);
        self::assertSame(1.5, $e->hours);
        self::assertSame(2026, $e->tyear);
        self::assertSame(11, $e->tmonth);
        self::assertSame(44, $e->tweek);
        self::assertSame(13, $e->issue?->id);
        self::assertSame('jsmith', $e->user->login);
        self::assertSame('jsmith', $e->author?->login);

        // create and continue keeps the activity
        $this->submit('/issues/13/time_entries', ['issue_id' => '13', 'continue' => '1', 'time_entry' => ['issue_id' => '13', 'spent_on' => '2026-11-02', 'hours' => '1', 'activity_id' => '8']]);
        self::assertSame(302, $this->code());
        self::assertStringContainsString('/issues/13/time_entries/new', $this->location());
        self::assertStringContainsString('activity_id', urldecode($this->location()));
    }

    public function testSettingsValidations(): void
    {
        $this->login('jsmith');
        $this->setting('timelog_max_hours_per_day', '2');
        $this->submit('/projects/ecommerce/time_entries', ['project_id' => 'ecommerce', 'time_entry' => ['spent_on' => '2026-11-07', 'hours' => '1.5', 'activity_id' => '9']]);
        self::assertSame(422, $this->code());
        self::assertStringContainsString('Cannot log more than 2:00 hours on the same day (1:00 hours have already been logged)', $this->content());

        $this->setting('timelog_max_hours_per_day', '999');
        $this->setting('timelog_accept_future_dates', '0');
        $this->setting('timelog_accept_0_hours', '0');
        $this->setting('timelog_required_fields', ['issue_id', 'comments']);
        $this->submit('/projects/ecommerce/time_entries', ['project_id' => 'ecommerce', 'time_entry' => ['spent_on' => '2099-01-01', 'hours' => '0', 'activity_id' => '9']]);
        $html = html_entity_decode($this->content(), \ENT_QUOTES);
        self::assertStringContainsString('Cannot log time on a future date', $html);
        self::assertStringContainsString('Hours is invalid', $html);
        self::assertStringContainsString('Issue cannot be blank', $html);
        self::assertStringContainsString('Comment cannot be blank', $html);

        $this->setting('timelog_accept_closed_issues', '0');
        $this->em->getConnection()->executeStatement('UPDATE issues SET status_id = 5 WHERE id = 13');
        $this->em->clear();
        $this->submit('/projects/ecommerce/time_entries', ['project_id' => 'ecommerce', 'time_entry' => ['issue_id' => '13', 'comments' => 'c', 'spent_on' => '2026-11-01', 'hours' => '1', 'activity_id' => '9']]);
        self::assertStringContainsString('Cannot log time on a closed issue', $this->content());
    }

    public function testLogTimeForOtherUsers(): void
    {
        // dlopper (Developer) cannot log time for other users
        $this->login('dlopper');
        $this->submit('/projects/ecommerce/time_entries', ['project_id' => 'ecommerce', 'time_entry' => ['user_id' => '5', 'spent_on' => '2026-11-01', 'hours' => '1', 'activity_id' => '9']]);
        self::assertSame(422, $this->code());
        self::assertStringContainsString('User is invalid', $this->content());
        // jsmith (Manager) can
        $this->login('jsmith');
        self::assertStringContainsString('id="time_entry_user_id"', $this->get('/projects/ecommerce/time_entries/new'));
        $this->submit('/projects/ecommerce/time_entries', ['project_id' => 'ecommerce', 'time_entry' => ['user_id' => '6', 'spent_on' => '2026-11-01', 'hours' => '1', 'activity_id' => '9', 'comments' => 'for dave']]);
        self::assertSame(302, $this->code());
        $e = $this->em->getRepository(TimeEntry::class)->findOneBy(['comments' => 'for dave']);
        self::assertSame('dlopper', $e->user->login);
        self::assertSame('jsmith', $e->author->login);
    }

    public function testEditUpdateAndPermissions(): void
    {
        // dlopper has no edit permission
        $this->login('dlopper');
        $this->client->request('GET', '/time_entries/1/edit');
        self::assertSame(403, $this->code());

        $this->login('jsmith');
        self::assertStringContainsString('value="2:30"', $this->get('/time_entries/1/edit'));
        $this->submit('/time_entries/1', ['_method' => 'PATCH', 'time_entry' => ['hours' => '3', 'comments' => 'Updated']]);
        self::assertSame(302, $this->code());
        $this->em->clear();
        $e = $this->em->find(TimeEntry::class, 1);
        self::assertSame(3.0, $e->hours);
        self::assertSame('Updated', $e->comments);
        // show is API only
        $this->client->request('GET', '/time_entries/1');
        self::assertSame(406, $this->code());
    }

    public function testDestroyAndBulkUpdate(): void
    {
        $this->login('jsmith');
        $this->submit('/time_entries/bulk_update', ['ids' => ['1', '2'], 'time_entry' => ['activity_id' => '9', 'hours' => '', 'comments' => '']]);
        self::assertSame(302, $this->code());
        $this->em->clear();
        self::assertSame(9, $this->em->find(TimeEntry::class, 1)->activity->id);
        self::assertSame(9, $this->em->find(TimeEntry::class, 2)->activity->id);
        self::assertSame('Investigation', $this->em->find(TimeEntry::class, 1)->comments);

        $this->submit('/time_entries/2', ['_method' => 'DELETE']);
        self::assertSame(302, $this->code());
        self::assertFalse($this->sql('SELECT id FROM time_entries WHERE id = 2'));
        $this->submit('/time_entries/destroy', ['_method' => 'DELETE', 'ids' => ['1']]);
        self::assertSame(302, $this->code());
        self::assertSame(0, (int) $this->sql('SELECT COUNT(*) FROM time_entries'));
    }

    public function testApiCrud(): void
    {
        $data = $this->api('POST', '/time_entries.json', ['time_entry' => ['issue_id' => 13, 'hours' => '1:15', 'activity_id' => 9, 'comments' => 'api', 'spent_on' => '2026-11-03']]);
        self::assertSame(201, $this->code());
        $id = $data['time_entry']['id'];
        self::assertSame(1.25, $data['time_entry']['hours']);
        self::assertSame(['id' => 1, 'name' => 'eCommerce'], $data['time_entry']['project']);
        self::assertSame(['id' => 13], $data['time_entry']['issue']);
        self::assertStringEndsWith('/time_entries/'.$id.'.json', $this->location());

        $this->api('PUT', '/time_entries/'.$id.'.json', ['time_entry' => ['hours' => 2, 'comments' => 'changed']]);
        self::assertSame(204, $this->code());
        $data = $this->api('GET', '/time_entries/'.$id.'.json');
        self::assertSame(2.0, $data['time_entry']['hours']);
        self::assertSame('changed', $data['time_entry']['comments']);

        $data = $this->api('POST', '/time_entries.json', ['time_entry' => ['project_id' => 1, 'activity_id' => 9]]);
        self::assertSame(422, $this->code());
        self::assertContains('Hours cannot be blank', $data['errors']);

        $this->api('DELETE', '/time_entries/'.$id.'.json');
        self::assertSame(204, $this->code());
        $this->api('GET', '/time_entries/'.$id.'.json');
        self::assertSame(404, $this->code());

        $this->client->request('GET', '/time_entries/1.xml', [], [], ['HTTP_X_REDMINE_API_KEY' => self::JSMITH_KEY]);
        self::assertStringContainsString('<time_entry><id>1</id><project id="1" name="eCommerce"/><issue id="13"/>', $this->content());
    }

    public function testCsvAndAtomAndReport(): void
    {
        $this->login('admin');
        $this->client->request('GET', '/time_entries.csv');
        self::assertSame(200, $this->code());
        $csv = $this->content();
        self::assertStringContainsString("Project,Date,User,Activity,Issue,Comment,Hours\n", $csv);
        self::assertStringContainsString('eCommerce,11/07/2026,John Smith,Design,"",Meeting,1.00', $csv);
        self::assertStringContainsString("eCommerce,11/06/2026,Dave Lopper,Design,Bug #13: Cart total is wrong with discounts,Investigation,2.50\n", $csv);

        $this->client->request('GET', '/projects/ecommerce/time_entries/report.csv?criteria[]=user&columns=month');
        $csv = $this->content();
        self::assertStringContainsString('User,2026-11,Total time', $csv);
        self::assertStringContainsString('Dave Lopper,2.50,2.50', $csv);
        self::assertStringContainsString('Total time,3.50,3.50', $csv);

        $html = $this->get('/projects/ecommerce/time_entries/report?criteria[]=project&criteria[]=activity');
        self::assertStringContainsString('id="time-report"', $html);
        self::assertStringContainsString('<td class="name"><a href="/projects/ecommerce">eCommerce</a></td>', $html);

        $key = static::getContainer()->get(\App\Service\AccountService::class)->atomKey($this->user('jsmith'));
        $this->client->request('GET', '/time_entries.atom?key='.$key);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom">', $this->content());
        self::assertStringContainsString('2.50 hours (Bug #13 (In Progress): Cart total is wrong with discounts)', str_replace(['2:30'], ['2.50'], $this->content()));
    }

    public function testOwnVisibility(): void
    {
        // Developer role: own time entries only
        $this->em->getConnection()->executeStatement("UPDATE roles SET time_entries_visibility = 'own' WHERE name IN ('Developer', 'Non member', 'Anonymous')");
        $this->login('dlopper');
        $html = $this->get('/projects/ecommerce/time_entries');
        self::assertStringContainsString('id="time-entry-1"', $html);
        self::assertStringNotContainsString('id="time-entry-2"', $html);
        $this->client->request('GET', '/time_entries/2/edit');
        self::assertSame(403, $this->code());
    }

    public function testProjectActivityOverridesAndDefaultActivity(): void
    {
        $conn = $this->em->getConnection();
        // project specific override of "Design" (8) for ecommerce, and a role default activity
        $conn->executeStatement("INSERT INTO enumerations (name, position, is_default, type, active, project_id, parent_id) VALUES ('Design', 1, 0, 'TimeEntryActivity', 1, 1, 8)");
        $overrideId = (int) $conn->lastInsertId();
        $conn->executeStatement('UPDATE roles SET default_time_entry_activity_id = 9 WHERE name = ?', ['Manager']);
        $svc = static::getContainer()->get(TimeEntryService::class);
        $project = $this->em->find(Project::class, 1);
        $ids = array_map(static fn ($a) => $a->id, $svc->activities($project));
        self::assertSame([$overrideId, 9], $ids);
        self::assertSame(9, $svc->defaultActivityId($this->user('jsmith'), $project));
        self::assertSame($overrideId, $svc->activityIdForProject(8, $project));
        // activity filter matches overrides through the parent id
        $this->login('admin');
        $data = $this->api('GET', '/time_entries.json?set_filter=1&f[]=activity_id&op[activity_id]==&v[activity_id][]=8');
        self::assertSame(2, $data['total_count']);
        // a shared activity overridden in the project is not allowed anymore for new entries
        $this->login('jsmith');
        $this->submit('/projects/ecommerce/time_entries', ['project_id' => 'ecommerce', 'time_entry' => ['spent_on' => '2026-11-01', 'hours' => '1', 'activity_id' => '8']]);
        self::assertStringContainsString('Activity is not included in the list', $this->content());
        self::assertNotNull($this->em->find(TimeEntryActivity::class, $overrideId));
    }

    public function testContextMenuAndFormRefresh(): void
    {
        $this->login('jsmith');
        $html = $this->get('/time_entries/context_menu?ids[]=1&ids[]=2');
        self::assertStringContainsString('/time_entries/bulk_edit?ids', $html);
        self::assertStringContainsString('Development', $html);
        $this->submit('/time_entries/new.js', ['project_id' => 'ecommerce', 'time_entry' => ['issue_id' => '13']], 'POST', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(200, $this->code());
        self::assertStringContainsString("\$('#time_entry_activity_id').html(", $this->content());
        self::assertStringContainsString('Bug #13', $this->content());
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/time_entries/new');
        self::assertSame(302, $this->code());
        self::assertStringContainsString('/login', $this->location());
    }
}

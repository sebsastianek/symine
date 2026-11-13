<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Private issues, private notes and restricted custom fields must not leak through any output:
 * HTML pages, issue lists, REST API, Atom feeds, CSV exports, activity, search, Gantt, autocomplete, relations.
 *
 * Seeded data: #17 "Payment fails on Safari" is a private issue of checkout (author rhill, assignee jsmith);
 * journal #15 of #13 ("Started working on it", by dlopper) has private notes; custom field #3 "Budget" is
 * only visible to the Manager role (a value is added to #14 by the tests).
 */
final class PrivateDataVisibilityTest extends SecurityWebTestCase
{
    private const string PRIVATE_SUBJECT = 'Payment fails on Safari';
    private const string PRIVATE_NOTE = 'Started working on it';

    /** API key / feeds key of a user (created on demand) */
    private function token(string $login, string $action): string
    {
        $value = substr(hash('sha1', $action.$login), 0, 40);
        if (false === $this->dbOne('SELECT id FROM tokens WHERE value = ?', [$value])) {
            $this->conn()->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) SELECT id, ?, ?, datetime('now'), datetime('now') FROM users WHERE login = ?", [$action, $value, $login]);
        }

        return $value;
    }

    /** Every output listing issues, as $login (null = anonymous) */
    private function outputs(?string $login): array
    {
        $key = null === $login ? '' : $this->token($login, 'feeds');
        $api = null === $login ? [] : ['HTTP_X_REDMINE_API_KEY' => $this->token($login, 'api')];
        $this->as($login);
        $out = [];
        $pages = [
            'issue list' => '/issues?set_filter=1&f[]=status_id&op[status_id]=*&c[]=project&c[]=subject&c[]=last_notes&per_page=100',
            'project issue list' => '/projects/ecommerce/issues?set_filter=1&f[]=status_id&op[status_id]=*&c[]=subject&c[]=last_notes',
            'issue list grouped' => '/issues?set_filter=1&f[]=status_id&op[status_id]=*&group_by=project&c[]=subject',
            'csv' => '/issues.csv?set_filter=1&f[]=status_id&op[status_id]=*&c[]=subject&c[]=last_notes&c[]=description&csv[description]=1',
            'activity' => '/activity?show_issues=1&from=2027-01-01',
            'project activity' => '/projects/ecommerce/activity?show_issues=1&with_subprojects=1&from=2027-01-01',
            'search' => '/search?q=Safari&all_words=1&issues=1',
            'search notes' => '/search?q=Started+working&issues=1',
            'gantt' => '/projects/ecommerce/issues/gantt?set_filter=1&f[]=status_id&op[status_id]=*',
            'issue #13' => '/issues/13',
            'autocomplete' => '/issues/auto_complete?q=Safari',
            'autocomplete id' => '/issues/auto_complete?q=17',
            'roadmap' => '/projects/ecommerce/roadmap?completed=1&tracker_ids[]=1&tracker_ids[]=2&tracker_ids[]=3&with_subprojects=1',
            'issue report' => '/projects/ecommerce/issues/report/tracker',
        ];
        foreach ($pages as $label => $url) {
            $this->get($url);
            $out[$label] = [$this->code(), $this->body()];
        }
        $feeds = [
            'atom issues' => '/issues.atom?set_filter=1&f[]=status_id&op[status_id]=*',
            'atom issue #13' => '/issues/13.atom',
            'atom changes' => '/issues/changes.atom',
            'atom activity' => '/activity.atom?show_issues=1&with_subprojects=1',
        ];
        foreach ($feeds as $label => $url) {
            $this->client->restart();
            $this->get($url.('' === $key ? '' : '&key='.$key));
            $out[$label] = [$this->code(), $this->body()];
        }
        $apis = [
            'api issues' => '/issues.json?status_id=*&limit=100',
            'api project issues' => '/projects/ecommerce/issues.json?status_id=*&limit=100',
            'api issue #13' => '/issues/13.json?include=journals,relations,children',
            'api relations of #13' => '/issues/13/relations.json',
            'api search' => '/search.json?q=Safari',
            'api search notes' => '/search.json?q=Started+working',
        ];
        foreach ($apis as $label => $url) {
            $this->client->restart();
            $this->get($url, $api);
            $out[$label] = [$this->code(), $this->body()];
        }

        return $out;
    }

    /** @return iterable<string, array{0: ?string, 1: bool, 2: bool}> login, sees the private issue, sees the private notes */
    public static function viewers(): iterable
    {
        yield 'anonymous' => [null, false, false];
        yield 'anna (non member)' => ['anna', false, false];
        yield 'dlopper (developer of ecommerce, not of checkout)' => ['dlopper', false, true];
        yield 'rhill (reporter, author of #17)' => ['rhill', true, false];
        yield 'jsmith (manager, assignee of #17)' => ['jsmith', true, true];
        yield 'admin' => ['admin', true, true];
    }

    #[DataProvider('viewers')]
    public function testPrivateIssueAndNotesInEveryOutput(?string $login, bool $seesIssue, bool $seesNotes): void
    {
        // the activity and atom feeds list recent events only: make the seeded events recent
        $this->conn()->executeStatement("UPDATE issues SET created_on = datetime('now', '-1 day'), updated_on = datetime('now', '-1 day')");
        $this->conn()->executeStatement("UPDATE journals SET created_on = datetime('now', '-1 hour')");
        // last notes of #13 = the private note
        $this->conn()->executeStatement('UPDATE journals SET notes = ? WHERE id = 15', [self::PRIVATE_NOTE.' (private)']);

        $leaks = [];
        foreach ($this->outputs($login) as $label => [$code, $body]) {
            if ($code >= 300) {
                continue; // denied output (login redirect, 401, 403) leaks nothing
            }
            if (!$seesIssue && (str_contains($body, self::PRIVATE_SUBJECT) || preg_match('/(issue|issues)\/17\b|"id":17,|#17\b/', $body))) {
                $leaks[] = "$label shows the private issue #17";
            }
            if (!$seesNotes && str_contains($body, self::PRIVATE_NOTE)) {
                $leaks[] = "$label shows the private notes of journal #15";
            }
        }
        self::assertSame([], $leaks, 'as '.($login ?? 'anonymous'));

        if ($seesIssue) {
            $this->as($login);
            $this->get('/issues?set_filter=1&f[]=status_id&op[status_id]=*&c[]=subject');
            self::assertStringContainsString(self::PRIVATE_SUBJECT, $this->body(), 'the private issue is listed for '.$login);
        }
        if ($seesNotes) {
            $this->as($login);
            $this->get('/issues/13');
            self::assertStringContainsString(self::PRIVATE_NOTE, $this->body(), 'the private notes are shown to '.$login);
        }
    }

    /** Direct access to the private issue and its derived pages */
    public function testDirectAccessToPrivateIssue(): void
    {
        $urls = ['/issues/17', '/issues/17/edit', '/projects/checkout/issues/17/copy', '/issues/17/time_entries/new', '/issues/17.pdf', '/issues/17/relations.json'];
        foreach (['anna', 'dlopper'] as $login) {
            $this->as($login);
            foreach ($urls as $url) {
                $this->get($url, str_ends_with($url, '.json') ? ['HTTP_X_REDMINE_API_KEY' => $this->token($login, 'api')] : []);
                self::assertContains($this->code(), [403, 404], "$url as $login");
                self::assertStringNotContainsString(self::PRIVATE_SUBJECT, $this->body(), "$url as $login");
            }
        }
        $this->as(null);
        $this->get('/issues/17');
        self::assertSame('login', $this->outcome());
    }

    /** A quote of a private note (journals#new) is refused without view_private_notes */
    public function testQuotingPrivateNotes(): void
    {
        foreach (['rhill' => false, 'dlopper' => true] as $login => $allowed) {
            $this->as($login);
            $token = $this->csrf();
            $this->client->request('POST', '/issues/13/quoted', ['journal_id' => '15', '_token' => $token], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'text/javascript']);
            if ($allowed) {
                self::assertSame(200, $this->code(), $login);
                self::assertStringContainsString('Started working', $this->body());
            } else {
                self::assertStringNotContainsString('Started working', $this->body(), $login);
            }
        }
    }

    /** Watching a private issue is not possible for users who cannot see it */
    public function testCannotWatchInvisibleIssue(): void
    {
        $this->as('anna');
        $token = $this->csrf();
        $this->client->request('POST', '/watchers/watch', ['object_type' => 'issue', 'object_id' => '17', '_token' => $token]);
        self::assertContains($this->code(), [403, 404]);
        self::assertSame(0, (int) $this->dbOne("SELECT COUNT(*) FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = 17 AND user_id = 9"));
    }

    /** issues_custom_fields_visibility_test.rb: a custom field visible to some roles only */
    #[DataProvider('customFieldViewers')]
    public function testRestrictedCustomField(string $login, bool $visible): void
    {
        $this->conn()->executeStatement("INSERT INTO custom_values (customized_type, customized_id, custom_field_id, value) VALUES ('Issue', 14, 3, '1500.5')");
        $this->conn()->executeStatement('UPDATE custom_fields SET is_filter = 1 WHERE id = 3');
        // a journal detail of the field
        $this->conn()->executeStatement("INSERT INTO journals (journalized_id, journalized_type, user_id, notes, created_on, private_notes, updated_on) VALUES (14, 'Issue', 5, '', datetime('now'), 0, datetime('now'))");
        $jid = (int) $this->dbOne('SELECT MAX(id) FROM journals');
        $this->conn()->executeStatement("INSERT INTO journal_details (journal_id, property, prop_key, old_value, value) VALUES (?, 'cf', '3', '1000.25', '1500.5')", [$jid]);
        $api = ['HTTP_X_REDMINE_API_KEY' => $this->token($login, 'api')];

        $this->as($login);
        $outputs = [];
        foreach ([
            'issue page' => '/issues/14',
            'issue list' => '/projects/ecommerce/issues?set_filter=1&c[]=subject&c[]=cf_3&f[]=status_id&op[status_id]=*',
            'issue list filtered' => '/projects/ecommerce/issues?set_filter=1&c[]=subject&f[]=cf_3&op[cf_3]==&v[cf_3][]=1500.5',
            'csv' => '/projects/ecommerce/issues.csv?set_filter=1&c[]=subject&c[]=cf_3&f[]=status_id&op[status_id]=*',
            'filters' => '/queries/filter?project_id=ecommerce&type=IssueQuery&name=cf_3',
            'query form' => '/projects/ecommerce/queries/new',
        ] as $label => $url) {
            $this->get($url);
            $outputs[$label] = $this->body();
        }
        foreach (['api issue' => '/issues/14.json?include=journals', 'api list' => '/issues.json?status_id=*&cf_3=1500.5'] as $label => $url) {
            $this->client->restart();
            $this->get($url, $api);
            $outputs[$label] = $this->body();
        }
        $this->client->restart();
        $this->get('/issues/14.atom?key='.$this->token($login, 'feeds'));
        $outputs['atom'] = $this->body();

        foreach ($outputs as $label => $body) {
            $shows = str_contains($body, '1500.5') || str_contains($body, '1000.25') || str_contains($body, 'Budget');
            if ('api list' === $label) {
                // the filter on an invisible field is ignored: all issues are returned (Wishlist #14 included)
                $ids = array_column(json_decode($body, true)['issues'], 'id');
                if ($visible) {
                    self::assertSame([14], $ids, "$label as $login");
                } else {
                    self::assertContains(13, $ids, "$label as $login: the filter must be ignored");
                }
                continue;
            }
            if ('issue list filtered' === $label) {
                // a filter on an invisible field is not applied (Redmine: not an available filter)
                self::assertSame($visible, !str_contains($body, 'Cart total is wrong'), "$label as $login");
                continue;
            }
            if (\in_array($label, ['filters', 'query form', 'issue list filtered'], true) && !$visible) {
                self::assertStringNotContainsString('Budget', $body, "$label as $login");
                continue;
            }
            if (\in_array($label, ['filters', 'query form'], true)) {
                continue;
            }
            self::assertSame($visible, $shows, "$label as $login: the Budget field must ".($visible ? '' : 'not ').'be shown');
        }
    }

    public static function customFieldViewers(): iterable
    {
        yield 'jsmith (manager)' => ['jsmith', true];
        yield 'admin' => ['admin', true];
        yield 'dlopper (developer)' => ['dlopper', false];
        yield 'rhill (reporter)' => ['rhill', false];
        yield 'anna (non member)' => ['anna', false];
    }

    /** timelog_custom_fields_visibility_test.rb: time entries list hides the invisible issue custom field columns */
    public function testRestrictedCustomFieldInTimeEntries(): void
    {
        $this->conn()->executeStatement("INSERT INTO custom_values (customized_type, customized_id, custom_field_id, value) VALUES ('Issue', 13, 3, '777.5')");
        $this->conn()->executeStatement("UPDATE custom_fields SET is_for_all = 1 WHERE id = 3");
        $this->conn()->executeStatement("INSERT INTO custom_fields_trackers (custom_field_id, tracker_id) SELECT 3, 1 WHERE NOT EXISTS (SELECT 1 FROM custom_fields_trackers WHERE custom_field_id = 3 AND tracker_id = 1)");
        foreach (['jsmith' => true, 'rhill' => false] as $login => $visible) {
            $this->as($login);
            $this->get('/projects/ecommerce/time_entries?set_filter=1&c[]=spent_on&c[]=issue&c[]=issue.cf_3&c[]=hours&f[]=spent_on&op[spent_on]=*');
            self::assertSame(200, $this->code());
            self::assertSame($visible, str_contains($this->body(), '777.5'), "time entries as $login");
            $this->get('/projects/ecommerce/time_entries.csv?set_filter=1&c[]=spent_on&c[]=issue&c[]=issue.cf_3&c[]=hours&f[]=spent_on&op[spent_on]=*');
            self::assertSame($visible, str_contains($this->body(), '777.5'), "time entries CSV as $login");
        }
    }
}

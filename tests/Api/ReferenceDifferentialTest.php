<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live differential tests: the same API requests are sent to the reference Redmine (env SYMINE_REFERENCE_URL,
 * e.g. http://localhost:3300) and to Symine (seeded database migrated from that reference, same ids), and the
 * JSON payloads are compared (keys, key order, types, values; timestamps and host ignored, see ReferenceDiff).
 *
 * Identities: jsmith (API key header), admin (HTTP basic, SYMINE_REFERENCE_ADMIN, default admin:admin),
 * dlopper/anna (HTTP basic, password123), rhill (admin + X-Redmine-Switch-User), anonymous.
 *
 * Read-only requests are compared directly. Mutating requests are only sent to the reference for resources
 * created by the test itself, which are deleted afterwards (the reference is restored).
 *
 *   SYMINE_REFERENCE_URL=http://localhost:3300 bin/test --differential
 */
#[Group('differential')]
final class ReferenceDifferentialTest extends ApiTestCase
{
    private string $base;

    protected function setUp(): void
    {
        $base = getenv('SYMINE_REFERENCE_URL') ?: ($_SERVER['SYMINE_REFERENCE_URL'] ?? '');
        if ('' === $base) {
            self::markTestSkipped('Set SYMINE_REFERENCE_URL (e.g. http://localhost:3300) to run the live differential tests.');
        }
        $this->base = rtrim((string) $base, '/');
        parent::setUp();
    }

    /** @return array<string, string> server params (Symfony) for an identity */
    private function symineAuth(string $who): array
    {
        [$adminLogin, $adminPw] = explode(':', getenv('SYMINE_REFERENCE_ADMIN') ?: 'admin:admin', 2);

        return match ($who) {
            'anonymous' => [],
            'jsmith' => ['HTTP_X_REDMINE_API_KEY' => self::JSMITH_KEY],
            'admin' => self::basic($adminLogin, $adminPw),
            'rhill' => self::basic($adminLogin, $adminPw) + ['HTTP_X_REDMINE_SWITCH_USER' => 'rhill'],
            default => self::basic($who),
        };
    }

    /** @return list<string> curl headers/options for an identity */
    private function referenceAuth(string $who): array
    {
        $admin = getenv('SYMINE_REFERENCE_ADMIN') ?: 'admin:admin';
        $key = getenv('SYMINE_REFERENCE_KEY') ?: self::JSMITH_KEY;

        return match ($who) {
            'anonymous' => [],
            'jsmith' => ['header:X-Redmine-API-Key: '.$key],
            'admin' => ['basic:'.$admin],
            'rhill' => ['basic:'.$admin, 'header:X-Redmine-Switch-User: rhill'],
            default => ['basic:'.$who.':password123'],
        };
    }

    /** @return array{0: int, 1: string, 2: string} status, body, content type */
    private function reference(string $method, string $path, string $who, ?string $body = null): array
    {
        $ch = curl_init($this->base.$path);
        $headers = [];
        foreach ($this->referenceAuth($who) as $opt) {
            if (str_starts_with($opt, 'header:')) {
                $headers[] = substr($opt, 7);
            } else {
                curl_setopt($ch, \CURLOPT_USERPWD, substr($opt, 6));
            }
        }
        if (null !== $body) {
            $headers[] = 'Content-Type: '.(str_starts_with($body, '<') ? 'application/xml' : 'application/json');
            curl_setopt($ch, \CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt_array($ch, [\CURLOPT_CUSTOMREQUEST => $method, \CURLOPT_RETURNTRANSFER => true, \CURLOPT_HTTPHEADER => $headers, \CURLOPT_TIMEOUT => 60]);
        $content = curl_exec($ch);
        if (false === $content) {
            self::markTestSkipped('Reference instance not reachable: '.curl_error($ch));
        }

        return [(int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE), (string) $content, (string) curl_getinfo($ch, \CURLINFO_CONTENT_TYPE)];
    }

    /** @return array{0: int, 1: string} */
    private function symine(string $method, string $path, string $who, ?string $body = null): array
    {
        $r = $this->request($method, $path, $body, null, $this->symineAuth($who));

        return [$r->getStatusCode(), (string) $r->getContent()];
    }

    private function assertSame2(array $ref, array $sym, string $label, array $ignoreKeys = []): void
    {
        $diff = new ReferenceDiff($ignoreKeys);
        $diff->compare($ref, $sym);
        self::assertSame([], $diff->diffs, "$label differs from Redmine:\n".implode("\n", $diff->diffs));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function readOnlyRequests(): iterable
    {
        $requests = [
            // issues
            ['anonymous', '/issues.json'],
            ['anonymous', '/issues/13.json'],
            ['jsmith', '/issues.json'],
            ['jsmith', '/issues.json?status_id=*&sort=id'],
            ['jsmith', '/issues.json?status_id=closed'],
            ['jsmith', '/issues.json?project_id=ecommerce&status_id=*&sort=priority:desc,id'],
            ['jsmith', '/issues.json?status_id=*&offset=2&limit=3&sort=id'],
            ['jsmith', '/issues.json?assigned_to_id=me&status_id=*'],
            ['jsmith', '/issues.json?status_id=*&include=relations,attachments&sort=id'],
            ['jsmith', '/issues.json?cf_1=PostgreSQL&status_id=*'],
            ['jsmith', '/issues.json?parent_id=14&status_id=*&sort=id'],
            ['jsmith', '/issues.json?query_id=1'],
            ['jsmith', '/issues/13.json?include=children,attachments,relations,changesets,journals,watchers,allowed_statuses'],
            ['jsmith', '/issues/14.json?include=children,journals'],
            ['jsmith', '/issues/17.json?include=relations,journals,watchers'],
            ['jsmith', '/issues/18.json'],
            ['jsmith', '/issues/19.json?include=journals,allowed_statuses'],
            ['dlopper', '/issues/13.json?include=journals,allowed_statuses'],
            ['dlopper', '/issues.json?status_id=*&sort=id'],
            ['anna', '/issues.json?status_id=*&sort=id'],
            ['rhill', '/issues/13.json?include=journals,allowed_statuses'],
            ['rhill', '/issues.json?status_id=*&sort=id'],
            ['admin', '/issues.json?status_id=*&sort=id'],
            ['admin', '/issues/17.json?include=journals,watchers,relations'],
            ['admin', '/issues/15.json?include=relations'],
            ['jsmith', '/issues/13/relations.json'],
            ['jsmith', '/relations/1.json'],
            // projects
            ['anonymous', '/projects.json'],
            ['jsmith', '/projects.json'],
            ['admin', '/projects.json'],
            ['admin', '/projects.json?status=9'],
            ['jsmith', '/projects/ecommerce.json?include=trackers,issue_categories,enabled_modules,time_entry_activities,issue_custom_fields'],
            ['jsmith', '/projects/1.json'],
            ['jsmith', '/projects/internal.json'],
            ['jsmith', '/projects/checkout.json?include=trackers'],
            ['jsmith', '/projects/ecommerce/memberships.json'],
            ['jsmith', '/memberships/1.json'],
            ['jsmith', '/projects/ecommerce/versions.json'],
            ['jsmith', '/projects/checkout/versions.json'],
            ['jsmith', '/versions/1.json'],
            ['jsmith', '/projects/ecommerce/issue_categories.json'],
            ['jsmith', '/issue_categories/1.json'],
            // time tracking
            ['jsmith', '/time_entries.json'],
            ['jsmith', '/time_entries/1.json'],
            ['jsmith', '/projects/ecommerce/time_entries.json?user_id=me'],
            ['dlopper', '/time_entries.json'],
            ['admin', '/time_entries.json?spent_on=*&limit=1'],
            // content
            ['anonymous', '/news.json'],
            ['jsmith', '/projects/ecommerce/news.json'],
            ['jsmith', '/news/1.json?include=comments,attachments'],
            ['jsmith', '/projects/ecommerce/wiki/index.json'],
            ['jsmith', '/projects/ecommerce/wiki/Wiki.json?include=attachments'],
            ['jsmith', '/projects/ecommerce/wiki/Checkout_flow.json'],
            ['jsmith', '/projects/ecommerce/wiki/Checkout_flow/1.json'],
            ['jsmith', '/attachments/1.json'],
            ['jsmith', '/projects/ecommerce/files.json'],
            // documents API (api_test/documents_test.rb) is not in Redmine 7.0-stable (the reference container): 406 there
            // reference data
            ['jsmith', '/trackers.json'],
            ['jsmith', '/issue_statuses.json'],
            ['jsmith', '/enumerations/issue_priorities.json'],
            ['jsmith', '/enumerations/time_entry_activities.json'],
            ['jsmith', '/enumerations/document_categories.json'],
            ['admin', '/custom_fields.json'],
            ['jsmith', '/roles.json'],
            ['jsmith', '/roles/3.json'],
            ['jsmith', '/roles/1.json'],
            ['jsmith', '/queries.json'],
            ['admin', '/queries.json'],
            // users
            ['jsmith', '/users/current.json?include=memberships,groups'],
            ['jsmith', '/users/6.json'],
            ['jsmith', '/my/account.json'],
            ['admin', '/users/5.json?include=memberships,groups'],
            ['admin', '/users.json?status=1'],
            ['admin', '/users.json?group_id=10'],
            ['admin', '/groups.json'],
            ['admin', '/groups/10.json?include=users,memberships'],
            // search
            ['jsmith', '/search.json?q=wishlist'],
            ['jsmith', '/search.json?q=cart&titles_only=1'],
            ['anonymous', '/search.json?q=crash'],
            ['jsmith', '/search.json?q=crash'],
            ['jsmith', '/projects/ecommerce/search.json?q=release&news=1'],
        ];
        foreach ($requests as [$who, $path]) {
            yield "$who $path" => [$who, $path];
        }
    }

    #[DataProvider('readOnlyRequests')]
    public function testSameJsonAsRedmine(string $who, string $path): void
    {
        [$refStatus, $refBody] = $this->reference('GET', $path, $who);
        [$symStatus, $symBody] = $this->symine('GET', $path, $who);
        self::assertSame($refStatus, $symStatus, "HTTP status of GET $path as $who (Redmine body: ".mb_substr($refBody, 0, 200).')');
        if (200 !== $refStatus) {
            return;
        }
        $ref = json_decode($refBody, true);
        $sym = json_decode($symBody, true);
        self::assertIsArray($ref, "Redmine JSON of $path");
        self::assertIsArray($sym, "Symine JSON of $path: ".mb_substr($symBody, 0, 300));
        $ignore = [];
        if (isset($ref['total_count'], $sym['total_count']) && $ref['total_count'] !== $sym['total_count']) {
            // records created on the shared reference by somebody else: the total must match once they are discounted
            self::assertSame($ref['total_count'] - $this->foreignRecords($path, $who), $sym['total_count'], "total_count of $path (discounting records only present in the reference)");
            $ignore[] = 'total_count';
        }
        $this->assertSame2($ref, self::knownDifferences($sym), "GET $path as $who", $ignore);
    }

    /** Number of records of a collection that only exist in the reference (all pages) */
    private function foreignRecords(string $path, string $who): int
    {
        $full = preg_replace('/([?&])(offset|limit|page)=\d+&?/', '$1', $path);
        $full = rtrim((string) $full, '?&').(str_contains((string) $full, '?') ? '&' : '?').'limit=100';
        $root = null;
        $ids = [];
        foreach (['ref' => $this->reference('GET', $full, $who)[1], 'sym' => $this->symine('GET', $full, $who)[1]] as $side => $body) {
            $data = json_decode($body, true);
            $root ??= array_key_first(array_diff_key($data, array_flip(['total_count', 'offset', 'limit'])));
            $ids[$side] = array_column($data[$root] ?? [], 'id');
        }

        return \count(array_diff($ids['ref'], $ids['sym']));
    }

    /**
     * Differences explained by the version of the reference container (Redmine 7.0-stable) compared with the
     * Redmine source ported by Symine (reference/redmine, master): the export_issues/export_time_entries
     * permissions are added by a Redmine 7.1 data migration.
     */
    private static function knownDifferences(array $sym): array
    {
        $strip = static function (array $a) use (&$strip): array {
            foreach ($a as $k => $v) {
                if ('permissions' === $k && \is_array($v) && array_is_list($v)) {
                    $a[$k] = array_values(array_diff($v, ['export_issues', 'export_time_entries']));
                } elseif (\is_array($v)) {
                    $a[$k] = $strip($v);
                }
            }

            return $a;
        };

        return $strip($sym);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function forbiddenOrMissing(): iterable
    {
        foreach ([
            ['anonymous', '/issues/18.json'], ['anonymous', '/projects/internal.json'], ['anonymous', '/users/current.json'],
            ['anna', '/issues/17.json'], ['anna', '/projects/internal.json'], ['dlopper', '/issues/18.json'],
            ['jsmith', '/issues/999.json'], ['jsmith', '/projects/nope.json'], ['jsmith', '/users.json'],
            ['jsmith', '/custom_fields.json'], ['jsmith', '/projects/legacy.json'], ['jsmith', '/groups.json'],
            ['jsmith', '/time_entries/999.json'], ['jsmith', '/versions/999.json'], ['anonymous', '/my/account.json'],
            ['dlopper', '/projects/ecommerce/memberships.json'], ['anonymous', '/projects/internal/issues.json'],
        ] as [$who, $path]) {
            yield "$who $path" => [$who, $path];
        }
    }

    #[DataProvider('forbiddenOrMissing')]
    public function testSameErrorStatus(string $who, string $path): void
    {
        [$refStatus] = $this->reference('GET', $path, $who);
        [$symStatus] = $this->symine('GET', $path, $who);
        self::assertSame($refStatus, $symStatus, "HTTP status of GET $path as $who");
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function xmlRequests(): iterable
    {
        foreach ([
            ['jsmith', '/issues/13.xml?include=journals,relations,attachments,watchers'],
            ['jsmith', '/projects/ecommerce.xml?include=trackers,issue_categories,enabled_modules'],
            ['jsmith', '/users/current.xml'],
            ['jsmith', '/versions/1.xml'],
            ['jsmith', '/time_entries/1.xml'],
            ['jsmith', '/news/1.xml'],
            ['jsmith', '/projects/ecommerce/wiki/Checkout_flow.xml'],
            ['jsmith', '/trackers.xml'],
            ['jsmith', '/issue_statuses.xml'],
            ['jsmith', '/roles/3.xml'],
            ['admin', '/custom_fields.xml'],
            ['admin', '/groups/10.xml?include=users'],
        ] as [$who, $path]) {
            yield "$who $path" => [$who, $path];
        }
    }

    #[DataProvider('xmlRequests')]
    public function testSameXmlAsRedmine(string $who, string $path): void
    {
        [$refStatus, $refBody] = $this->reference('GET', $path, $who);
        [$symStatus, $symBody] = $this->symine('GET', $path, $who);
        self::assertSame($refStatus, $symStatus, "HTTP status of GET $path as $who");
        $ref = simplexml_load_string($refBody);
        $sym = @simplexml_load_string($symBody);
        self::assertNotFalse($sym, 'Symine XML: '.mb_substr($symBody, 0, 300));
        $symArray = ReferenceDiff::xmlToArray($sym);
        if (str_starts_with($path, '/roles/')) {
            // see knownDifferences()
            foreach ($symArray['@children'] as $i => $child) {
                if ('permissions' === $child['@name']) {
                    $symArray['@children'][$i]['@children'] = array_values(array_filter($child['@children'], static fn ($p) => !\in_array($p['@text'], ['export_issues', 'export_time_entries'], true)));
                }
            }
        }
        $this->assertSame2(ReferenceDiff::xmlToArray($ref), $symArray, "GET $path as $who (XML)");
    }

    /**
     * Mutating requests: each scenario creates a resource on both sides, compares the responses (shape, values
     * except the new id), applies the same update, compares a GET of it, then deletes it (restoring the reference).
     *
     * @return iterable<string, array{0: string, 1: string, 2: array, 3: ?array, 4: string}>
     */
    public static function createUpdateDelete(): iterable
    {
        yield 'issue' => ['/projects/ecommerce/issues.json', 'issue', ['issue' => ['tracker_id' => 1, 'subject' => 'Differential test issue', 'priority_id' => 3, 'description' => 'Created by ReferenceDifferentialTest', 'category_id' => 1, 'fixed_version_id' => 1, 'custom_fields' => [['id' => 1, 'value' => 'MySQL'], ['id' => 2, 'value' => ['Linux']]], 'start_date' => '2026-01-01', 'estimated_hours' => 2.5]],
            ['issue' => ['status_id' => 2, 'assigned_to_id' => 6, 'notes' => 'Differential update', 'done_ratio' => 20]], '/issues/%d.json?include=journals,watchers,allowed_statuses'];
        yield 'time entry' => ['/time_entries.json', 'time_entry', ['time_entry' => ['issue_id' => 13, 'hours' => 1.25, 'activity_id' => 8, 'spent_on' => '2026-01-02', 'comments' => 'Differential test']],
            ['time_entry' => ['hours' => 2, 'comments' => 'Updated']], '/time_entries/%d.json'];
        yield 'version' => ['/projects/ecommerce/versions.json', 'version', ['version' => ['name' => 'Differential 9.9', 'description' => 'tmp', 'due_date' => '2027-01-01', 'sharing' => 'tree']],
            ['version' => ['status' => 'locked', 'wiki_page_title' => 'Wiki']], '/versions/%d.json'];
        yield 'issue category' => ['/projects/ecommerce/issue_categories.json', 'issue_category', ['issue_category' => ['name' => 'Differential', 'assigned_to_id' => 6]],
            ['issue_category' => ['name' => 'Differential 2']], '/issue_categories/%d.json'];
        yield 'relation' => ['/issues/16/relations.json', 'relation', ['relation' => ['issue_to_id' => 19, 'relation_type' => 'relates']], null, '/relations/%d.json'];
        yield 'news' => ['/projects/ecommerce/news.json', 'news', ['news' => ['title' => 'Differential news', 'summary' => 'sum', 'description' => 'desc']], null, null];
    }

    #[DataProvider('createUpdateDelete')]
    public function testSameCreateUpdateDelete(string $createPath, string $root, array $payload, ?array $update, ?string $showPath): void
    {
        $body = (string) json_encode($payload);
        [$refStatus, $refBody] = $this->reference('POST', $createPath, 'jsmith', $body);
        $refData = json_decode($refBody, true);
        $refId = $refData[$root]['id'] ?? null;
        try {
            [$symStatus, $symBody] = $this->symine('POST', $createPath, 'jsmith', $body);
            self::assertSame($refStatus, $symStatus, "POST $createPath: ".mb_substr($symBody, 0, 300).' / Redmine: '.mb_substr($refBody, 0, 300));
            $symData = json_decode($symBody, true) ?? [];
            if ('news' === $root) {
                // Redmine answers 204 for news creation
                return;
            }
            $symId = $symData[$root]['id'] ?? null;
            self::assertIsInt($symId);
            self::assertIsInt($refId);
            $this->assertSame2(self::withoutIds($refData, $refId), self::withoutIds($symData, $symId), "POST $createPath");

            if (null !== $update) {
                [$refStatus] = $this->reference('PUT', \sprintf(str_replace('?', '', explode('?', (string) $showPath)[0]).'', $refId), 'jsmith', (string) json_encode($update));
                [$symStatus] = $this->symine('PUT', \sprintf(explode('?', (string) $showPath)[0], $symId), 'jsmith', (string) json_encode($update));
                self::assertSame($refStatus, $symStatus, 'PUT status');
            }
            if (null !== $showPath) {
                [, $refShow] = $this->reference('GET', \sprintf($showPath, $refId), 'jsmith');
                [, $symShow] = $this->symine('GET', \sprintf($showPath, $symId), 'jsmith');
                $this->assertSame2(self::withoutIds(json_decode($refShow, true), $refId), self::withoutIds(json_decode($symShow, true), $symId), 'GET '.$showPath.' after update');
                [$refDel] = $this->reference('DELETE', \sprintf(explode('?', $showPath)[0], $refId), 'jsmith');
                [$symDel] = $this->symine('DELETE', \sprintf(explode('?', $showPath)[0], $symId), 'jsmith');
                $refId = 204 === $refDel ? null : $refId;
                self::assertSame($refDel, $symDel, 'DELETE status');
            }
        } finally {
            $this->cleanupReference($root, $refId, $payload);
        }
    }

    /** Restores the reference: deletes what this test created there */
    private function cleanupReference(string $root, ?int $refId, array $payload): void
    {
        $paths = ['issue' => '/issues/%d.json', 'time_entry' => '/time_entries/%d.json', 'version' => '/versions/%d.json', 'issue_category' => '/issue_categories/%d.json', 'relation' => '/relations/%d.json', 'news' => '/news/%d.json'];
        if ('news' === $root && null === $refId) {
            [, $list] = $this->reference('GET', '/projects/ecommerce/news.json?limit=100', 'jsmith');
            foreach (json_decode($list, true)['news'] ?? [] as $n) {
                if ($n['title'] === $payload['news']['title']) {
                    $this->reference('DELETE', \sprintf($paths['news'], $n['id']), 'admin');
                }
            }

            return;
        }
        if (null !== $refId) {
            $this->reference('DELETE', \sprintf($paths[$root], $refId), 'admin');
        }
    }

    /**
     * Replaces the id of the created record (different on both sides) and the ids of the journals created
     * by the update (compared by position).
     */
    private static function withoutIds(mixed $data, int $id): mixed
    {
        if (!\is_array($data)) {
            return $data;
        }
        foreach ($data as $root => $record) {
            if (\is_array($record) && ($record['id'] ?? null) === $id) {
                $data[$root]['id'] = '<new id>';
                foreach ($data[$root]['journals'] ?? [] as $i => $journal) {
                    $data[$root]['journals'][$i]['id'] = '<journal>';
                }
            }
        }

        return $data;
    }

    /** Validation errors: same status and same messages */
    public static function invalidRequests(): iterable
    {
        yield 'issue without subject' => ['POST', '/projects/ecommerce/issues.json', ['issue' => ['tracker_id' => 1, 'subject' => '']]];
        yield 'issue without project' => ['POST', '/issues.json', ['issue' => ['subject' => 'x']]];
        yield 'issue invalid parent' => ['POST', '/projects/ecommerce/issues.json', ['issue' => ['tracker_id' => 1, 'subject' => 'x', 'parent_issue_id' => 999]]];
        yield 'issue invalid dates' => ['POST', '/projects/ecommerce/issues.json', ['issue' => ['tracker_id' => 1, 'subject' => 'x', 'start_date' => '2026-02-10', 'due_date' => '2026-02-01']]];
        yield 'issue unassignable user' => ['POST', '/projects/ecommerce/issues.json', ['issue' => ['tracker_id' => 1, 'subject' => 'x', 'assigned_to_id' => 9]]];
        yield 'time entry without hours' => ['POST', '/time_entries.json', ['time_entry' => ['issue_id' => 13, 'activity_id' => 8]]];
        yield 'time entry invalid issue' => ['POST', '/time_entries.json', ['time_entry' => ['issue_id' => 999, 'hours' => 1, 'activity_id' => 8]]];
        yield 'version duplicate name' => ['POST', '/projects/ecommerce/versions.json', ['version' => ['name' => '1.0']]];
        yield 'category blank' => ['POST', '/projects/ecommerce/issue_categories.json', ['issue_category' => ['name' => '']]];
        yield 'relation to itself' => ['POST', '/issues/13/relations.json', ['relation' => ['issue_to_id' => 13, 'relation_type' => 'relates']]];
        yield 'relation duplicate' => ['POST', '/issues/13/relations.json', ['relation' => ['issue_to_id' => 19, 'relation_type' => 'blocks']]];
        yield 'update issue blank subject' => ['PUT', '/issues/16.json', ['issue' => ['subject' => '']]];
        yield 'update issue invalid done ratio' => ['PUT', '/issues/16.json', ['issue' => ['done_ratio' => 150, 'start_date' => 'abc']]];
        yield 'project invalid identifier' => ['POST', '/projects.json', ['project' => ['name' => 'X', 'identifier' => 'Bad Id']], 'admin'];
        yield 'user duplicate login' => ['POST', '/users.json', ['user' => ['login' => 'jsmith', 'firstname' => 'J', 'lastname' => 'S', 'mail' => 'jsmith@example.net', 'password' => 'x']], 'admin'];
    }

    #[DataProvider('invalidRequests')]
    public function testSameValidationErrors(string $method, string $path, array $payload, string $who = 'jsmith'): void
    {
        $body = (string) json_encode($payload);
        [$refStatus, $refBody] = $this->reference($method, $path, $who, $body);
        if ($refStatus >= 200 && $refStatus < 300) {
            // restore the reference before giving up
            $created = json_decode($refBody, true) ?? [];
            $root = (string) array_key_first($created);
            if ('POST' === $method && isset($created[$root]['id'])) {
                $this->cleanupReference($root, $created[$root]['id'], $payload);
            }
            self::fail("The reference accepted $method $path: ".mb_substr($refBody, 0, 300));
        }
        [$symStatus, $symBody] = $this->symine($method, $path, $who, $body);
        self::assertSame($refStatus, $symStatus, "$method $path: ".mb_substr($symBody, 0, 300));
        if (422 === $refStatus) {
            $ref = json_decode($refBody, true)['errors'] ?? [];
            $sym = json_decode($symBody, true)['errors'] ?? null;
            self::assertIsArray($sym);
            sort($ref);
            sort($sym);
            self::assertSame($ref, $sym, "$method $path errors");
        }
    }
}

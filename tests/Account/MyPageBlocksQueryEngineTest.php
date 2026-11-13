<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Entity\IssueQuery;
use App\Entity\Project;
use App\Tests\Timelog\TimelogWebTestCase;

/**
 * My page issue blocks rendered with the query engine (IssueQuery: every filter, custom field columns,
 * sort links) and the activity block built by the full activity fetcher (MyHelper#render_*_block).
 * Expected values come from the reference Redmine on the same seeded data.
 */
final class MyPageBlocksQueryEngineTest extends TimelogWebTestCase
{
    private function blockHtml(string $html, string $block): string
    {
        $start = strpos($html, 'id="block-'.$block.'"');
        self::assertNotFalse($start, $block);
        $end = strpos($html, 'class="mypage-box"', $start + 10);

        return substr($html, $start, false === $end ? null : $end - $start);
    }

    /** @return list<int> */
    private static function issueIds(string $html): array
    {
        preg_match_all('/<tr id="issue-(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    private function savedQuery(array $filters, array $columns, array $sort, string $name = 'Wishlist search'): IssueQuery
    {
        $q = new IssueQuery();
        $q->name = $name;
        $q->userId = (int) $this->user('jsmith')->id;
        $q->visibility = IssueQuery::VISIBILITY_PUBLIC;
        $q->filters = $filters;
        $q->columnNames = $columns;
        $q->sortCriteria = $sort;
        $this->em->persist($q);
        $this->em->flush();

        return $q;
    }

    public function testSavedQueryBlockUsesAllFiltersColumnsAndSort(): void
    {
        // filters the former simplified block ignored: "any searchable text" and a custom field column
        $q = $this->savedQuery(
            ['status_id' => ['operator' => '*', 'values' => ['']], 'any_searchable' => ['operator' => '~', 'values' => ['wishlist']]],
            ['subject', 'cf_1', 'status'],
            [['id', 'asc']],
        );
        $this->login('jsmith');
        $this->submit('/my/add_block', ['block' => 'issuequery']);
        $this->submit('/my/page', ['settings' => ['issuequery' => ['query_id' => (string) $q->id]]]);
        $html = $this->blockHtml($this->get('/my/page'), 'issuequery');

        self::assertMatchesRegularExpression('#<a href="/issues\?[^"]*query_id='.$q->id.'[^"]*">Wishlist search</a>\s*\(3\)#', $html);
        self::assertSame([14, 15, 16], self::issueIds($html));
        self::assertMatchesRegularExpression('#<th class="cf_1[^"]*">#', $html);
        // sort links post the block settings (remote)
        self::assertMatchesRegularExpression('#<a title="Sort by &quot;Subject&quot;" href="/my/page\?settings%5Bissuequery%5D%5Bsort%5D=subject%2Cid" data-remote="true" rel="nofollow" data-method="post">Subject</a>#', $html);
        // column selection of the query engine (custom fields included)
        self::assertStringContainsString('name="settings[issuequery][columns][]" id="selected_settings_issuequery_columns"', $html);
        self::assertMatchesRegularExpression('#<select name="available_columns\[\]" id="available_settings_issuequery_columns"[^>]*>.*<option value="cf_7">Severity</option>#s', $html);

        // block settings override the query columns and sort
        $this->submit('/my/page', ['settings' => ['issuequery' => ['columns' => ['subject', 'priority'], 'sort' => 'id:desc']]]);
        $html = $this->blockHtml($this->get('/my/page'), 'issuequery');
        self::assertSame([16, 15, 14], self::issueIds($html));
        self::assertStringContainsString('<th class="priority">', $html);
        self::assertStringNotContainsString('<th class="cf_1', $html);
    }

    public function testProjectQueryBlockAndStandardBlocks(): void
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['identifier' => 'ecommerce']);
        $q = $this->savedQuery(['status_id' => ['operator' => 'o', 'values' => ['']], 'subject' => ['operator' => '~', 'values' => ['wishlist']]], ['subject'], [['subject', 'desc']], 'Open wishlist');
        $q->project = $project;
        $this->em->flush();
        $this->login('jsmith');
        $this->submit('/my/add_block', ['block' => 'issuequery']);
        $this->submit('/my/add_block', ['block' => 'issuesupdatedbyme']);
        $this->submit('/my/page', ['settings' => ['issuequery' => ['query_id' => (string) $q->id]]]);
        $page = $this->get('/my/page');

        $html = $this->blockHtml($page, 'issuequery');
        self::assertMatchesRegularExpression('#eCommerce \|\s*<a href="/projects/ecommerce/issues\?[^"]*">Open wishlist</a>\s*\(3\)#', $html);
        self::assertSame([15, 16, 14], self::issueIds($html)); // subject desc

        // updated_by "me" (journals with visible notes), open issues of active projects: jsmith updated
        // #14 (open) and #19 (closed)
        $html = $this->blockHtml($page, 'issuesupdatedbyme');
        self::assertMatchesRegularExpression('#Updated issues</a>\s*\(1\)#', $html);
        self::assertSame([14], self::issueIds($html));

        $html = $this->blockHtml($page, 'issuesassignedtome');
        self::assertMatchesRegularExpression('#<a href="/issues\?[^"]*assigned_to_id[^"]*">Issues assigned to me</a>\s*\(1\)#', $html);
        self::assertSame([17], self::issueIds($html));
    }

    public function testActivityBlockUsesTheActivityFetcher(): void
    {
        $this->login('jsmith');
        $this->submit('/my/add_block', ['block' => 'activity']);
        $html = $this->blockHtml($this->get('/my/page'), 'activity');
        self::assertMatchesRegularExpression('#<h3><a href="/activity\?from=\d{4}-\d\d-\d\d&amp;user_id=5">Activity</a></h3>#', $html);
        // all event types of the user (not only issues): Redmine::Activity::Fetcher(:author => user), 10 events
        preg_match_all('#<dt class="([a-z\-]+) icon icon-[a-z\-]+ ?(grouped)? ?me">#', $html, $m);
        self::assertCount(10, $m[1]);
        foreach (['message', 'news', 'wiki-page', 'time-entry', 'issue'] as $type) {
            self::assertContains($type, $m[1], $type);
        }
        foreach (['General: Hello', 'Release 0.9', 'Wiki edit: Checkout_flow (#1)', '1:00 hour (Project: eCommerce)', 'Bug #18 (New): Internal tool crash'] as $title) {
            self::assertStringContainsString('>'.$title.'</a>', $html);
        }
    }

    public function testUserProfileShowsTheFullActivity(): void
    {
        $this->login('admin', 'admin');
        $html = $this->get('/users/5');
        self::assertSame(200, $this->code());
        $right = substr($html, (int) strpos($html, '<div class="splitcontentright">'));
        self::assertMatchesRegularExpression('#<h3><a href="/activity\?from=\d{4}-\d\d-\d\d&amp;user_id=5">Activity</a></h3>#', $right);
        self::assertStringContainsString('<div id="activity">', $right);
        foreach (['General: Hello', 'Release 0.9', 'Wiki edit: Wiki (#1)', '1:00 hour (Project: eCommerce)'] as $title) {
            self::assertStringContainsString('>'.$title.'</a>', $right);
        }
        preg_match_all('#<dt class="[a-z\-]+ icon#', $right, $m);
        self::assertCount(10, $m[0]);
        self::assertMatchesRegularExpression('#<p class="other-formats hide-when-print">Also available in: <span><a class="atom" rel="nofollow" href="/activity\.atom\?(key=[0-9a-f]+&amp;)?user_id=5">Atom</a></span>#', $right);

        // a user without events: no activity section
        $this->em->clear();
        $html = $this->get('/users/'.$this->user('anna')->id);
        self::assertStringNotContainsString('<div id="activity">', $html);
    }
}

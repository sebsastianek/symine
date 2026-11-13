<?php

declare(strict_types=1);

namespace App\Tests\Query;

use App\Entity\Project;
use App\Query\IssueQuery;
use App\Query\QueryFactory;
use App\Tests\Integration\SymineTestCase;

/**
 * "Any searchable text" filter (IssueQuery#sql_for_any_searchable_field): issue ids found by the search engine
 * (Redmine::Search::Fetcher: subject, description, visible journal notes, searchable custom fields) in the selected
 * and visible projects. Expected ids were recorded on the reference Redmine with the same data and users.
 */
final class AnySearchableFilterTest extends SymineTestCase
{
    /** @return list<int> */
    private function ids(string $login, array $params, ?string $project = null): array
    {
        $this->actAs($this->user($login));
        $p = null === $project ? null : $this->em->getRepository(Project::class)->findOneBy(['identifier' => $project]);
        /** @var IssueQuery $q */
        $q = $this->service(QueryFactory::class)->issueQuery($p);
        $q->buildFromParams(['set_filter' => '1'] + $params);
        self::assertTrue($q->isValid());
        $ids = $q->issueIds();
        sort($ids);

        return $ids;
    }

    private static function search(string $operator, string $text, array $more = []): array
    {
        return array_merge_recursive(['f' => ['status_id', 'any_searchable'], 'op' => ['status_id' => '*', 'any_searchable' => $operator], 'v' => ['status_id' => [''], 'any_searchable' => [$text]]], $more);
    }

    public function testOperatorsAndTokens(): void
    {
        foreach (['admin', 'jsmith', 'dlopper'] as $login) {
            // ~ : all words
            self::assertSame([16], $this->ids($login, self::search('~', 'wishlist emails')), $login);
            // *~ : any word
            self::assertSame([14, 15, 16, 19], $this->ids($login, self::search('*~', 'wishlist login')), $login);
            // a quoted phrase is one token
            self::assertSame([], $this->ids($login, self::search('~', '"item apply"')), $login);
            // journal notes
            self::assertSame([19], $this->ids($login, self::search('~', 'resetting')), $login);
            // tokens of one character are ignored: no token => no match
            self::assertSame([], $this->ids($login, self::search('~', 'a')), $login);
        }
    }

    public function testNegationIsLimitedToVisibleIssues(): void
    {
        // !~ : issues that do not match (among the visible ones)
        self::assertSame([13, 17, 18, 19], $this->ids('admin', self::search('!~', 'wishlist')));
        self::assertSame([13, 17, 18, 19], $this->ids('jsmith', self::search('!~', 'wishlist')));
        self::assertSame([13, 19], $this->ids('dlopper', self::search('!~', 'wishlist')));
    }

    public function testOpenIssuesOnlyWhenTheStatusFilterIsOpen(): void
    {
        $params = ['f' => ['status_id', 'any_searchable'], 'op' => ['status_id' => 'o', 'any_searchable' => '~'], 'v' => ['status_id' => [''], 'any_searchable' => ['resetting']]];
        self::assertSame([], $this->ids('admin', $params));
    }

    public function testProjectScope(): void
    {
        // in a project: its (sub)projects only
        self::assertSame([], $this->ids('admin', self::search('~', 'crash'), 'ecommerce'));
        self::assertSame([18], $this->ids('admin', self::search('~', 'crash')));
        // project_id filter: "mine", and a negated list
        $mine = ['f' => ['project_id'], 'op' => ['project_id' => '='], 'v' => ['project_id' => ['mine']]];
        self::assertSame([], $this->ids('admin', self::search('*~', 'crash wishlist safari', $mine)));
        self::assertSame([14, 15, 16, 17, 18], $this->ids('jsmith', self::search('*~', 'crash wishlist safari', $mine)));
        self::assertSame([14, 15, 16], $this->ids('dlopper', self::search('*~', 'crash wishlist safari', $mine)));
        $notEcommerce = ['f' => ['project_id'], 'op' => ['project_id' => '!'], 'v' => ['project_id' => ['1']]];
        self::assertSame([17, 18], $this->ids('jsmith', self::search('*~', 'crash wishlist safari', $notEcommerce)));
        self::assertSame([], $this->ids('dlopper', self::search('*~', 'crash wishlist safari', $notEcommerce)));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Query;

use App\Entity\IssueQuery as IssueQueryRecord;
use App\Entity\Project;
use App\Query\AbstractQuery;
use App\Query\IssueQuery;
use App\Query\QueryFactory;
use App\Query\SortCriteria;
use App\Query\Tokenizer;
use App\Tests\Integration\SymineTestCase;

/** Query engine behaviour (port of Query / IssueQuery) on the seeded database. */
final class IssueQueryTest extends SymineTestCase
{
    private function query(?string $login = 'admin', ?string $project = null, array $params = []): IssueQuery
    {
        if (null !== $login) {
            $this->actAs($this->user($login));
        }
        $p = null === $project ? null : $this->em->getRepository(Project::class)->findOneBy(['identifier' => $project]);
        $q = $this->service(QueryFactory::class)->issueQuery($p);
        $q->buildFromParams($params);

        return $q;
    }

    public function testSortCriteria(): void
    {
        $c = new SortCriteria('priority:desc,id,subject:asc,status');
        self::assertSame([['priority', 'desc'], ['id', 'asc'], ['subject', 'asc']], $c->toArray());
        self::assertSame('priority:desc,id,subject', $c->toParam());
        self::assertSame('status,priority:desc,id', $c->add('status', 'asc')->toParam());
        self::assertSame(['issues.id DESC'], (new SortCriteria([['id', 'desc']]))->sortClause(['id' => ['issues.id']]));
        self::assertSame(['issues.root_id ASC', 'issues.lft ASC'], (new SortCriteria('parent'))->sortClause(['parent' => ['issues.root_id', 'issues.lft ASC']]));
        self::assertSame([['a', 'desc']], (new SortCriteria(['0' => ['a', 'desc'], '1' => ['', '']]))->toArray());
    }

    public function testTokenizerAndHours(): void
    {
        self::assertSame(['hello', 'bye bye'], (new Tokenizer('hello "bye bye" a'))->tokens());
        self::assertTrue((new Tokenizer('aa bb cc dd ee ff'))->isTruncated());
        self::assertSame(1.5, AbstractQuery::toHours('1,5'));
        self::assertSame(2.5, AbstractQuery::toHours('2:30'));
        self::assertSame(2.5, AbstractQuery::toHours('2h30'));
        self::assertSame(0.5, AbstractQuery::toHours('30m'));
        self::assertNull(AbstractQuery::toHours('abc'));
    }

    public function testDefaultsAndShortFilters(): void
    {
        $q = $this->query('admin');
        self::assertSame(['status_id' => ['operator' => 'o', 'values' => ['']]], $q->getFilters());
        self::assertSame(['project', 'tracker', 'status', 'priority', 'subject', 'assigned_to', 'updated_on'], $q->defaultColumnsNames());
        self::assertSame(['id', 'project', 'tracker', 'status', 'priority', 'subject', 'assigned_to', 'updated_on'], array_map(static fn ($c) => $c->name, $q->columns()));
        self::assertSame('id:desc', $q->sortCriteria()->toParam());

        $q = $this->query('admin', null, ['status_id' => '*', 'assigned_to_id' => '!*', 'subject' => '~wish']);
        self::assertSame(['operator' => '!*', 'values' => ['']], $q->getFilters()['assigned_to_id']);
        self::assertSame(['operator' => '~', 'values' => ['wish']], $q->getFilters()['subject']);
        self::assertSame([16, 15, 14], $q->issueIds());

        // f[]/op[]/v[] replace the default filters
        $q = $this->query('admin', null, ['set_filter' => '1', 'f' => ['tracker_id', ''], 'op' => ['tracker_id' => '='], 'v' => ['tracker_id' => ['1']]]);
        self::assertSame(['tracker_id'], array_keys($q->getFilters()));
        self::assertSame([18, 17, 13], $q->issueIds(), 'closed Bug #19 included only if tracker matches: #19 is a Support');
    }

    public function testValidation(): void
    {
        $q = $this->query('admin', null, ['set_filter' => '1', 'f' => ['subject', 'done_ratio', 'start_date'], 'op' => ['subject' => '~', 'done_ratio' => '=', 'start_date' => '='], 'v' => ['done_ratio' => ['abc'], 'start_date' => ['2026-13-45']]]);
        self::assertFalse($q->isValid());
        self::assertContains('Subject cannot be blank', $q->errors);
        self::assertContains('% Done is invalid', $q->errors);
        self::assertContains('Start date is invalid', $q->errors);
        $q = $this->query('admin');
        $q->record->name = '';
        self::assertFalse($q->isValid(true));
    }

    public function testProjectScopeAndSubprojects(): void
    {
        $q = $this->query('admin', 'ecommerce', ['status_id' => '*']);
        self::assertSame([19, 17, 16, 15, 14, 13], $q->issueIds(), 'subprojects included');
        self::assertArrayHasKey('subproject_id', $q->availableFilters());
        self::assertArrayNotHasKey('project_id', $q->availableFilters());
        $q = $this->query('admin', 'ecommerce', ['status_id' => '*', 'subproject_id' => '!*']);
        self::assertSame([19, 16, 15, 14, 13], $q->issueIds());
        $q = $this->query('admin', 'checkout', ['status_id' => '*']);
        self::assertSame([17], $q->issueIds());
    }

    public function testRelativeDateOperatorsRun(): void
    {
        foreach (['t', 'ld', 'nd', 'w', 'lw', 'l2w', 'nw', 'm', 'lm', 'nm', 'y', '>t-', '<t-', '><t-', 't-', '>t+', '<t+', '><t+', 't+'] as $op) {
            $value = str_contains($op, 't-') || str_contains($op, 't+') ? ['3'] : [''];
            $q = $this->query('jsmith', null, ['set_filter' => '1', 'f' => ['due_date', 'created_on'], 'op' => ['due_date' => $op, 'created_on' => '*'], 'v' => ['due_date' => $value]]);
            self::assertTrue($q->isValid(), $op);
            self::assertIsInt($q->issueCount(), $op);
        }
        $today = new \DateTimeImmutable('today');
        $q = $this->query('admin', null, ['status_id' => '*', 'created_on' => '>t-'.max(0, (int) $today->diff(new \DateTimeImmutable('2026-11-07'))->days + 1)]);
        self::assertCount(7, $q->issueIds());
    }

    public function testGroupingAndTotals(): void
    {
        $q = $this->query('admin', 'ecommerce', ['status_id' => '*', 'group_by' => 'status', 't' => ['estimated_hours', 'spent_hours', 'estimated_remaining_hours']]);
        self::assertTrue($q->isGrouped());
        self::assertSame(['1' => 4, '2' => 1, '5' => 1], $q->resultCountByGroup());
        self::assertSame([17, 16, 15, 14, 13, 19], $q->issueIds());
        $totals = [];
        foreach ($q->totals() as [$c, $t]) {
            $totals[$c->name] = $t;
        }
        self::assertEqualsWithDelta(14.0, $totals['estimated_hours'], 0.001);
        self::assertEqualsWithDelta(2.5, $totals['spent_hours'], 0.001);
        self::assertEqualsWithDelta(9.6, $totals['estimated_remaining_hours'], 0.001);
        self::assertEqualsWithDelta(8.0, $q->totalByGroupFor('estimated_hours')['2'], 0.001);

        $q = $this->query('admin', null, ['status_id' => '*', 'group_by' => 'cf_1']);
        // issues whose tracker does not use the custom field are excluded when grouping by it (like Redmine)
        self::assertSame(['' => 5, 'PostgreSQL' => 1], $q->resultCountByGroup());
        $q = $this->query('admin', null, ['status_id' => '*', 'group_by' => 'updated_on']);
        self::assertFalse($q->isGrouped(), 'timestamp grouping is not supported on SQLite (like Redmine)');
    }

    public function testColumnValues(): void
    {
        $q = $this->query('admin', null, ['status_id' => '*', 'c' => ['spent_hours', 'total_spent_hours', 'relations', 'last_notes', 'last_updated_by', 'cf_2', 'total_estimated_hours', 'watcher_users', 'attachments']]);
        $issues = $q->issues();
        $byId = [];
        foreach ($issues as $i) {
            $byId[$i->id] = $i;
        }
        self::assertSame(2.5, $q->columnValue($q->findColumn('spent_hours'), $byId[13]));
        self::assertSame('Started working on it', $q->columnValue($q->findColumn('last_notes'), $byId[13]));
        self::assertCount(2, $q->columnValue($q->findColumn('relations'), $byId[13]));
        self::assertSame(['Linux', 'macOS'], $q->columnValue($q->findColumn('cf_2'), $byId[13])['value']);
        self::assertSame(6.0, $q->columnValue($q->findColumn('total_estimated_hours'), $byId[14]));
        self::assertCount(2, $q->columnValue($q->findColumn('watcher_users'), $byId[13]));
        self::assertCount(1, $q->columnValue($q->findColumn('attachments'), $byId[13]));
        self::assertSame('jsmith', $q->columnValue($q->findColumn('last_updated_by'), $byId[19])->login);
    }

    public function testSavedQueryAndVisibility(): void
    {
        $this->actAs($this->user('jsmith'));
        $factory = $this->service(QueryFactory::class);
        $record = $this->em->find(IssueQueryRecord::class, 8); // "Open bugs" on eCommerce
        $project = $this->em->getRepository(Project::class)->findOneBy(['identifier' => 'ecommerce']);
        $q = $factory->wrap($record, $project);
        self::assertFalse($q->isNew());
        self::assertSame(['id', 'tracker', 'status', 'subject', 'assigned_to', 'updated_on'], array_map(static fn ($c) => $c->name, $q->columns()));
        self::assertSame('priority:desc,id:desc', $q->sortCriteria()->toParam());
        self::assertSame([17, 13], $q->issueIds(), 'includes the private issue of the Checkout subproject assigned to jsmith');
        self::assertTrue($q->isVisibleBy());
        self::assertTrue($q->isEditableBy(), 'jsmith is Manager (manage_public_queries) on eCommerce');
        self::assertFalse($q->isEditableBy($this->user('dlopper')));
        self::assertFalse($factory->wrap($this->em->find(IssueQueryRecord::class, 1))->isEditableBy(), 'global public queries: admin only');

        $names = array_map(static fn ($r) => $r->name, $factory->visibleQueries('IssueQuery', $project));
        self::assertSame(['Issues assigned to me', 'Open bugs', 'Reported issues', 'Updated issues', 'Watched issues'], $names);
        self::assertNotContains('Open bugs', array_map(static fn ($r) => $r->name, $factory->visibleQueries('IssueQuery', null)));

        // a private query is only visible by its owner
        $private = new IssueQueryRecord();
        $private->name = 'Mine';
        $private->userId = (int) $this->user('dlopper')->id;
        $private->visibility = 0;
        $this->em->persist($private);
        $this->em->flush();
        self::assertNotContains('Mine', array_map(static fn ($r) => $r->name, $factory->visibleQueries('IssueQuery', null)));
        $this->actAs($this->user('dlopper'));
        self::assertContains('Mine', array_map(static fn ($r) => $r->name, $factory->visibleQueries('IssueQuery', null)));
    }

    public function testAvailableFiltersJson(): void
    {
        $q = $this->query('jsmith', 'ecommerce');
        $json = $q->availableFiltersAsJson();
        self::assertSame(['type' => 'list_status', 'name' => 'Status', 'remote' => true, 'values' => [['New', '1'], ['In Progress', '2'], ['Resolved', '3'], ['Feedback', '4'], ['Closed', '5'], ['Rejected', '6']]], $json['status_id']);
        self::assertSame(['type' => 'list', 'name' => 'Author', 'remote' => true], $json['author_id']);
        self::assertSame(['type' => 'date', 'name' => "Target version's Due date", 'values' => null], $json['fixed_version.due_date']);
        self::assertSame(['status_id', 'tracker_id', 'priority_id', 'author_id', 'author.group', 'author.role', 'assigned_to_id', 'member_of_group', 'assigned_to_role', 'fixed_version_id', 'fixed_version.due_date', 'fixed_version.status', 'category_id', 'subject', 'description', 'notes', 'created_on', 'updated_on', 'closed_on', 'start_date', 'due_date', 'estimated_hours', 'spent_time', 'done_ratio', 'is_private', 'attachment', 'attachment_description', 'watcher_id', 'updated_by', 'last_updated_by', 'subproject_id', 'project.status', 'cf_1', 'relates', 'duplicates', 'duplicated', 'blocks', 'blocked', 'precedes', 'follows', 'copied_to', 'copied_from', 'parent_id', 'child_id', 'issue_id', 'any_searchable'], array_keys($json));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Plugin\Kanban;

use App\Entity\Issue;
use App\Entity\Journal;
use Symine\Kanban\Board\BoardFactory;
use Symine\Kanban\Card\CardQuery;
use Symine\Kanban\Entity\Board;

/** Cards: what is on a board, moving cards within the workflow, ordering, swimlanes, adding and filtering */
final class CardsTest extends KanbanWebTestCase
{
    private function moveUrl(Board $board, int $issueId): string
    {
        return '/kanban/boards/'.$board->id.'/cards/'.$issueId.'/move';
    }

    public function testBoardShowsTheIssuesOfItsProjectsToThoseWhoMaySeeThem(): void
    {
        $this->enableKanban();
        $board = $this->board();

        $this->loginAs('jsmith');
        $crawler = $this->get('/kanban/boards/'.$board->id);
        $this->assertStatus(200);
        self::assertSame('Delivery', trim($crawler->filter('h2.kanban-title')->text()));
        self::assertSame(['4', '1', '0', '0', '1', '0'], $crawler->filter('.kanban-head .kanban-count-value')->each(static fn ($n) => $n->text()));
        self::assertCount(1, $crawler->filter('#kanban-card-17 .badge-private'), 'the private issue of the subproject, visible to its assignee');
        self::assertStringContainsString('Checkout', $crawler->filter('#kanban-card-17 .kanban-card-project')->text(), 'several projects: cards say which');
        self::assertCount(0, $crawler->filter('#kanban-card-18'), 'the issue of another project');
        self::assertCount(1, $crawler->filter('#kanban-card-13 a.kanban-card-subject[href="/issues/13"]'));
        self::assertCount(1, $crawler->filter('#kanban-card-13.kanban-card[data-movable]'));
        self::assertCount(1, $crawler->filter('#kanban-card-19.closed'));

        // without the subprojects
        $this->change($board, static function (Board $b): void { $b->includeSubprojects = false; });
        self::assertSame([14, 15, 16], $this->cardsByColumn('/kanban/boards/'.$board->id)['New']);
        $this->change($board, static function (Board $b): void { $b->includeSubprojects = true; });

        // a developer does not see the private issue
        $this->loginAs('dlopper');
        self::assertSame([14, 15, 16], $this->cardsByColumn('/kanban/boards/'.$board->id)['New']);

        // only some trackers
        $this->change($board, static function (Board $b): void { $b->trackerIds = [1]; });
        $this->loginAs('jsmith');
        self::assertSame(['New' => [17], 'In Progress' => [13], 'Resolved' => [], 'Feedback' => [], 'Closed' => [], 'Rejected' => []], $this->cardsByColumn('/kanban/boards/'.$board->id));
    }

    /** The subtasks of the cards of a page come in one query: the rules of a card ask each of them whether it has any */
    public function testCardsComeWithTheirSubtasksLoaded(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->em->clear();
        $board = $this->em->find(Board::class, $board->id);
        $statusIds = array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT id FROM issue_statuses'));
        $cards = self::getContainer()->get(CardQuery::class)->cards($board, $statusIds, null, 100, 0, $this->user('admin'));
        self::assertGreaterThan(3, \count($cards));
        foreach ($cards as $card) {
            self::assertTrue($card->children->isInitialized(), '#'.$card->id);
        }
        $parents = array_filter($cards, static fn (Issue $i) => !$i->isLeaf());
        self::assertNotSame([], $parents);
    }

    public function testClosedIssuesLeaveTheBoardAfterAWhile(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->loginAs('jsmith');
        self::assertSame([19], $this->cardsByColumn('/kanban/boards/'.$board->id)['Closed'], 'closed a week ago, kept for 14 days');

        $this->setOptions($board, ['closed_days' => 3]);
        self::assertSame([], $this->cardsByColumn('/kanban/boards/'.$board->id)['Closed']);

        $this->setOptions($board, ['closed_days' => 0]);
        self::assertSame([19], $this->cardsByColumn('/kanban/boards/'.$board->id)['Closed'], '0: for ever');
    }

    public function testCardsKnowWhereTheWorkflowLetsThemGo(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $column = fn (int $status) => (string) $this->columnFor($board, $status)->id;

        // a manager may do every transition
        $this->loginAs('jsmith');
        $crawler = $this->get('/kanban/boards/'.$board->id);
        $moves = json_decode((string) $crawler->filter('#kanban-card-16')->attr('data-moves'), true);
        self::assertSame([$column(1) => [], $column(2) => [2], $column(3) => [3], $column(4) => [4], $column(5) => [5], $column(6) => [6]], $moves);
        // ... except closing an issue whose subtasks are open, as on the issue form
        $moves = json_decode((string) $crawler->filter('#kanban-card-14')->attr('data-moves'), true);
        self::assertSame([$column(1), $column(2), $column(3), $column(4)], array_map('strval', array_keys($moves)));

        // a developer: never to Rejected
        $this->loginAs('dlopper');
        $moves = json_decode((string) $this->get('/kanban/boards/'.$board->id)->filter('#kanban-card-16')->attr('data-moves'), true);
        self::assertArrayHasKey($column(2), $moves);
        self::assertArrayNotHasKey($column(6), $moves);

        // whoever it is, the card offers exactly what the workflow gives them
        foreach (['jsmith', 'dlopper', 'rhill'] as $login) {
            $this->loginAs($login);
            $crawler = $this->get('/kanban/boards/'.$board->id);
            foreach ([13, 15, 16, 19] as $issueId) {
                $moves = json_decode((string) $crawler->filter('#kanban-card-'.$issueId)->attr('data-moves'), true);
                $expected = [(int) $column($this->issue($issueId)->status->id), ...array_map(static fn (int $s) => (int) $column($s), $this->allowedStatusIds($issueId, $login))];
                sort($expected);
                $actual = array_map('intval', array_keys($moves));
                sort($actual);
                self::assertSame($expected, $actual, "$login #$issueId");
            }
        }
        self::assertNotSame($this->allowedStatusIds(16, 'jsmith'), $this->allowedStatusIds(16, 'rhill'), 'a reporter may do less than a manager');
    }

    public function testMovingACardChangesTheStatusLikeTheIssueForm(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->loginAs('jsmith');
        $journals = fn (int $id) => (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM journals WHERE journalized_type = 'Issue' AND journalized_id = ?", [$id]);
        $before = $journals(14);

        $json = $this->postJson($this->moveUrl($board, 14), ['column_id' => $this->columnFor($board, 2)->id]);
        $this->assertStatus(200);
        self::assertSame(2, $this->issue(14)->status->id);
        self::assertSame($before + 1, $journals(14), 'journalized like any other change');
        $detail = $this->em->getConnection()->fetchAssociative("SELECT d.prop_key, d.old_value, d.value, j.user_id FROM journal_details d JOIN journals j ON j.id = d.journal_id WHERE j.journalized_id = 14 ORDER BY d.id DESC LIMIT 1");
        self::assertSame(['prop_key' => 'status_id', 'old_value' => '1', 'value' => '2', 'user_id' => $this->user('jsmith')->id], $detail);

        self::assertStringContainsString('id="kanban-card-14"', $json['card']);
        self::assertStringContainsString('data-column="'.$this->columnFor($board, 2)->id.'"', $json['card']);
        self::assertSame(3, $json['columns'][$this->columnFor($board, 1)->id]['count']);
        self::assertSame(2, $json['columns'][$this->columnFor($board, 2)->id]['count']);
        self::assertNotEmpty($json['version']);
        self::assertSame([13, 14], $this->cardsByColumn('/kanban/boards/'.$board->id)['In Progress']);
    }

    public function testMovesTheWorkflowForbidsAreRefused(): void
    {
        $this->enableKanban();
        $board = $this->board();

        // a developer cannot reject
        $this->loginAs('dlopper');
        $json = $this->postJson($this->moveUrl($board, 14), ['column_id' => $this->columnFor($board, 6)->id]);
        $this->assertStatus(403);
        self::assertSame(['The workflow does not let you move #14 to "Rejected"'], $json['errors']);
        self::assertSame(1, $this->issue(14)->status->id);

        // nor take a status the column does not hold
        $this->postJson($this->moveUrl($board, 14), ['column_id' => $this->columnFor($board, 2)->id, 'status_id' => 6]);
        $this->assertStatus(200);
        self::assertSame(2, $this->issue(14)->status->id, 'the status of the column, not the one asked for');

        // a reporter: whatever the workflow does not give them is refused
        $this->loginAs('rhill');
        $allowed = $this->allowedStatusIds(15, 'rhill');
        foreach (array_diff([2, 3, 4, 5, 6], $allowed) as $forbidden) {
            $this->postJson($this->moveUrl($board, 15), ['column_id' => $this->columnFor($board, $forbidden)->id]);
            $this->assertStatus(403, "status $forbidden");
        }
        self::assertSame(1, $this->issue(15)->status->id);
        self::assertNotSame([], array_diff([2, 3, 4, 5, 6], $allowed));

        // cards that are not on the board, columns that do not exist
        $this->loginAs('jsmith');
        $this->postJson($this->moveUrl($board, 18), ['column_id' => $this->columnFor($board, 2)->id]);
        $this->assertStatus(404);
        self::assertSame(1, $this->issue(18)->status->id);
        $this->postJson($this->moveUrl($board, 16), ['column_id' => 99999]);
        $this->assertStatus(422);
        $this->loginAs('dlopper');
        $this->postJson($this->moveUrl($board, 17), ['column_id' => $this->columnFor($board, 2)->id]);
        $this->assertStatus(404, 'a private issue the user cannot see');

        // the request must carry the CSRF token, and visitors cannot move anything
        $this->client->request('POST', $this->moveUrl($board, 16), ['column_id' => $this->columnFor($board, 2)->id]);
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->issue(16)->status->id);
    }

    public function testAColumnWithSeveralStatuses(): void
    {
        $this->enableKanban();
        $board = $this->board([], BoardFactory::TEMPLATE_SIMPLE);
        $doing = $this->columnFor($board, 2);
        self::assertSame([2, 3, 4], $doing->statusIds());

        $this->loginAs('jsmith');
        $moves = json_decode((string) $this->get('/kanban/boards/'.$board->id)->filter('#kanban-card-14')->attr('data-moves'), true);
        self::assertSame([2, 3, 4], $moves[$doing->id], 'the page offers the choice');

        // no choice: the first status of the column the workflow allows
        $this->postJson($this->moveUrl($board, 14), ['column_id' => $doing->id]);
        self::assertSame(2, $this->issue(14)->status->id);
        // a choice
        $this->postJson($this->moveUrl($board, 15), ['column_id' => $doing->id, 'status_id' => 4]);
        self::assertSame(4, $this->issue(15)->status->id);
        // within the column nothing changes: only the place
        $before = $this->issue(15)->updatedOn;
        $this->postJson($this->moveUrl($board, 15), ['column_id' => $doing->id, 'before_id' => 13]);
        $this->assertStatus(200);
        self::assertSame(4, $this->issue(15)->status->id);
        self::assertEquals($before, $this->issue(15)->updatedOn);
    }

    public function testCardsKeepThePlaceTheyWereGiven(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $new = $this->columnFor($board, 1);
        $query = self::getContainer()->get(CardQuery::class);
        $order = fn () => $query->orderedIds($this->em->find(Board::class, $board->id), [1], $this->user('jsmith'));
        self::assertSame([14, 15, 16, 17], $order(), 'never ordered: oldest first');

        $this->loginAs('jsmith');
        $this->postJson($this->moveUrl($board, 16), ['column_id' => $new->id, 'before_id' => 14]);
        self::assertSame([16, 14, 15, 17], $order());
        $this->postJson($this->moveUrl($board, 14), ['column_id' => $new->id]);
        self::assertSame([16, 15, 17, 14], $order(), 'no card to go before: at the end');
        $this->postJson($this->moveUrl($board, 17), ['column_id' => $new->id, 'before_id' => 15]);
        self::assertSame([16, 17, 15, 14], $order());
        // between two cards, again and again: gaps run out and the column is renumbered
        for ($i = 0; $i < 14; ++$i) {
            $this->postJson($this->moveUrl($board, 0 === $i % 2 ? 14 : 15), ['column_id' => $new->id, 'before_id' => 17]);
        }
        self::assertSame([16, 14, 15, 17], $order(), 'the last one moved sits right before #17');
        self::assertSame([16, 14, 15, 17], $this->cardsByColumn('/kanban/boards/'.$board->id)['New']);

        // a card arriving from another column takes the place it was dropped at
        $this->postJson($this->moveUrl($board, 13), ['column_id' => $new->id, 'before_id' => 15]);
        self::assertSame([16, 14, 13, 15, 17], $order());

        // another board has its own order
        $other = $this->board(['name' => 'Other']);
        self::assertSame([13, 14, 15, 16, 17], $query->orderedIds($other, [1], $this->user('jsmith')));

        // boards sorted otherwise ignore the places
        $this->setOptions($board, ['sort' => 'priority']);
        self::assertSame([17, 14, 16, 13, 15], $this->cardsByColumn('/kanban/boards/'.$board->id)['New'], 'Immediate, Urgent, Urgent, High, Low');
    }

    public function testSwimlanes(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->setOptions($board, ['swimlane' => 'assignee']);

        $this->loginAs('jsmith');
        $crawler = $this->get('/kanban/boards/'.$board->id);
        self::assertSame(['Developers', 'John Smith', 'Unassigned'], $crawler->filter('.kanban-lane .kanban-lane-name')->each(static fn ($n) => $n->text()), 'the lane without assignee comes last');
        $jsmith = (string) $this->user('jsmith')->id;
        self::assertCount(1, $crawler->filter('.kanban-lane[data-lane="'.$jsmith.'"] #kanban-card-17'));
        self::assertCount(4, $crawler->filter('.kanban-lane[data-lane="0"] .kanban-card'));
        self::assertSame('1', $crawler->filter('#kanban')->attr('data-lane-movable'));

        // dropped in the lane of somebody: assigned to them, in the same request as the status
        $this->postJson($this->moveUrl($board, 14), ['column_id' => $this->columnFor($board, 2)->id, 'lane' => $jsmith]);
        $this->assertStatus(200);
        $issue = $this->issue(14);
        self::assertSame(2, $issue->status->id);
        self::assertSame((int) $jsmith, $issue->assignedTo?->id);
        // dropped in the lane without assignee
        $this->postJson($this->moveUrl($board, 14), ['column_id' => $this->columnFor($board, 2)->id, 'lane' => '0']);
        self::assertNull($this->issue(14)->assignedTo);
        // somebody who cannot be assigned
        $json = $this->postJson($this->moveUrl($board, 14), ['column_id' => $this->columnFor($board, 2)->id, 'lane' => (string) $this->user('anna')->id]);
        $this->assertStatus(422);
        self::assertNotEmpty($json['errors']);
        self::assertNull($this->issue(14)->assignedTo);

        // lanes that only group (project, tracker): the lane is ignored
        $this->setOptions($board, ['swimlane' => 'tracker']);
        $crawler = $this->get('/kanban/boards/'.$board->id);
        self::assertSame(['Bug', 'Feature', 'Support'], $crawler->filter('.kanban-lane .kanban-lane-name')->each(static fn ($n) => $n->text()));
        self::assertSame('0', $crawler->filter('#kanban')->attr('data-lane-movable'));
        $this->postJson($this->moveUrl($board, 14), ['column_id' => $this->columnFor($board, 3)->id, 'lane' => '1']);
        self::assertSame(2, $this->issue(14)->tracker->id);

        $this->setOptions($board, ['swimlane' => 'priority']);
        self::assertSame(['Immediate', 'Urgent', 'High', 'Normal', 'Low'], $this->get('/kanban/boards/'.$board->id)->filter('.kanban-lane .kanban-lane-name')->each(static fn ($n) => $n->text()));
        $this->postJson($this->moveUrl($board, 15), ['column_id' => $this->columnFor($board, 1)->id, 'lane' => '5']);
        self::assertSame(5, $this->issue(15)->priority->id);
    }

    /** A card may be visible to somebody who cannot see its parent: the parent lane then shows the number, not the subject */
    public function testParentSwimlaneDoesNotRevealAParentTheViewerCannotSee(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->setOptions($board, ['swimlane' => 'parent']);
        $this->em->getConnection()->executeStatement("UPDATE issues SET is_private = 1, subject = 'Secret plan' WHERE id = 14");
        $this->em->clear();
        $lanes = fn (): array => $this->get('/kanban/boards/'.$board->id)->filter('.kanban-lane .kanban-lane-name')->each(static fn ($n) => $n->text());

        $this->loginAs('jsmith'); // a manager sees every issue
        self::assertContains('#14 Secret plan', $lanes());

        $this->loginAs('dlopper'); // sees the subtasks, not their private parent (neither its author nor its assignee)
        $crawler = $this->get('/kanban/boards/'.$board->id);
        self::assertCount(1, $crawler->filter('#kanban-card-15'));
        self::assertCount(0, $crawler->filter('#kanban-card-14'));
        self::assertContains('#14', $lanes());
        self::assertStringNotContainsString('Secret plan', (string) $this->client->getResponse()->getContent());
    }

    public function testAddingACard(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->loginAs('jsmith');
        $crawler = $this->get('/kanban/boards/'.$board->id);
        self::assertCount(6, $crawler->filter('.kanban-add-button'));
        self::assertCount(1, $crawler->filter('#kanban-add-template'));

        // Redmine starts a new issue in the default status of its tracker unless the workflow has "New issue" transitions
        $json = $this->postJson('/kanban/boards/'.$board->id.'/cards', ['subject' => 'Gift cards', 'project_id' => 1, 'tracker_id' => 2, 'column_id' => $this->columnFor($board, 2)->id]);
        $this->assertStatus(201);
        $issue = $this->em->getRepository(Issue::class)->findOneBy(['subject' => 'Gift cards']);
        self::assertNotNull($issue);
        self::assertSame([1, 2, 1, $this->user('jsmith')->id], [$issue->project->id, $issue->tracker->id, $issue->status->id, $issue->author->id]);
        self::assertStringContainsString('id="kanban-card-'.$issue->id.'"', $json['card']);
        self::assertStringContainsString('data-column="'.$this->columnFor($board, 1)->id.'"', $json['card'], 'the card says which column it really is in');
        self::assertSame(5, $json['columns'][$this->columnFor($board, 1)->id]['count']);

        // with such a transition (old status "0") the issue starts in the status of the column it was added to
        $manager = $this->em->getRepository(\App\Entity\Role::class)->findOneBy(['name' => 'Manager']);
        self::getContainer()->get(\App\Service\Admin\WorkflowService::class)->addTransition(2, (int) $manager->id, 0, 2);
        $this->em->flush();
        $this->postJson('/kanban/boards/'.$board->id.'/cards', ['subject' => 'Loyalty points', 'project_id' => 1, 'tracker_id' => 2, 'column_id' => $this->columnFor($board, 2)->id]);
        $this->assertStatus(201);
        self::assertSame(2, $this->em->getRepository(Issue::class)->findOneBy(['subject' => 'Loyalty points'])->status->id);

        // in the subproject
        $this->postJson('/kanban/boards/'.$board->id.'/cards', ['subject' => 'Express pay', 'project_id' => 3, 'tracker_id' => 1, 'column_id' => $this->columnFor($board, 1)->id]);
        $this->assertStatus(201);
        self::assertSame(3, $this->em->getRepository(Issue::class)->findOneBy(['subject' => 'Express pay'])->project->id);

        // no subject
        $json = $this->postJson('/kanban/boards/'.$board->id.'/cards', ['subject' => ' ', 'project_id' => 1, 'tracker_id' => 2, 'column_id' => $this->columnFor($board, 1)->id]);
        $this->assertStatus(422);
        self::assertSame(['Subject cannot be blank'], $json['errors']);

        // a project that is not on the board
        $this->postJson('/kanban/boards/'.$board->id.'/cards', ['subject' => 'Elsewhere', 'project_id' => 2, 'tracker_id' => 1]);
        $this->assertStatus(403);
        self::assertNull($this->em->getRepository(Issue::class)->findOneBy(['subject' => 'Elsewhere']));

        // a developer cannot start an issue as Rejected: it starts where its tracker starts
        $this->loginAs('dlopper');
        $this->postJson('/kanban/boards/'.$board->id.'/cards', ['subject' => 'Wrong column', 'project_id' => 1, 'tracker_id' => 1, 'column_id' => $this->columnFor($board, 6)->id]);
        $this->assertStatus(201);
        self::assertSame(1, $this->em->getRepository(Issue::class)->findOneBy(['subject' => 'Wrong column'])->status->id);
    }

    public function testFilters(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->loginAs('jsmith');
        $ids = fn (string $query) => (static function (array $cards): array { $all = array_merge(...array_values($cards)); sort($all); return $all; })($this->cardsByColumn('/kanban/boards/'.$board->id.'?'.$query));

        self::assertSame([14, 15, 16], $ids('q=wishlist'));
        self::assertSame([15], $ids('q=wishlist+sharing'));
        self::assertSame([13], $ids('q=%2313'));
        self::assertSame([17], $ids('assigned_to_id=me'));
        self::assertSame([14, 15, 16, 19], $ids('assigned_to_id=none'));
        self::assertSame([13], $ids('assigned_to_id=10'));
        self::assertSame([13, 17], $ids('tracker_id=1'));
        self::assertSame([14, 16], $ids('priority_id=4'));
        self::assertSame([13], $ids('fixed_version_id=1'));
        self::assertSame([14, 16], $ids('tracker_id=2&priority_id=4&q=wish'));
        self::assertSame([], $ids('q=%25'), '"%" is searched for as such, it is not a wildcard');
        self::assertSame([], $ids('q=w_shlist'));
        self::assertSame([], $ids('q=nothing-like-this'));
        self::assertStringContainsString('No issue matches these filters.', (string) $this->client->getResponse()->getContent());

        // the numbers of the columns follow the filter, also after a move
        $crawler = $this->get('/kanban/boards/'.$board->id.'?tracker_id=2');
        self::assertSame(['3', '0', '0', '0', '0', '0'], $crawler->filter('.kanban-head .kanban-count-value')->each(static fn ($n) => $n->text()));
        self::assertCount(1, $crawler->filter('select[name="tracker_id"] option[value="2"][selected]'));
        $json = $this->postJson($this->moveUrl($board, 14).'?tracker_id=2', ['column_id' => $this->columnFor($board, 2)->id]);
        self::assertSame(1, $json['columns'][$this->columnFor($board, 2)->id]['count'], 'the Bug #13 is filtered out');
    }

    public function testLimitsShowMoreAndWorkInProgress(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->change($board, static function (Board $b): void {
            $b->options = ['card_limit' => 10] + $b->options;
            $b->columnsByStatus()[1]->wipLimit = 3;
        });
        $this->loginAs('jsmith');
        for ($i = 1; $i <= 12; ++$i) {
            $this->postJson('/kanban/boards/'.$board->id.'/cards', ['subject' => 'Card '.$i, 'project_id' => 1, 'tracker_id' => 2, 'column_id' => $this->columnFor($board, 1)->id]);
            $this->assertStatus(201);
        }

        $crawler = $this->get('/kanban/boards/'.$board->id);
        $head = $crawler->filter('.kanban-column-head[data-column="'.$this->columnFor($board, 1)->id.'"]');
        self::assertSame('16', $head->filter('.kanban-count-value')->text());
        self::assertStringContainsString('kanban-over-limit', (string) $head->attr('class'), 'more cards than the limit of the column');
        self::assertCount(10, $crawler->filter('.kanban-cell[data-column="'.$this->columnFor($board, 1)->id.'"] .kanban-card'));
        $more = $crawler->filter('.kanban-more');
        self::assertSame('Show 6 more', $more->text());
        self::assertSame('10', $more->attr('data-offset'));

        $crawler = $this->get('/kanban/boards/'.$board->id.'/cards?column='.$this->columnFor($board, 1)->id.'&offset=10');
        $this->assertStatus(200);
        self::assertCount(6, $crawler->filter('.kanban-card'));

        // the whole board, as redrawn by the page
        $crawler = $this->get('/kanban/boards/'.$board->id.'/cards');
        self::assertCount(1, $crawler->filter('#kanban'));
        self::assertCount(0, $crawler->filter('#header'), 'only the board');
    }

    public function testTheVersionChangesWhenCardsDo(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->loginAs('jsmith');
        $read = function () use ($board): string {
            $this->client->request('GET', '/kanban/boards/'.$board->id.'/version');

            return json_decode((string) $this->client->getResponse()->getContent(), true)['version'];
        };
        $v1 = $read();
        self::assertSame($v1, $read(), 'nothing changed');
        self::assertSame($v1, $this->get('/kanban/boards/'.$board->id)->filter('#kanban')->attr('data-version'));

        $this->postJson($this->moveUrl($board, 14), ['column_id' => $this->columnFor($board, 2)->id]);
        $v2 = $read();
        self::assertNotSame($v1, $v2, 'a status changed');
        $this->postJson($this->moveUrl($board, 16), ['column_id' => $this->columnFor($board, 1)->id, 'before_id' => 15]);
        self::assertNotSame($v2, $read(), 'a card was placed');
    }
}

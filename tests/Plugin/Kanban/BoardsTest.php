<?php

declare(strict_types=1);

namespace App\Tests\Plugin\Kanban;

use App\Entity\IssueStatus;
use Symine\Kanban\Board\BoardAccess;
use Symine\Kanban\Board\BoardFactory;
use Symine\Kanban\Board\BoardScope;
use Symine\Kanban\Entity\Board;

/** Boards: where they appear, who sees and changes them, their settings and the mapping of statuses to columns */
final class BoardsTest extends KanbanWebTestCase
{
    public function testNothingShowsUntilTheModuleIsEnabled(): void
    {
        $this->loginAs('jsmith');
        $crawler = $this->get('/projects/ecommerce');
        self::assertCount(0, $crawler->filter('#main-menu a.kanban'));
        $this->get('/projects/ecommerce/kanban');
        $this->assertStatus(403);
        $this->get('/kanban');
        $this->assertStatus(403);
        self::assertCount(0, $this->get('/projects')->filter('#main-menu a.kanban'), 'no entry above the projects either');
    }

    public function testMenusAndEmptyList(): void
    {
        $this->enableKanban();
        $this->loginAs('jsmith');
        $crawler = $this->get('/projects/ecommerce');
        self::assertCount(1, $crawler->filter('#main-menu a.kanban[href="/projects/ecommerce/kanban"]'));
        self::assertCount(1, $this->get('/projects')->filter('#main-menu a.kanban[href="/kanban"]'));

        $crawler = $this->get('/projects/ecommerce/kanban');
        $this->assertStatus(200);
        self::assertStringContainsString('There is no board here yet. Create the first one', $crawler->filter('p.nodata')->text());
        self::assertCount(1, $crawler->filter('.contextual a[href="/kanban/boards/new?project_id=ecommerce"]'));

        // a reporter may look but not create
        $this->loginAs('rhill');
        $crawler = $this->get('/projects/ecommerce/kanban');
        $this->assertStatus(200);
        self::assertCount(0, $crawler->filter('.contextual a.icon-add'));
        $this->get('/kanban/boards/new?project_id=ecommerce');
        $this->assertStatus(403);

        // other projects are not concerned
        $this->loginAs('jsmith');
        $this->get('/projects/checkout/kanban');
        $this->assertStatus(403);
    }

    public function testCreateMapsEveryStatus(): void
    {
        $this->enableKanban();
        $this->loginAs('jsmith');
        $crawler = $this->get('/kanban/boards/new?project_id=ecommerce');
        $this->assertStatus(200);
        self::assertCount(1, $crawler->filter('input[name="template"][value="statuses"][checked]'));
        self::assertCount(3, $crawler->filter('input[name="board[visibility]"]'), 'a manager may share boards');

        $this->post('/kanban/boards', [
            'project_id' => 'ecommerce',
            'board' => ['name' => 'Delivery', 'description' => 'What ships next', 'visibility' => '2', 'include_subprojects' => '1'],
            'board_projects_submitted' => '1',
            'template' => 'statuses',
        ]);
        $board = $this->em->getRepository(Board::class)->findOneBy(['name' => 'Delivery']);
        self::assertNotNull($board);
        $this->assertRedirectTo('/kanban/boards/'.$board->id.'/edit');
        self::assertSame(1, $board->projectId);
        self::assertSame($this->user('jsmith')->id, $board->userId);
        self::assertSame(Board::VISIBILITY_PUBLIC, $board->visibility);
        self::assertTrue($board->includeSubprojects);
        self::assertSame(['New', 'In Progress', 'Resolved', 'Feedback', 'Closed', 'Rejected'], $board->columns->map(static fn ($c) => $c->name)->toArray());
        self::assertSame([[1], [2], [3], [4], [5], [6]], $board->columns->map(static fn ($c) => $c->statusIds())->toArray());
        self::assertSame([], $board->hiddenStatusIds());
        self::assertSame([], self::getContainer()->get(BoardScope::class)->unmappedStatusIds($board, $this->user('jsmith')), 'a new board hides nothing');

        // the project list now goes straight to the board
        $this->get('/projects/ecommerce/kanban');
        $this->assertRedirectTo('/kanban/boards/'.$board->id);
        self::assertCount(1, $this->get('/projects/ecommerce/kanban?all=1')->filter('#kanban-board-'.$board->id));
    }

    public function testSimpleTemplateGroupsTheStatuses(): void
    {
        $this->enableKanban();
        $board = $this->board([], BoardFactory::TEMPLATE_SIMPLE);
        self::assertSame(['To do', 'In progress', 'Done'], $board->columns->map(static fn ($c) => $c->name)->toArray());
        self::assertSame([[1], [2, 3, 4], [5, 6]], $board->columns->map(static fn ($c) => $c->statusIds())->toArray(), 'the default status of the trackers, the other open ones, the closed ones');
    }

    public function testCreateValidation(): void
    {
        $this->enableKanban();
        $this->loginAs('dlopper');
        $crawler = $this->get('/kanban/boards/new?project_id=ecommerce');
        self::assertCount(0, $crawler->filter('input[name="board[visibility]"]'), 'a developer cannot share boards');

        $response = $this->post('/kanban/boards', ['project_id' => 'ecommerce', 'board' => ['name' => ' ', 'visibility' => '2']]);
        $this->assertStatus(422);
        self::assertStringContainsString('Name cannot be blank', (string) $response->getContent());
        self::assertSame(0, $this->em->getRepository(Board::class)->count([]));

        // asking for a shared board without the permission gives a private one
        $this->post('/kanban/boards', ['project_id' => 'ecommerce', 'board' => ['name' => 'Mine', 'visibility' => '2']]);
        $board = $this->em->getRepository(Board::class)->findOneBy(['name' => 'Mine']);
        self::assertNotNull($board);
        self::assertSame(Board::VISIBILITY_PRIVATE, $board->visibility);

        // projects the user cannot see cannot be put on a board
        $this->post('/kanban/boards', ['project_id' => 'ecommerce', 'board' => ['name' => 'Sneaky', 'project_ids' => ['1', '2', '999']], 'board_projects_submitted' => '1']);
        self::assertSame([1], $this->em->getRepository(Board::class)->findOneBy(['name' => 'Sneaky'])->projectIds, '"internal" (2) is invisible to dlopper');
    }

    public function testWhoSeesABoard(): void
    {
        $this->enableKanban();
        $public = $this->board();
        $private = $this->board(['name' => 'Private', 'visibility' => Board::VISIBILITY_PRIVATE]);
        $managerRole = $this->em->getRepository(\App\Entity\Role::class)->findOneBy(['name' => 'Manager']);
        $roles = $this->board(['name' => 'Managers', 'visibility' => Board::VISIBILITY_ROLES, 'roleIds' => [$managerRole->id]]);
        $access = self::getContainer()->get(BoardAccess::class);

        $expected = [
            'jsmith' => [true, true, true],     // owner
            'dlopper' => [true, false, false],  // developer
            'rhill' => [true, false, false],
            'admin' => [true, true, true],
            'anna' => [false, false, false],    // not a member, and non members have no permission
        ];
        foreach ($expected as $login => [$seesPublic, $seesPrivate, $seesRoles]) {
            $user = $this->user($login);
            self::assertSame($seesPublic, $access->canView($public, $user), "$login public");
            self::assertSame($seesPrivate, $access->canView($private, $user), "$login private");
            self::assertSame($seesRoles, $access->canView($roles, $user), "$login roles");
        }

        $this->loginAs('dlopper');
        $this->get('/kanban/boards/'.$public->id);
        $this->assertStatus(200);
        $this->get('/kanban/boards/'.$private->id);
        $this->assertStatus(403);
        $this->get('/kanban/boards/'.$private->id.'/cards');
        $this->assertStatus(403);
        $this->get('/kanban/boards/999');
        $this->assertStatus(404);
        self::assertCount(1, $this->get('/projects/ecommerce/kanban?all=1')->filter('table.kanban-boards tbody tr'));
        self::assertCount(1, $this->get('/kanban')->filter('table.kanban-boards tbody tr'));

        // who may change it
        self::assertTrue($access->canEdit($public, $this->user('jsmith')));
        self::assertFalse($access->canEdit($public, $this->user('dlopper')), 'changing a shared board needs manage_public_kanban_boards');
        self::assertFalse($access->canEdit($public, $this->user('rhill')));
        self::assertTrue($access->canEdit($private, $this->user('jsmith')));
        $this->get('/kanban/boards/'.$public->id.'/edit');
        $this->assertStatus(403);
        $this->post('/kanban/boards/'.$public->id, ['board' => ['name' => 'Hacked']]);
        $this->assertStatus(403);
        $this->post('/kanban/boards/'.$public->id, [], 'DELETE');
        $this->assertStatus(403);

        // visitors are sent to the login page
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/kanban/boards/'.$public->id);
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403]);
    }

    public function testEditColumnsAndMapping(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->loginAs('jsmith');
        $crawler = $this->get('/kanban/boards/'.$board->id.'/edit');
        $this->assertStatus(200);
        self::assertCount(6 + 2, $crawler->filter('#kanban-column-rows tr'), 'the columns and two blank rows');
        self::assertCount(6, $crawler->filter('select.kanban-status-select'), 'every issue status can be mapped');
        self::assertCount(0, $crawler->filter('#kanban-mapping-warning'));

        $c = static fn (int $status) => 'c'.$board->columnsByStatus()[$status]->id;
        $this->post('/kanban/boards/'.$board->id, [
            'board' => ['name' => 'Flow', 'options' => ['swimlane' => 'assignee', 'color_by' => 'tracker', 'sort' => 'priority', 'closed_days' => '30', 'card_limit' => '50', 'refresh' => '0', 'card_fields' => ['', 'priority', 'assignee', 'bogus']]],
            'columns' => [
                $c(1) => ['name' => 'Backlog', 'wip_limit' => '', 'position' => '2'],
                $c(2) => ['name' => 'Doing', 'wip_limit' => '3', 'position' => '3'],
                $c(3) => ['name' => 'Resolved', 'delete' => '1'],
                $c(4) => ['name' => 'Feedback', 'delete' => '1'],
                $c(5) => ['name' => 'Done', 'position' => '4'],
                $c(6) => ['name' => 'Rejected', 'delete' => '1'],
                'n1' => ['name' => 'Review', 'wip_limit' => '2', 'position' => '1'],
                'n2' => ['name' => '', 'wip_limit' => ''],
            ],
            // Resolved and Feedback go to the new column, Rejected is hidden on purpose, Closed stays
            'status_column' => [1 => $c(1), 2 => $c(2), 3 => 'n1', 4 => 'n1', 5 => $c(5), 6 => 'hidden'],
        ]);
        $this->assertRedirectTo('/kanban/boards/'.$board->id);
        $this->em->clear();
        $board = $this->em->find(Board::class, $board->id);
        self::assertSame('Flow', $board->name);
        self::assertSame(['Review', 'Backlog', 'Doing', 'Done'], $board->columns->map(static fn ($c) => $c->name)->getValues());
        self::assertSame([[3, 4], [1], [2], [5]], $board->columns->map(static fn ($c) => $c->statusIds())->getValues());
        self::assertSame([2, null, 3, null], $board->columns->map(static fn ($c) => $c->wipLimit)->getValues());
        self::assertSame([6], $board->hiddenStatusIds());
        self::assertSame('assignee', $board->option('swimlane'));
        self::assertSame('tracker', $board->option('color_by'));
        self::assertSame('priority', $board->option('sort'));
        self::assertSame(30, $board->option('closed_days'));
        self::assertSame(50, $board->option('card_limit'));
        self::assertSame(0, $board->option('refresh'));
        self::assertSame(['priority', 'assignee'], $board->option('card_fields'));
        self::assertSame([], self::getContainer()->get(BoardScope::class)->unmappedStatusIds($board, $this->user('jsmith')));

        // the form shows the mapping back
        $crawler = $this->get('/kanban/boards/'.$board->id.'/edit');
        self::assertSame('hidden', $crawler->filter('select[name="status_column[6]"] option[selected]')->attr('value'));
        self::assertSame('Review', $crawler->filter('select[name="status_column[3]"] option[selected]')->text());
    }

    public function testRejectedSettingsChangeNothing(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->loginAs('jsmith');
        $c = static fn (int $status) => 'c'.$board->columnsByStatus()[$status]->id;

        $response = $this->post('/kanban/boards/'.$board->id, [
            'board' => ['name' => 'Renamed'],
            'columns' => [$c(1) => ['name' => ''], $c(2) => ['name' => 'Doing', 'wip_limit' => 'many']],
            'status_column' => [1 => $c(1)],
        ]);
        $this->assertStatus(422);
        self::assertStringContainsString('Name cannot be blank', (string) $response->getContent());
        self::assertStringContainsString('Limit is invalid', (string) $response->getContent());

        $response = $this->post('/kanban/boards/'.$board->id, ['columns' => array_fill_keys(array_map($c, [1, 2, 3, 4, 5, 6]), ['name' => 'x', 'delete' => '1'])]);
        $this->assertStatus(422);
        self::assertStringContainsString('A board needs at least one column', (string) $response->getContent());

        $this->em->clear();
        $board = $this->em->find(Board::class, $board->id);
        self::assertSame('Delivery', $board->name);
        self::assertSame(['New', 'In Progress', 'Resolved', 'Feedback', 'Closed', 'Rejected'], $board->columns->map(static fn ($c) => $c->name)->getValues());
        self::assertSame([[1], [2], [3], [4], [5], [6]], $board->columns->map(static fn ($c) => $c->statusIds())->getValues());
    }

    public function testStatusesLeftUnmappedStayInSight(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->loginAs('jsmith');
        self::assertSame(['New' => [14, 15, 16, 17], 'In Progress' => [13], 'Resolved' => [], 'Feedback' => [], 'Closed' => [19], 'Rejected' => []], $this->cardsByColumn('/kanban/boards/'.$board->id));

        // "New" loses its column without being hidden: its issues move to the Unmapped column
        $c = static fn (int $status) => 'c'.$board->columnsByStatus()[$status]->id;
        $columns = [];
        foreach ([2, 3, 4, 5, 6] as $status) {
            $columns[$c($status)] = ['name' => $board->columnsByStatus()[$status]->name];
        }
        $columns[$c(1)] = ['name' => 'New', 'delete' => '1'];
        $this->post('/kanban/boards/'.$board->id, ['columns' => $columns, 'status_column' => [1 => '', 2 => $c(2), 3 => $c(3), 4 => $c(4), 5 => $c(5), 6 => $c(6)]]);
        $this->assertRedirectTo('/kanban/boards/'.$board->id);

        $cards = $this->cardsByColumn('/kanban/boards/'.$board->id);
        self::assertSame(['In Progress', 'Resolved', 'Feedback', 'Closed', 'Rejected', 'Unmapped'], array_keys($cards));
        self::assertSame([14, 15, 16, 17], $cards['Unmapped']);
        $crawler = $this->get('/kanban/boards/'.$board->id);
        self::assertStringContainsString('These statuses are not mapped to a column: New.', $crawler->filter('#kanban-unmapped-note')->text());
        self::assertCount(0, $crawler->filter('.kanban-column-head[data-column="unmapped"] .kanban-add-button'), 'cards are not added to the Unmapped column');
        self::assertCount(1, $this->get('/kanban/boards/'.$board->id.'/edit')->filter('#kanban-mapping-warning'));

        // hidden on purpose: gone, and no longer reported
        $this->post('/kanban/boards/'.$board->id, ['columns' => array_diff_key($columns, [$c(1) => 1]), 'status_column' => [1 => 'hidden', 2 => $c(2), 3 => $c(3), 4 => $c(4), 5 => $c(5), 6 => $c(6)]]);
        $cards = $this->cardsByColumn('/kanban/boards/'.$board->id);
        self::assertSame(['In Progress' => [13], 'Resolved' => [], 'Feedback' => [], 'Closed' => [19], 'Rejected' => []], $cards);
        self::assertCount(0, $this->get('/kanban/boards/'.$board->id)->filter('#kanban-unmapped-note'));
    }

    public function testAStatusAddedLaterIsNotLost(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $status = new IssueStatus();
        $status->name = 'In review';
        $status->position = 7;
        $this->em->persist($status);
        $this->em->flush();
        // an issue ends up in the new status, which no board knows about
        $this->em->getConnection()->executeStatement('UPDATE issues SET status_id = ? WHERE id = 15', [$status->id]);
        $this->em->clear();

        $this->loginAs('jsmith');
        $cards = $this->cardsByColumn('/kanban/boards/'.$board->id);
        self::assertSame([15], $cards['Unmapped']);
        self::assertSame([14, 16, 17], $cards['New']);
    }

    public function testCopyAndDestroy(): void
    {
        $this->enableKanban();
        $board = $this->board(['hiddenStatusIds' => [6]]);
        $board->columns->first()->wipLimit = 4;
        $this->em->flush();

        $this->loginAs('dlopper');
        $this->post('/kanban/boards/'.$board->id.'/copy');
        $copy = $this->em->getRepository(Board::class)->findOneBy(['name' => 'Copy of Delivery']);
        self::assertNotNull($copy);
        $this->assertRedirectTo('/kanban/boards/'.$copy->id.'/edit');
        self::assertSame($this->user('dlopper')->id, $copy->userId);
        self::assertSame(Board::VISIBILITY_PRIVATE, $copy->visibility, 'a copy starts private');
        self::assertSame($board->columns->map(static fn ($c) => [$c->name, $c->statusIds(), $c->wipLimit])->getValues(), $copy->columns->map(static fn ($c) => [$c->name, $c->statusIds(), $c->wipLimit])->getValues());
        self::assertSame([6], $copy->hiddenStatusIds());

        // the owner of a private board may delete it
        $this->post('/kanban/boards/'.$copy->id, [], 'DELETE');
        $this->assertRedirectTo('/projects/ecommerce/kanban?all=1');
        $this->em->clear();
        self::assertNull($this->em->find(Board::class, $copy->id));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM kanban_columns WHERE board_id = ?', [$copy->id]));
        self::assertNotNull($this->em->find(Board::class, $board->id));
    }

    public function testBoardAboveTheProjects(): void
    {
        $this->enableKanban('ecommerce', 'internal');
        $this->loginAs('admin');
        $this->post('/kanban/boards', ['board' => ['name' => 'Everything', 'visibility' => '2'], 'board_projects_submitted' => '1']);
        $board = $this->em->getRepository(Board::class)->findOneBy(['name' => 'Everything']);
        self::assertNotNull($board);
        self::assertNull($board->projectId);

        // every project the user sees: "internal" for its members only
        $cards = array_merge(...array_values($this->cardsByColumn('/kanban/boards/'.$board->id)));
        sort($cards);
        self::assertSame([13, 14, 15, 16, 17, 18, 19], $cards);

        $this->loginAs('dlopper');
        $cards = array_merge(...array_values($this->cardsByColumn('/kanban/boards/'.$board->id)));
        sort($cards);
        self::assertSame([13, 14, 15, 16, 19], $cards, 'neither the private issue #17 nor #18 of a project dlopper is not in');
        self::assertCount(1, $this->get('/kanban')->filter('#kanban-board-'.$board->id));

        // limited to chosen projects
        $this->loginAs('admin');
        $this->post('/kanban/boards/'.$board->id, ['board' => ['name' => 'Everything', 'project_ids' => ['2']], 'board_projects_submitted' => '1']);
        $cards = array_merge(...array_values($this->cardsByColumn('/kanban/boards/'.$board->id)));
        self::assertSame([18], $cards);
    }

    public function testPluginSettingsAreTheDefaultsOfNewBoards(): void
    {
        $this->enableKanban();
        $this->loginAs('admin');
        $crawler = $this->get('/settings/plugin/symine_kanban');
        $this->assertStatus(200);
        self::assertCount(1, $crawler->filter('input[name="settings[default_closed_days]"][value="14"]'));
        $this->post('/settings/plugin/symine_kanban', ['settings' => ['default_closed_days' => '3', 'default_card_limit' => '20', 'default_refresh' => '0']]);
        $board = self::getContainer()->get(BoardFactory::class)->blank($this->project(), $this->user('admin'));
        self::assertSame(3, $board->option('closed_days'));
        self::assertSame(20, $board->option('card_limit'));
        self::assertSame(0, $board->option('refresh'));
    }
}

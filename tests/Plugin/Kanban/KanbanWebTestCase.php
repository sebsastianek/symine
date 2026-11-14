<?php

declare(strict_types=1);

namespace App\Tests\Plugin\Kanban;

use App\Entity\EnabledModule;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Role;
use App\Plugin\Migration\PluginMigrator;
use App\Plugin\PluginRegistry;
use App\Tests\Admin\AdminWebTestCase;
use Symine\Kanban\Board\BoardFactory;
use Symine\Kanban\Entity\Board;
use Symine\Kanban\Entity\BoardColumn;
use Symine\Kanban\KanbanPlugin;
use Symine\Kanban\KanbanSchema;

/**
 * Functional tests of the Kanban plugin (plugins/symine-kanban). Its migrations are run on the fresh test database.
 *
 * Seeded data used throughout: project "ecommerce" (1) with subproject "checkout" (3) and the private project
 * "internal" (2); statuses New (1), In Progress (2), Resolved (3), Feedback (4), Closed (5), Rejected (6);
 * issues #13 (Bug, In Progress, assigned to the Developers group), #14-#16 (Feature, New), #17 (Bug, New, private,
 * in checkout), #18 (in internal), #19 (Support, Closed a week ago).
 * jsmith is Manager (every transition), dlopper Developer (never to Rejected, never back to New),
 * rhill Reporter (only to Closed).
 */
abstract class KanbanWebTestCase extends AdminWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $registry = self::getContainer()->get(PluginRegistry::class);
        self::getContainer()->get(PluginMigrator::class)->migrate($registry->find(KanbanPlugin::ID));
        self::getContainer()->get(KanbanSchema::class)->reset();
    }

    protected function project(string $identifier = 'ecommerce'): Project
    {
        $p = $this->em->getRepository(Project::class)->findOneBy(['identifier' => $identifier]);
        self::assertNotNull($p);

        return $p;
    }

    /**
     * Enables the module on projects and gives the usual permissions: managers see, keep and share boards,
     * developers see and keep them, reporters only see them.
     */
    protected function enableKanban(string ...$identifiers): void
    {
        foreach ($identifiers ?: ['ecommerce'] as $identifier) {
            $project = $this->project($identifier);
            if (!$project->isModuleEnabled('kanban')) {
                $m = new EnabledModule();
                $m->project = $project;
                $m->name = 'kanban';
                $project->enabledModules->add($m);
                $this->em->persist($m);
            }
        }
        $this->grant('Manager', 'view_kanban_boards', 'save_kanban_boards', 'manage_public_kanban_boards');
        $this->grant('Developer', 'view_kanban_boards', 'save_kanban_boards');
        $this->grant('Reporter', 'view_kanban_boards');
        $this->em->flush();
    }

    protected function grant(string $roleName, string ...$permissions): void
    {
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => $roleName]);
        self::assertNotNull($role, $roleName);
        $role->setPermissions(array_values(array_unique([...$role->getPermissions(), ...$permissions])));
        $this->em->flush();
    }

    /**
     * A saved board of the project "ecommerce" (with its subproject), public, one column per status.
     *
     * @param array<string, mixed> $attributes properties of the board to override
     */
    protected function board(array $attributes = [], string $template = BoardFactory::TEMPLATE_STATUSES, ?string $owner = 'jsmith'): Board
    {
        $factory = self::getContainer()->get(BoardFactory::class);
        $project = \array_key_exists('projectId', $attributes) && null === $attributes['projectId'] ? null : $this->project();
        $board = $factory->blank($project, $this->user($owner));
        $board->name = 'Delivery';
        $board->visibility = Board::VISIBILITY_PUBLIC;
        $board->includeSubprojects = true;
        foreach ($attributes as $name => $value) {
            $board->{$name} = $value;
        }
        $factory->applyTemplate($board, $template, $this->user('admin'));
        // what the template reset
        $board->hiddenStatusIds = $attributes['hiddenStatusIds'] ?? [];
        $this->em->persist($board);
        $this->em->flush();

        return $board;
    }

    /**
     * Changes a saved board. Requests reload the entities, so the board is fetched again before it is changed.
     *
     * @param callable(Board): void $change
     */
    protected function change(Board $board, callable $change): Board
    {
        $fresh = $this->em->find(Board::class, $board->id);
        self::assertNotNull($fresh);
        $change($fresh);
        $fresh->touch();
        $this->em->flush();

        return $fresh;
    }

    /** @param array<string, mixed> $options */
    protected function setOptions(Board $board, array $options): Board
    {
        return $this->change($board, static function (Board $b) use ($options): void {
            $b->options = $options + ($b->options ?? []);
        });
    }

    /** @return list<int> ids of the statuses the workflow lets the user give to the issue */
    protected function allowedStatusIds(int $issueId, string $login): array
    {
        $issue = $this->em->find(Issue::class, $issueId);
        $ids = array_map(static fn ($s) => (int) $s->id, self::getContainer()->get(\App\Service\IssueService::class)->newStatusesAllowedTo($issue, $this->user($login)));
        sort($ids);

        return array_values(array_diff($ids, [$issue->status->id]));
    }

    protected function columnFor(Board $board, int $statusId): BoardColumn
    {
        $column = $board->columnsByStatus()[$statusId] ?? null;
        self::assertNotNull($column, "column of status $statusId");

        return $column;
    }

    protected function issue(int $id): Issue
    {
        $this->em->clear();
        $issue = $this->em->find(Issue::class, $id);
        self::assertNotNull($issue);

        return $issue;
    }

    /** @return array<string, mixed> the decoded JSON answer */
    protected function postJson(string $url, array $params = []): array
    {
        $response = $this->post($url, $params);

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    /** @return array<string, list<int>> column name => issue ids, as the board page shows them */
    protected function cardsByColumn(string $url): array
    {
        $crawler = $this->get($url);
        $this->assertStatus(200);
        $names = [];
        foreach ($crawler->filter('.kanban-head .kanban-column-head') as $head) {
            $names[$head->getAttribute('data-column')] = trim($head->getElementsByTagName('span')->item(0)->textContent);
        }
        $cards = array_fill_keys(array_values($names), []);
        foreach ($crawler->filter('.kanban-card') as $card) {
            $cards[$names[$card->getAttribute('data-column')]][] = (int) $card->getAttribute('data-issue-id');
        }

        return $cards;
    }
}

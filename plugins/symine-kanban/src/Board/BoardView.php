<?php

declare(strict_types=1);

namespace Symine\Kanban\Board;

use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\IssueStatus;
use App\Entity\User;
use App\Service\IssueService;
use App\I18n\Localizer;
use Doctrine\ORM\EntityManagerInterface;
use Symine\Kanban\Card\CardBadges;
use Symine\Kanban\Card\CardCreator;
use Symine\Kanban\Card\CardFilter;
use Symine\Kanban\Card\CardMover;
use Symine\Kanban\Card\CardQuery;
use Symine\Kanban\Card\CardRules;
use Symine\Kanban\Entity\Board;
use Symine\Kanban\Entity\BoardColumn;

/**
 * What a board page shows: its columns with their cards, the automatic "Unmapped" column for the statuses the
 * board does not account for, the swimlanes, and what the user can do with each card.
 */
final class BoardView
{
    public const string UNMAPPED = 'unmapped';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BoardScope $scope,
        private readonly BoardAccess $access,
        private readonly BoardFinder $finder,
        private readonly CardQuery $cards,
        private readonly CardRules $rules,
        private readonly CardCreator $creator,
        private readonly CardBadges $badges,
        private readonly Localizer $i18n,
        private readonly IssueService $issues,
    ) {
    }

    /** @return array<string, mixed> the variables of the board page */
    public function page(Board $board, CardFilter $filter, User $user): array
    {
        $project = $this->access->project($board);
        $projects = $this->scope->projects($board, $user);

        return $this->body($board, $filter, $user) + [
            'project' => $project,
            'siblings' => $this->finder->siblings($board),
            'can_edit' => $this->access->canEdit($board, $user),
            'can_create_board' => $this->access->canCreate($project, $user),
            'filter_options' => [
                'trackers' => $this->scope->trackers($board, $user),
                'priorities' => $this->em->getRepository(IssuePriority::class)->findBy(['active' => true], ['position' => 'ASC']),
                'assignees' => $this->people($board, $user),
                'versions' => $this->versions($board, $user),
            ],
            'multi_project' => \count($projects) > 1,
        ];
    }

    /** @return array<string, mixed> the variables of the board itself (what is redrawn when cards change) */
    public function body(Board $board, CardFilter $filter, User $user): array
    {
        $limit = max(10, (int) $board->option('card_limit'));
        $counts = $this->cards->countsByStatus($board, $filter, $user);
        $statusNames = [];
        foreach ($this->scope->allStatuses() as $status) {
            $statusNames[(int) $status->id] = $status;
        }

        $columns = [];
        $taken = [];
        foreach ($board->columns as $column) {
            // a status shows in one column only: the first that lists it
            $statusIds = array_values(array_diff($column->statusIds(), $taken));
            $taken = [...$taken, ...$statusIds];
            $columns[] = $this->column($board, (int) $column->id, $column->name, $statusIds, $column->wipLimit, $counts, $statusNames, $filter, $user, $limit);
        }
        $unmapped = array_values(array_diff($this->scope->unmappedStatusIds($board, $user), $taken));
        $unmappedCount = array_sum(array_intersect_key($counts, array_flip($unmapped)));
        if ($unmappedCount > 0) {
            $columns[] = $this->column($board, self::UNMAPPED, $this->i18n->l('label_kanban_unmapped'), $unmapped, null, $counts, $statusNames, $filter, $user, $limit);
        }

        $swimlane = (string) $board->option('swimlane');
        $targets = $this->creator->targets($board, $user);

        return [
            'board' => $board,
            'columns' => $columns,
            'lanes' => 'none' === $swimlane ? null : $this->lanes($columns, $swimlane),
            'swimlane' => $swimlane,
            'lane_movable' => isset(CardMover::LANE_ATTRIBUTES[$swimlane]),
            'unmapped_statuses' => array_values(array_filter(array_map(static fn (int $id) => $statusNames[$id] ?? null, $unmapped))),
            'filter' => $filter,
            'version' => $this->cards->version($board, $filter, $user),
            'card_fields' => (array) $board->option('card_fields'),
            'show_project' => \count($this->scope->projects($board, $user)) > 1 || \in_array('project', (array) $board->option('card_fields'), true),
            'add_targets' => $targets,
            'manual_order' => 'manual' === $board->option('sort'),
            'total' => array_sum(array_column($columns, 'count')),
        ];
    }

    /**
     * Further cards of a column, after the ones already shown.
     *
     * @return list<array<string, mixed>>
     */
    public function moreCards(Board $board, string $columnId, int $offset, CardFilter $filter, User $user): array
    {
        $statusIds = self::UNMAPPED === $columnId ? $this->scope->unmappedStatusIds($board, $user) : ($board->column((int) $columnId)?->statusIds() ?? []);
        $limit = max(10, (int) $board->option('card_limit'));

        return $this->cardsOf($board, $this->cards->cards($board, $statusIds, $filter, $limit, max(0, $offset), $user), $columnId, $user);
    }

    /**
     * @param list<Issue> $issues
     *
     * @return list<array<string, mixed>> the cards of some issues, with their badges counted together
     */
    private function cardsOf(Board $board, array $issues, int|string|null $columnId, User $user): array
    {
        $fields = (array) $board->option('card_fields');
        $badges = \in_array('badges', $fields, true) || \in_array('cover', $fields, true)
            ? $this->badges->of($issues, $user, \in_array('cover', $fields, true))
            : [];

        return array_map(fn (Issue $issue) => $this->card($board, $issue, $columnId, $user, $badges[(int) $issue->id] ?? null), $issues);
    }

    /**
     * @param array<string, mixed>|null $badges counts of the issue (CardBadges), null: not shown
     *
     * @return array<string, mixed> one card, for the column it is in now
     */
    public function card(Board $board, Issue $issue, int|string|null $columnId, User $user, ?array $badges = null): array
    {
        $columnId ??= $board->columnsByStatus()[$issue->status->id]?->id ?? self::UNMAPPED;
        $moves = $this->rules->moves($board, $issue, $user);
        [$laneKey, $laneLabel, $laneSort] = $this->lane($issue, (string) $board->option('swimlane'), $user);

        return [
            'issue' => $issue,
            'column' => $columnId,
            'moves' => $moves,
            // a card that can go nowhere else can still be reordered in its column
            'movable' => $user->isLogged() && [] !== $moves,
            'badges' => $badges,
            'due_soon' => null !== $issue->dueDate && !$issue->isClosed() && !$issue->isOverdue() && $issue->dueDate <= new \DateTimeImmutable('today +2 days'),
            'lane' => $laneKey,
            'lane_label' => $laneLabel,
            'lane_sort' => $laneSort,
            'color' => match ($board->option('color_by')) {
                'priority' => 'priority-'.$issue->priority->id.' '.($issue->priority->positionName ? 'priority-'.$issue->priority->positionName : ''),
                'tracker' => 'tracker-'.$issue->tracker->id.' kanban-hue-'.($issue->tracker->id % 8),
                'project' => 'project-'.$issue->project->id.' kanban-hue-'.($issue->project->id % 8),
                default => '',
            },
        ];
    }

    /** @return array<string, mixed> the variables of the template of one card */
    public function cardVariables(Board $board, Issue $issue, User $user): array
    {
        return [
            'board' => $board,
            'card' => $this->cardsOf($board, [$issue], null, $user)[0],
            'card_fields' => (array) $board->option('card_fields'),
            'show_project' => \count($this->scope->projects($board, $user)) > 1 || \in_array('project', (array) $board->option('card_fields'), true),
            'manual_order' => 'manual' === $board->option('sort'),
        ];
    }

    /**
     * The numbers of the board after a change, for the page to update its column headers without redrawing.
     *
     * @return array{columns: array<int|string, array{count: int, over_limit: bool}>, version: string}
     */
    public function summary(Board $board, CardFilter $filter, User $user): array
    {
        $counts = $this->cards->countsByStatus($board, $filter, $user);
        $columns = [];
        $taken = [];
        foreach ($board->columns as $column) {
            $statusIds = array_values(array_diff($column->statusIds(), $taken));
            $taken = [...$taken, ...$statusIds];
            $count = array_sum(array_intersect_key($counts, array_flip($statusIds)));
            $columns[(int) $column->id] = ['count' => $count, 'over_limit' => null !== $column->wipLimit && $count > $column->wipLimit];
        }
        $unmapped = array_diff($this->scope->unmappedStatusIds($board, $user), $taken);
        $columns[self::UNMAPPED] = ['count' => array_sum(array_intersect_key($counts, array_flip($unmapped))), 'over_limit' => false];

        return ['columns' => $columns, 'version' => $this->cards->version($board, $filter, $user)];
    }

    /**
     * @param array<int, int>         $counts
     * @param array<int, IssueStatus> $statusNames
     * @param list<int>               $statusIds
     *
     * @return array<string, mixed>
     */
    private function column(Board $board, int|string $id, string $name, array $statusIds, ?int $wipLimit, array $counts, array $statusNames, CardFilter $filter, User $user, int $limit): array
    {
        $count = array_sum(array_intersect_key($counts, array_flip($statusIds)));
        $issues = $count > 0 ? $this->cards->cards($board, $statusIds, $filter, $limit, 0, $user) : [];

        return [
            'id' => $id,
            'name' => $name,
            'unmapped' => self::UNMAPPED === $id,
            'status_ids' => $statusIds,
            'statuses' => array_values(array_filter(array_map(static fn (int $sid) => $statusNames[$sid] ?? null, $statusIds))),
            'wip_limit' => $wipLimit,
            'count' => $count,
            'over_limit' => null !== $wipLimit && $count > $wipLimit,
            'cards' => $this->cardsOf($board, $issues, $id, $user),
            'more' => $count > \count($issues),
        ];
    }

    /**
     * The swimlanes: the cards of every column, grouped by what the lane stands for.
     *
     * @param list<array<string, mixed>> $columns
     *
     * @return list<array{key: string, label: string, count: int, cells: array<int|string, list<array<string, mixed>>>}>
     */
    private function lanes(array $columns, string $swimlane): array
    {
        $lanes = [];
        foreach ($columns as $column) {
            foreach ($column['cards'] as $card) {
                $key = $card['lane'];
                $lanes[$key] ??= ['key' => $key, 'label' => $card['lane_label'], 'sort' => $card['lane_sort'], 'count' => 0, 'cells' => []];
                $lanes[$key]['cells'][$column['id']][] = $card;
                ++$lanes[$key]['count'];
            }
        }
        // lanes in their natural order; the lane of the issues without the attribute comes last
        uasort($lanes, static fn (array $a, array $b) => ['0' === $a['key'], $a['sort']] <=> ['0' === $b['key'], $b['sort']]);

        return array_values(array_map(static function (array $lane): array {
            unset($lane['sort']);

            return $lane;
        }, $lanes));
    }

    /** @return array{string, string, mixed} key of the lane of an issue ("0": none), its label and what lanes are sorted by */
    private function lane(Issue $issue, string $swimlane, User $user): array
    {
        $none = [
            'assignee' => 'label_kanban_unassigned', 'version' => 'label_kanban_no_version', 'category' => 'label_kanban_no_category', 'parent' => 'label_kanban_no_parent',
        ];

        return match ($swimlane) {
            'project' => [(string) $issue->project->id, $issue->project->name, [$issue->project->lft ?? 0, $issue->project->name]],
            'tracker' => [(string) $issue->tracker->id, $issue->tracker->name, [$issue->tracker->position ?? 0, $issue->tracker->name]],
            'priority' => [(string) $issue->priority->id, $issue->priority->name, [-($issue->priority->position ?? 0), $issue->priority->name]],
            'assignee' => null !== $issue->assignedTo
                ? [(string) $issue->assignedTo->id, $issue->assignedTo->getName(), [0, mb_strtolower($issue->assignedTo->getName())]]
                : ['0', $this->i18n->l($none['assignee']), [1, '']],
            'version' => null !== $issue->fixedVersion
                ? [(string) $issue->fixedVersion->id, $issue->fixedVersion->name, [0, mb_strtolower($issue->fixedVersion->name)]]
                : ['0', $this->i18n->l($none['version']), [1, '']],
            'category' => null !== $issue->category
                ? [(string) $issue->category->id, $issue->category->name, [0, mb_strtolower($issue->category->name)]]
                : ['0', $this->i18n->l($none['category']), [1, '']],
            'parent' => null !== $issue->parent
                // a card can be seen by somebody who may not see its parent: the lane then only carries the number
                ? [(string) $issue->parent->id, '#'.$issue->parent->id.($this->issues->isVisible($issue->parent, $user) ? ' '.$issue->parent->subject : ''), [0, $issue->parent->id]]
                : ['0', $this->i18n->l($none['parent']), [1, 0]],
            default => ['', '', [0, '']],
        };
    }

    /** @return list<\App\Entity\Principal> the people the cards of the board are assigned to */
    private function people(Board $board, User $user): array
    {
        $ids = $this->cards->referencedIds($board, 'assignedTo', $user);
        $people = [] === $ids ? [] : $this->em->getRepository(\App\Entity\Principal::class)->findBy(['id' => $ids]);
        usort($people, static fn ($a, $b) => strcasecmp($a->getName(), $b->getName()));

        return $people;
    }

    /** @return list<\App\Entity\Version> the versions the cards of the board are planned for */
    private function versions(Board $board, User $user): array
    {
        $ids = $this->cards->referencedIds($board, 'fixedVersion', $user);

        return [] === $ids ? [] : $this->em->getRepository(\App\Entity\Version::class)->findBy(['id' => $ids], ['name' => 'ASC']);
    }
}

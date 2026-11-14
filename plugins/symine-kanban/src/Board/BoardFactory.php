<?php

declare(strict_types=1);

namespace Symine\Kanban\Board;

use App\Entity\IssueStatus;
use App\Entity\Project;
use App\Entity\User;
use App\I18n\Localizer;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symine\Kanban\Entity\Board;
use Symine\Kanban\Entity\BoardColumn;
use Symine\Kanban\KanbanPlugin;

/**
 * Builds new boards. Whatever the starting point, every status the board can meet ends up in a column:
 * a new board never hides anything.
 */
final class BoardFactory
{
    /** One column per status */
    public const string TEMPLATE_STATUSES = 'statuses';
    /** To do / In progress / Done */
    public const string TEMPLATE_SIMPLE = 'simple';

    public const array TEMPLATES = [self::TEMPLATE_STATUSES, self::TEMPLATE_SIMPLE];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BoardScope $scope,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
    ) {
    }

    /** A board not saved yet, with the defaults of the plugin settings */
    public function blank(?Project $project, User $owner): Board
    {
        $board = new Board();
        $board->projectId = $project?->id;
        $board->userId = (int) $owner->id;
        $defaults = $this->settings->hash('plugin_'.KanbanPlugin::ID);
        $board->options = [
            'closed_days' => max(0, (int) ($defaults['default_closed_days'] ?? Board::DEFAULT_OPTIONS['closed_days'])),
            'card_limit' => max(10, (int) ($defaults['default_card_limit'] ?? Board::DEFAULT_OPTIONS['card_limit'])),
            'refresh' => max(0, (int) ($defaults['default_refresh'] ?? Board::DEFAULT_OPTIONS['refresh'])),
        ] + Board::DEFAULT_OPTIONS;

        return $board;
    }

    /** Gives the board the columns of a template, for the statuses its projects and trackers use */
    public function applyTemplate(Board $board, string $template, ?User $user = null): void
    {
        $board->columns->clear();
        $board->hiddenStatusIds = [];
        $relevant = $this->scope->relevantStatusIds($board, $user);
        $statuses = array_values(array_filter($this->scope->allStatuses(), static fn (IssueStatus $s) => \in_array($s->id, $relevant, true)));
        if ([] === $statuses) {
            // no project or tracker yet: every status, so that the board is complete whatever is added later
            $statuses = $this->scope->allStatuses();
        }

        if (self::TEMPLATE_SIMPLE === $template) {
            $initial = [];
            foreach ($this->scope->trackers($board, $user) as $tracker) {
                if (null !== $tracker->defaultStatus) {
                    $initial[] = $tracker->defaultStatus->id;
                }
            }
            $groups = ['label_kanban_column_todo' => [], 'label_kanban_column_doing' => [], 'label_kanban_column_done' => []];
            foreach ($statuses as $status) {
                $key = $status->isClosed ? 'label_kanban_column_done' : (\in_array($status->id, $initial, true) ? 'label_kanban_column_todo' : 'label_kanban_column_doing');
                $groups[$key][] = (int) $status->id;
            }
            foreach ($groups as $label => $statusIds) {
                $this->addColumn($board, $this->i18n->l($label), $statusIds);
            }

            return;
        }

        foreach ($statuses as $status) {
            $this->addColumn($board, $status->name, [(int) $status->id]);
        }
    }

    /** A copy of a board for another owner; it starts private */
    public function copy(Board $source, User $owner): Board
    {
        $copy = new Board();
        $copy->name = mb_substr($this->i18n->l('label_kanban_copy_of', ['name' => $source->name]), 0, 255);
        $copy->description = $source->description;
        $copy->projectId = $source->projectId;
        $copy->userId = (int) $owner->id;
        $copy->visibility = Board::VISIBILITY_PRIVATE;
        $copy->projectIds = $source->projectIds;
        $copy->includeSubprojects = $source->includeSubprojects;
        $copy->trackerIds = $source->trackerIds;
        $copy->hiddenStatusIds = $source->hiddenStatusIds;
        $copy->options = $source->options;
        $copy->position = $this->nextPosition($source->projectId);
        foreach ($source->columns as $column) {
            $new = $this->addColumn($copy, $column->name, $column->statusIds());
            $new->wipLimit = $column->wipLimit;
        }

        return $copy;
    }

    public function nextPosition(?int $projectId): int
    {
        $qb = $this->em->createQueryBuilder()->select('MAX(b.position)')->from(Board::class, 'b');
        null === $projectId ? $qb->where('b.projectId IS NULL') : $qb->where('b.projectId = :p')->setParameter('p', $projectId);

        return 1 + (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @param list<int> $statusIds */
    private function addColumn(Board $board, string $name, array $statusIds): BoardColumn
    {
        $column = new BoardColumn();
        $column->board = $board;
        $column->name = mb_substr($name, 0, 255);
        $column->position = $board->columns->count() + 1;
        $column->statusIds = $statusIds;
        $board->columns->add($column);

        return $column;
    }
}

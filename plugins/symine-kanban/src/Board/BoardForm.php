<?php

declare(strict_types=1);

namespace Symine\Kanban\Board;

use App\Entity\Project;
use App\Entity\Role;
use App\Entity\Tracker;
use App\Entity\User;
use App\I18n\Localizer;
use App\Service\ProjectService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symine\Kanban\Entity\Board;
use Symine\Kanban\Entity\BoardColumn;

/**
 * The form of a board: what it is called and who sees it, the projects and trackers it covers, its columns and
 * the mapping of every issue status to a column.
 *
 * Fields: board[name|description|visibility|include_subprojects], board[role_ids|project_ids|tracker_ids][],
 * board[options][swimlane|color_by|sort|closed_days|card_limit|refresh], board[options][card_fields][],
 * template (new boards), columns[<key>][name|wip_limit|position|delete] and status_column[<status id>] holding the
 * key of a column, "hidden", or nothing (the status then shows in the "Unmapped" column).
 */
final class BoardForm
{
    public const string HIDDEN = 'hidden';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BoardAccess $access,
        private readonly BoardScope $scope,
        private readonly BoardFactory $factory,
        private readonly ProjectService $projects,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * Creates a board from the submitted form.
     *
     * @return list<string> what must be corrected; empty when the board was saved
     */
    public function create(Board $board, Request $request, User $user): array
    {
        $errors = $this->assign($board, $request, $user);
        $template = (string) $request->request->get('template', BoardFactory::TEMPLATE_STATUSES);
        $this->factory->applyTemplate($board, \in_array($template, BoardFactory::TEMPLATES, true) ? $template : BoardFactory::TEMPLATE_STATUSES, $user);
        if ([] !== $errors) {
            return $errors;
        }
        $board->position = $this->factory->nextPosition($board->projectId);
        $this->em->persist($board);
        $this->em->flush();

        return [];
    }

    /**
     * Updates a board, its columns and its status mapping from the submitted form.
     *
     * @return list<string> what must be corrected; empty when the board was saved
     */
    public function update(Board $board, Request $request, User $user): array
    {
        $errors = [...$this->assign($board, $request, $user), ...$this->assignColumns($board, $request)];
        if ([] !== $errors) {
            // nothing of a rejected form is kept: the board and its columns go back to what is saved
            foreach ($board->columns as $column) {
                if (null !== $column->id) {
                    $this->em->refresh($column);
                }
            }
            $this->em->refresh($board);

            return $errors;
        }
        $board->touch();
        $this->em->flush();

        return [];
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, mixed> the variables of the form
     */
    public function view(Board $board, User $user, array $errors = [], ?Request $rejected = null): array
    {
        $project = $this->access->project($board);
        $relevant = $this->scope->relevantStatusIds($board, $user);
        $byStatus = $board->columnsByStatus();
        $hidden = $board->hiddenStatusIds();

        $statuses = [];
        foreach ($this->scope->allStatuses() as $status) {
            $column = $byStatus[$status->id] ?? null;
            $statuses[] = [
                'status' => $status,
                'relevant' => \in_array($status->id, $relevant, true),
                'column' => null !== $column ? 'c'.$column->id : (\in_array($status->id, $hidden, true) ? self::HIDDEN : ''),
            ];
        }

        $projectOptions = [];
        foreach ($this->projects->visibleProjects($user) as $candidate) {
            if (!$candidate->isArchived()) {
                $projectOptions[] = ['project' => $candidate, 'level' => \count($candidate->getAncestors())];
            }
        }

        return [
            'board' => $board,
            'project' => $project,
            'errors' => $errors,
            'statuses' => $statuses,
            'unmapped_count' => \count(array_filter($statuses, static fn (array $s) => $s['relevant'] && '' === $s['column'])),
            'project_options' => $projectOptions,
            'selected_project_ids' => array_map('intval', $board->projectIds ?? []),
            'trackers' => $this->em->getRepository(Tracker::class)->findBy([], ['position' => 'ASC']),
            'roles' => $this->em->getRepository(Role::class)->findBy(['builtin' => 0], ['position' => 'ASC']),
            'can_share' => $this->access->canShare($project, $user),
            'templates' => BoardFactory::TEMPLATES,
            'template' => (string) ($rejected?->request->get('template') ?? BoardFactory::TEMPLATE_STATUSES),
            'swimlanes' => Board::SWIMLANES,
            'card_fields' => Board::CARD_FIELDS,
            'color_by' => Board::COLOR_BY,
            'sorts' => Board::SORTS,
        ];
    }

    /** @return list<string> errors */
    private function assign(Board $board, Request $request, User $user): array
    {
        $params = $request->request->all('board');
        $errors = [];
        $project = $this->access->project($board);

        $board->name = trim((string) ($params['name'] ?? $board->name));
        if ('' === $board->name) {
            $errors[] = $this->i18n->l('field_name').' '.$this->i18n->l('activerecord.errors.messages.blank');
        } elseif (mb_strlen($board->name) > 255) {
            $errors[] = $this->i18n->l('field_name').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 255]);
        }
        if (\array_key_exists('description', $params)) {
            $board->description = '' === trim((string) $params['description']) ? null : (string) $params['description'];
        }

        // sharing a board needs the permission to; without it the visibility stays what it was
        if (isset($params['visibility']) && $this->access->canShare($project, $user)) {
            $visibility = (int) $params['visibility'];
            if (\in_array($visibility, [Board::VISIBILITY_PRIVATE, Board::VISIBILITY_ROLES, Board::VISIBILITY_PUBLIC], true)) {
                $board->visibility = $visibility;
                $board->roleIds = Board::VISIBILITY_ROLES === $visibility ? $this->ids($params['role_ids'] ?? []) : [];
                if (Board::VISIBILITY_ROLES === $visibility && [] === $board->roleIds) {
                    $errors[] = $this->i18n->l('label_role_plural').' '.$this->i18n->l('activerecord.errors.messages.blank');
                }
            }
        }

        if (\array_key_exists('project_ids', $params) || $request->request->has('board_projects_submitted')) {
            // only projects the user can see can be put on a board
            $visible = array_map(static fn (Project $p) => (int) $p->id, $this->projects->visibleProjects($user));
            $board->projectIds = array_values(array_intersect($this->ids($params['project_ids'] ?? []), $visible));
            $board->includeSubprojects = !empty($params['include_subprojects']);
            $board->trackerIds = $this->ids($params['tracker_ids'] ?? []);
        }

        if (isset($params['options']) && \is_array($params['options'])) {
            $board->options = $this->options($params['options']) + ($board->options ?? []);
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $submitted
     *
     * @return array<string, mixed> the valid options among the submitted ones
     */
    private function options(array $submitted): array
    {
        $options = [];
        foreach (['swimlane' => Board::SWIMLANES, 'color_by' => Board::COLOR_BY, 'sort' => Board::SORTS] as $name => $allowed) {
            if (isset($submitted[$name]) && \in_array($submitted[$name], $allowed, true)) {
                $options[$name] = $submitted[$name];
            }
        }
        foreach (['closed_days' => [0, 3650], 'card_limit' => [10, 500], 'refresh' => [0, 3600]] as $name => [$min, $max]) {
            if (isset($submitted[$name]) && is_numeric($submitted[$name])) {
                $options[$name] = max($min, min($max, (int) $submitted[$name]));
            }
        }
        if (\array_key_exists('card_fields', $submitted)) {
            $options['card_fields'] = array_values(array_intersect(Board::CARD_FIELDS, (array) $submitted['card_fields']));
        }

        return $options;
    }

    /** @return list<string> errors */
    private function assignColumns(Board $board, Request $request): array
    {
        $submitted = $request->request->all('columns');
        if ([] === $submitted) {
            return [];
        }
        $errors = [];
        $existing = [];
        foreach ($board->columns as $column) {
            $existing['c'.$column->id] = $column;
        }

        /** @var array<string, BoardColumn> $kept columns by form key */
        $kept = [];
        $order = [];
        $index = 0;
        foreach ($submitted as $key => $data) {
            $key = (string) $key;
            ++$index;
            if (!\is_array($data)) {
                continue;
            }
            $name = trim((string) ($data['name'] ?? ''));
            $column = $existing[$key] ?? null;
            if (!empty($data['delete']) || (null === $column && '' === $name)) {
                continue;
            }
            if (null === $column) {
                $column = new BoardColumn();
                $column->board = $board;
            }
            if ('' === $name) {
                $errors[] = $this->i18n->l('label_kanban_column').' '.$index.': '.$this->i18n->l('field_name').' '.$this->i18n->l('activerecord.errors.messages.blank');
            }
            $column->name = mb_substr($name, 0, 255);
            $wip = trim((string) ($data['wip_limit'] ?? ''));
            if ('' !== $wip && (!ctype_digit($wip) || (int) $wip < 1)) {
                $errors[] = $this->i18n->l('label_kanban_column').' '.$index.': '.$this->i18n->l('field_kanban_wip_limit').' '.$this->i18n->l('activerecord.errors.messages.invalid');
            }
            $column->wipLimit = '' === $wip || !ctype_digit($wip) ? null : max(1, (int) $wip);
            $column->statusIds = [];
            $kept[$key] = $column;
            $order[$key] = [is_numeric($data['position'] ?? null) ? (int) $data['position'] : $index, $index];
        }
        if ([] === $kept) {
            $errors[] = $this->i18n->l('error_kanban_no_column');
        }

        // every status goes to the column chosen for it, is hidden on purpose, or stays unmapped
        $hidden = [];
        $statusOrder = [];
        foreach ($this->scope->allStatuses() as $status) {
            $statusOrder[(int) $status->id] = \count($statusOrder);
        }
        foreach ($request->request->all('status_column') as $statusId => $target) {
            $statusId = (int) $statusId;
            if (!isset($statusOrder[$statusId])) {
                continue;
            }
            if (self::HIDDEN === $target) {
                $hidden[] = $statusId;
            } elseif (\is_string($target) && isset($kept[$target])) {
                $kept[$target]->statusIds[] = $statusId;
            }
        }
        if ([] !== $errors) {
            return $errors;
        }

        uksort($kept, static fn (string $a, string $b) => $order[$a] <=> $order[$b]);
        $position = 0;
        foreach ($kept as $column) {
            $column->position = ++$position;
            $ids = $column->statusIds;
            usort($ids, static fn (int $a, int $b) => $statusOrder[$a] <=> $statusOrder[$b]);
            $column->statusIds = $ids;
            if (!$board->columns->contains($column)) {
                $board->columns->add($column);
            }
        }
        foreach ($existing as $column) {
            if (!\in_array($column, $kept, true)) {
                $board->columns->removeElement($column);
            }
        }
        $board->hiddenStatusIds = $hidden;

        return [];
    }

    /** @return list<int> */
    private function ids(mixed $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $values), static fn (int $id) => $id > 0)));
    }
}

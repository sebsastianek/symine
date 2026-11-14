<?php

declare(strict_types=1);

namespace Symine\Kanban\Card;

use App\Entity\User;
use App\I18n\Localizer;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symine\Kanban\Entity\Board;

/**
 * Moves a card: to another column (the issue gets a status of that column, as the workflow allows), to another
 * swimlane (the issue gets the assignee, version, priority or category of the lane) and to a place in the column.
 *
 * The issue is changed exactly as by its edit form: same safe attributes, validations, journal, notifications
 * and webhooks (IssueService::setSafeAttributes and save).
 *
 * Fields: column_id, status_id (when the column offers several), before_id (the card it goes before; none: at the
 * end), lane (the key of the swimlane it was dropped in).
 */
final class CardMover
{
    /** Swimlanes a card can be dragged between, with the issue attribute the lane stands for */
    public const array LANE_ATTRIBUTES = ['assignee' => 'assigned_to_id', 'version' => 'fixed_version_id', 'priority' => 'priority_id', 'category' => 'category_id'];

    public function __construct(
        private readonly CardQuery $cards,
        private readonly CardRules $rules,
        private readonly CardPositions $positions,
        private readonly IssueService $issues,
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
    ) {
    }

    public function move(Board $board, int $issueId, Request $request, User $user): MoveResult
    {
        $issue = $this->cards->card($board, $issueId, $user);
        if (null === $issue) {
            return MoveResult::refused($this->i18n->l('error_kanban_card_not_found'), 404);
        }
        $column = $board->column((int) $request->request->get('column_id'));
        if (null === $column) {
            return MoveResult::refused($this->i18n->l('error_kanban_column_not_found'));
        }

        $attributes = [];
        if (!\in_array($issue->status->id, $column->statusIds(), true)) {
            $targets = $this->rules->moves($board, $issue, $user)[(int) $column->id] ?? [];
            if ([] === $targets) {
                return MoveResult::refused($this->i18n->l('error_kanban_move_not_allowed', ['issue' => '#'.$issue->id, 'column' => $column->name]), 403);
            }
            $wanted = (int) $request->request->get('status_id');
            $attributes['status_id'] = \in_array($wanted, $targets, true) ? $wanted : $targets[0];
        }

        $lane = $request->request->get('lane');
        $laneAttribute = self::LANE_ATTRIBUTES[(string) $board->option('swimlane')] ?? null;
        if (null !== $laneAttribute && \is_string($lane) && preg_match('/^\d+$/', $lane)) {
            $current = match ($laneAttribute) {
                'assigned_to_id' => $issue->assignedTo?->id,
                'fixed_version_id' => $issue->fixedVersion?->id,
                'priority_id' => $issue->priority->id,
                'category_id' => $issue->category?->id,
            };
            if ((int) $lane !== (int) $current) {
                if (!$this->issues->isSafeAttribute($issue, $laneAttribute, $user)) {
                    return MoveResult::refused($this->i18n->l('error_kanban_lane_not_allowed', ['issue' => '#'.$issue->id]), 403);
                }
                // lane "0" is the lane of the issues without that attribute
                $attributes[$laneAttribute] = '0' === $lane ? '' : $lane;
            }
        }

        if ([] !== $attributes) {
            $this->issues->setSafeAttributes($issue, $attributes, $user);
            $errors = $this->issues->save($issue, $user);
            if ([] !== $errors) {
                $this->em->refresh($issue);

                return new MoveResult($issue, array_values($errors), 422);
            }
        }

        if ('manual' === $board->option('sort')) {
            $before = $request->request->get('before_id');
            $this->positions->place($board, (int) $issue->id, $this->cards->orderedIds($board, $column->statusIds(), $user), is_numeric($before) ? (int) $before : null);
        }

        return new MoveResult($issue);
    }
}

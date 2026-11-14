<?php

declare(strict_types=1);

namespace Symine\Kanban\Card;

use Doctrine\DBAL\Connection;
use Symine\Kanban\Entity\Board;

/**
 * The manual order of the cards of a board. Positions leave gaps (1024 apart), so putting a card between two others
 * usually writes a single row; a column is renumbered only the first time it is ordered or when a gap is used up.
 */
final class CardPositions
{
    private const int GAP = 1024;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Puts a card before another one of its column, or at the end.
     *
     * @param list<int> $columnIssueIds every card of the column in its current order (see CardQuery::orderedIds)
     * @param int|null  $beforeId       the card it goes before; null (or a card not in the column): at the end
     */
    public function place(Board $board, int $issueId, array $columnIssueIds, ?int $beforeId): void
    {
        $boardId = (int) $board->id;
        $others = array_values(array_filter($columnIssueIds, static fn (int $id) => $id !== $issueId));
        $index = null === $beforeId ? false : array_search($beforeId, $others, true);
        $index = false === $index ? \count($others) : $index;

        $positions = $this->positions($boardId, $others);
        // the neighbours must have a place themselves; a column nobody ordered yet gets one for every card
        $needed = \array_slice($others, 0, min($index + 1, \count($others)));
        $placed = [] === array_diff($needed, array_keys($positions));
        if ($placed) {
            $previous = $index > 0 ? $positions[$others[$index - 1]] : 0;
            $next = $index < \count($others) ? $positions[$others[$index]] : $previous + 2 * self::GAP;
            if ($next - $previous >= 2) {
                $this->write($boardId, [$issueId => intdiv($previous + $next, 2)]);

                return;
            }
        }

        array_splice($others, $index, 0, [$issueId]);
        $renumbered = [];
        foreach ($others as $n => $id) {
            $renumbered[$id] = ($n + 1) * self::GAP;
        }
        $this->write($boardId, $renumbered);
    }

    /** Forgets the positions of a board (when it is deleted) */
    public function clear(Board $board): void
    {
        $this->db->executeStatement('DELETE FROM kanban_card_positions WHERE board_id = ?', [(int) $board->id]);
    }

    /**
     * @param list<int> $issueIds
     *
     * @return array<int, int> issue id => position
     */
    private function positions(int $boardId, array $issueIds): array
    {
        $positions = [];
        foreach (array_chunk($issueIds, 500) as $chunk) {
            $rows = $this->db->fetchAllKeyValue(
                'SELECT issue_id, position FROM kanban_card_positions WHERE board_id = ? AND issue_id IN ('.implode(',', array_fill(0, \count($chunk), '?')).')',
                [$boardId, ...$chunk],
            );
            foreach ($rows as $issueId => $position) {
                $positions[(int) $issueId] = (int) $position;
            }
        }

        return $positions;
    }

    /** @param array<int, int> $positions issue id => position */
    private function write(int $boardId, array $positions): void
    {
        if ([] === $positions) {
            return;
        }
        $this->db->transactional(function () use ($boardId, $positions): void {
            $existing = $this->positions($boardId, array_keys($positions));
            foreach ($positions as $issueId => $position) {
                if (!isset($existing[$issueId])) {
                    $this->db->insert('kanban_card_positions', ['board_id' => $boardId, 'issue_id' => $issueId, 'position' => $position]);
                } elseif ($existing[$issueId] !== $position) {
                    $this->db->update('kanban_card_positions', ['position' => $position], ['board_id' => $boardId, 'issue_id' => $issueId]);
                }
            }
        });
    }
}

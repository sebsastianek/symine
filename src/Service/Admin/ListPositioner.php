<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Doctrine\DBAL\Connection;

/**
 * Port of Redmine::Acts::Positioned for tables with a "position" column
 * (custom_fields, enumerations, custom_field_enumerations...), done with plain SQL.
 *
 * $scope is a column => value map restricting the list (e.g. ['type' => 'IssuePriority', 'project_id' => null]).
 */
final class ListPositioner
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string, scalar|null> $scope */
    public function nextPosition(string $table, array $scope): int
    {
        [$where, $params] = $this->where($scope);

        return (int) $this->db->fetchOne("SELECT MAX(position) FROM $table WHERE $where", $params) + 1;
    }

    /** After insert with an explicit position: shift the others down (insert_position) */
    public function inserted(string $table, array $scope, int $id, int $position): void
    {
        [$where, $params] = $this->where($scope);
        $this->db->executeStatement("UPDATE $table SET position = position + 1 WHERE $where AND position >= ? AND id <> ?", [...$params, $position, $id]);
    }

    /** After a position change (shift_positions) */
    public function moved(string $table, array $scope, int $id, int $old, int $new): void
    {
        if ($old === $new) {
            return;
        }
        [$where, $params] = $this->where($scope);
        $offset = $old <=> $new;
        $min = min($old, $new);
        $max = max($old, $new);
        $r = $this->db->executeStatement("UPDATE $table SET position = position + ($offset) WHERE $where AND id <> ? AND position BETWEEN ? AND ?", [...$params, $id, $min, $max]);
        if ($r !== $max - $min) {
            $this->reset($table, $scope);
        }
    }

    /** After delete (remove_position) */
    public function removed(string $table, array $scope, int $id, ?int $position): void
    {
        if (null === $position) {
            return;
        }
        [$where, $params] = $this->where($scope);
        $this->db->executeStatement("UPDATE $table SET position = position - 1 WHERE $where AND position >= ? AND id <> ?", [...$params, $position, $id]);
    }

    /** reset_positions_in_list */
    public function reset(string $table, array $scope): void
    {
        [$where, $params] = $this->where($scope);
        $ids = $this->db->fetchFirstColumn("SELECT id FROM $table WHERE $where ORDER BY position, id", $params);
        foreach ($ids as $i => $id) {
            $this->db->executeStatement("UPDATE $table SET position = ? WHERE id = ?", [$i + 1, $id]);
        }
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function where(array $scope): array
    {
        $parts = ['1=1'];
        $params = [];
        foreach ($scope as $col => $value) {
            if (null === $value) {
                $parts[] = "$col IS NULL";
            } else {
                $parts[] = "$col = ?";
                $params[] = $value;
            }
        }

        return [implode(' AND ', $parts), $params];
    }
}

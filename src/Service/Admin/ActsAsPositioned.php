<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Port of Redmine::Acts::Positioned for tables with a "position" column (roles, trackers,
 * issue_statuses, ...). The record itself is saved by the caller; these helpers shift the others.
 *
 * $scope: column => value conditions delimiting the list (e.g. ['builtin' => 0] for roles).
 */
final class ActsAsPositioned
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    private function db(): Connection
    {
        return $this->em->getConnection();
    }

    /** @param array<string, scalar|null> $scope @return array{0: string, 1: list<mixed>} */
    private function where(array $scope): array
    {
        $sql = '1=1';
        $params = [];
        foreach ($scope as $col => $val) {
            if (null === $val) {
                $sql .= " AND $col IS NULL";
            } else {
                $sql .= " AND $col = ?";
                $params[] = \is_bool($val) ? (int) $val : $val;
            }
        }

        return [$sql, $params];
    }

    /** set_default_position for a new record */
    public function nextPosition(string $table, array $scope = []): int
    {
        [$w, $p] = $this->where($scope);

        return (int) $this->db()->fetchOne("SELECT MAX(position) FROM $table WHERE $w", $p) + 1;
    }

    /** after_save of a new record (or one whose scope changed) */
    public function insertPosition(string $table, int $id, int $position, array $scope = []): void
    {
        [$w, $p] = $this->where($scope);
        $this->db()->executeStatement("UPDATE $table SET position = position + 1 WHERE $w AND position >= ? AND id <> ?", [...$p, $position, $id]);
    }

    /** after_destroy */
    public function removePosition(string $table, int $id, ?int $previous, array $scope = []): void
    {
        if (null === $previous) {
            return;
        }
        [$w, $p] = $this->where($scope);
        $this->db()->executeStatement("UPDATE $table SET position = position - 1 WHERE $w AND position >= ? AND id <> ?", [...$p, $previous, $id]);
    }

    /** after_save when the position changed (record already saved with $new) */
    public function positionChanged(string $table, int $id, ?int $old, int $new, array $scope = []): void
    {
        if ($old === $new) {
            return;
        }
        if (null === $old) {
            $this->insertPosition($table, $id, $new, $scope);

            return;
        }
        $offset = $old <=> $new;
        [$min, $max] = [min($old, $new), max($old, $new)];
        [$w, $p] = $this->where($scope);
        $r = $this->db()->executeStatement("UPDATE $table SET position = position + ($offset) WHERE $w AND id <> ? AND position BETWEEN ? AND ?", [...$p, $id, $min, $max]);
        if ($r !== $max - $min) {
            $ids = $this->db()->fetchFirstColumn("SELECT id FROM $table WHERE $w ORDER BY position, id", $p);
            foreach ($ids as $i => $rid) {
                $this->db()->executeStatement("UPDATE $table SET position = ? WHERE id = ?", [$i + 1, $rid]);
            }
        }
    }
}

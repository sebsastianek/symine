<?php

declare(strict_types=1);

namespace App\Service\Tracking\Reports;

/**
 * Sums the counts of an issue report (ReportsHelper#aggregate) over the rows matching the given criteria.
 */
final class ReportAggregator
{
    /**
     * @param list<array<string, mixed>> $data     Issue.count_and_group_by rows
     * @param array<string, mixed>       $criteria column => value; "closed" is compared as a boolean
     */
    public static function aggregate(array $data, array $criteria): int
    {
        $a = 0;
        foreach ($data as $row) {
            $match = true;
            foreach ($criteria as $k => $v) {
                if ('closed' === $k) {
                    if ((bool) $row['closed'] !== (bool) $v) {
                        $match = false;
                    }
                } elseif ((string) ($row[$k] ?? '') !== (string) ($v ?? '')) {
                    $match = false;
                }
            }
            if ($match) {
                $a += (int) $row['total'];
            }
        }

        return $a;
    }
}

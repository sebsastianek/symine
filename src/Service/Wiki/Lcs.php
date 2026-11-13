<?php

declare(strict_types=1);

namespace App\Service\Wiki;

/** Longest common subsequence of two arrays (used by wiki diff and annotate). */
final class Lcs
{
    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return array<int, int> map index in $b => matched index in $a (ascending)
     */
    public static function matches(array $a, array $b): array
    {
        $n = \count($a);
        $m = \count($b);
        $map = [];
        // common prefix
        $start = 0;
        while ($start < $n && $start < $m && $a[$start] === $b[$start]) {
            $map[$start] = $start;
            ++$start;
        }
        // common suffix
        $endA = $n - 1;
        $endB = $m - 1;
        $suffix = [];
        while ($endA >= $start && $endB >= $start && $a[$endA] === $b[$endB]) {
            $suffix[$endB] = $endA;
            --$endA;
            --$endB;
        }
        $la = $endA - $start + 1;
        $lb = $endB - $start + 1;
        if ($la > 0 && $lb > 0) {
            // DP on the middle part, row by row lengths table (int arrays)
            $len = array_fill(0, $la + 1, null);
            $len[$la] = array_fill(0, $lb + 1, 0);
            for ($i = $la - 1; $i >= 0; --$i) {
                $row = array_fill(0, $lb + 1, 0);
                $next = $len[$i + 1];
                $ai = $a[$start + $i];
                for ($j = $lb - 1; $j >= 0; --$j) {
                    $row[$j] = $ai === $b[$start + $j] ? $next[$j + 1] + 1 : max($next[$j], $row[$j + 1]);
                }
                $len[$i] = $row;
            }
            $i = 0;
            $j = 0;
            while ($i < $la && $j < $lb) {
                if ($a[$start + $i] === $b[$start + $j]) {
                    $map[$start + $j] = $start + $i;
                    ++$i;
                    ++$j;
                } elseif ($len[$i + 1][$j] >= $len[$i][$j + 1]) {
                    ++$i;
                } else {
                    ++$j;
                }
            }
        }
        foreach (array_reverse($suffix, true) as $j => $i) {
            $map[$j] = $i;
        }
        ksort($map);

        return $map;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Word diff rendered like Redmine::Helpers::Diff#to_html (journals/diff, wiki diff):
 * the new text with added words in <span class="diff_in"> and removed words in <span class="diff_out">.
 */
final class JournalDiff
{
    /** @return list<string> */
    private static function words(string $text): array
    {
        $parts = preg_split('/(\s+)/', $text, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [];

        return array_values(array_filter($parts, static fn ($w) => ' ' !== $w && '' !== $w));
    }

    public static function toHtml(string $to, string $from): string
    {
        $new = self::words($to);
        $old = self::words($from);
        $n = \count($old);
        $m = \count($new);
        // LCS table
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $lcs[$i][$j] = $old[$i] === $new[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $out = [];
        $i = $j = 0;
        $added = [];
        $deleted = [];
        $flush = static function () use (&$out, &$added, &$deleted): void {
            if ([] !== $deleted) {
                $out[] = '<span class="diff_out">'.htmlspecialchars(implode(' ', $deleted)).'</span>';
                $deleted = [];
            }
            if ([] !== $added) {
                $h = array_map(static fn ($w) => htmlspecialchars($w), $added);
                $h[0] = '<span class="diff_in">'.$h[0];
                $h[\count($h) - 1] .= '</span>';
                array_push($out, ...$h);
                $added = [];
            }
        };
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $old[$i] === $new[$j]) {
                $flush();
                $out[] = htmlspecialchars($new[$j]);
                ++$i;
                ++$j;
            } elseif ($j < $m && ($i >= $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                $added[] = $new[$j++];
            } else {
                $deleted[] = $old[$i++];
            }
        }
        $flush();

        return implode(' ', $out);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Wiki;

use App\Entity\WikiContentVersion;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Port of WikiDiff / Redmine::Helpers::Diff: word diff rendered with diff_in / diff_out spans. */
#[Exclude]
final class WikiDiff
{
    public function __construct(
        public readonly WikiContentVersion $contentTo,
        public readonly WikiContentVersion $contentFrom,
        private readonly string $textTo,
        private readonly string $textFrom,
    ) {
    }

    /** @return list<string> */
    public static function words(string $text): array
    {
        $parts = preg_split('/(\s+)/u', $text, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [];
        // Ruby's split drops trailing empty strings
        while ([] !== $parts && '' === end($parts)) {
            array_pop($parts);
        }

        return array_values(array_filter($parts, static fn ($w) => ' ' !== $w));
    }

    public function toHtml(): string
    {
        return self::html($this->textTo, $this->textFrom);
    }

    public static function html(string $to, string $from): string
    {
        $wordsTo = self::words($to);
        $wordsFrom = self::words($from);
        $match = Lcs::matches($wordsFrom, $wordsTo); // index in to => index in from
        $h = static fn (string $s) => htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $out = [];
        $i = 0; // from
        $j = 0; // to
        $nTo = \count($wordsTo);
        $nFrom = \count($wordsFrom);
        $anchors = $match;
        $anchors[$nTo] = $nFrom; // sentinel
        foreach ($anchors as $toIdx => $fromIdx) {
            $added = [];
            while ($j < $toIdx) {
                $added[] = $h($wordsTo[$j++]);
            }
            $deleted = [];
            while ($i < $fromIdx) {
                $deleted[] = $wordsFrom[$i++];
            }
            if ([] !== $added) {
                $added[0] = '<span class="diff_in">'.$added[0];
                $added[\count($added) - 1] .= '</span>';
                array_push($out, ...$added);
            }
            if ([] !== $deleted) {
                $out[] = '<span class="diff_out">'.$h(implode(' ', $deleted)).'</span>';
            }
            if ($toIdx < $nTo) {
                $out[] = $h($wordsTo[$j++]);
                ++$i;
            }
        }

        return implode(' ', $out);
    }
}

<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Helpers of Redmine's ActivitiesHelper / SearchHelper / ApplicationHelper used by the activity and search views. */
final class ActivityExtension extends AbstractExtension
{
    public function __construct(private readonly AppExtension $app)
    {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('format_activity_description', self::formatActivityDescription(...), $safe),
            new TwigFunction('highlight_tokens', self::highlightTokens(...), $safe),
            new TwigFunction('activity_event_type_icon', $this->activityEventTypeIcon(...), $safe),
        ];
    }

    public function activityEventTypeIcon(string $eventType): string
    {
        $icon = match ($eventType) {
            'reply' => 'comments',
            'time-entry' => 'time',
            'message' => 'comment',
            default => $eventType,
        };

        return $this->app->spriteIcon($icon);
    }

    /** ApplicationHelper#format_activity_description */
    public static function formatActivityDescription(?string $text): string
    {
        $text = mb_substr((string) $text, 0, 10240);
        $text = preg_replace('/(^>.*?(?:\r?\n))(?:>.*?(?:\r?\n)+)+/m', "\$1> ...\n", $text) ?? $text;
        $text = preg_replace('/[\r\n]*<(pre|code)>.*$/s', '', $text) ?? $text;
        if (mb_strlen($text) > 240) {
            $text = mb_substr($text, 0, 237).'...';
        }

        return preg_replace('/[\r\n]+/', '<br>', htmlspecialchars($text)) ?? '';
    }

    /**
     * SearchHelper#highlight_tokens.
     *
     * @param list<string> $tokens
     */
    public static function highlightTokens(?string $text, array $tokens): string
    {
        if (null === $text || '' === $text) {
            return '';
        }
        if ([] === $tokens) {
            return htmlspecialchars($text);
        }
        $re = '/('.implode('|', array_map(static fn (string $t) => preg_quote($t, '/'), $tokens)).')/iu';
        $parts = preg_split($re, $text, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $lower = array_map('mb_strtolower', $tokens);
        $result = '';
        foreach ($parts as $i => $words) {
            if (mb_strlen($result) > 1200) {
                $result .= '...';
                break;
            }
            if (0 === $i % 2) {
                $result .= htmlspecialchars(mb_strlen($words) > 100 ? mb_substr($words, 0, 45).' ... '.mb_substr($words, -45) : $words);
            } else {
                $idx = array_search(mb_strtolower($words), $lower, true);
                $t = (false === $idx ? 0 : $idx) % 4;
                $result .= '<span class="highlight token-'.$t.'">'.htmlspecialchars($words).'</span>';
            }
        }

        return $result;
    }
}

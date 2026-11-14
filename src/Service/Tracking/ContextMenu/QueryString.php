<?php

declare(strict_types=1);

namespace App\Service\Tracking\ContextMenu;

/**
 * Query strings written the way Rails writes them (Hash#to_query): keys sorted, "a[]=" for lists.
 */
final class QueryString
{
    /** Hash#to_query */
    public static function toQuery(array $params, ?string $prefix = null): string
    {
        $parts = [];
        if (!array_is_list($params) || null === $prefix) {
            ksort($params, \SORT_STRING);
        }
        foreach ($params as $k => $v) {
            if (null === $v) {
                continue;
            }
            $key = null === $prefix ? (string) $k : (array_is_list($params) ? $prefix.'[]' : $prefix.'['.$k.']');
            if (\is_array($v)) {
                $sub = self::toQuery($v, $key);
                if ('' !== $sub) {
                    $parts[] = $sub;
                }
            } else {
                $parts[] = rawurlencode($key).'='.rawurlencode(\is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
            }
        }

        return implode('&', $parts);
    }
}

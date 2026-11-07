<?php

declare(strict_types=1);

namespace App\Redmine;

use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Decodes values serialized by Ruby on Rails (Psych YAML) as stored in a Redmine database.
 *
 * Ruby symbols (":view_issues") become plain strings ("view_issues"), Ruby specific tags
 * (e.g. "!ruby/hash:ActiveSupport::HashWithIndifferentAccess") are stripped.
 */
final class RubyYaml
{
    public static function isYaml(?string $raw): bool
    {
        return null !== $raw && str_starts_with(ltrim($raw), '---');
    }

    public static function decode(string $raw): mixed
    {
        // Ruby "!binary |" values are base64 encoded strings
        $raw = preg_replace('/!binary\s*\|/', '!rubybinary |', $raw) ?? $raw;
        // Ruby symbol keys (":key: value") are not valid in Symfony YAML when unquoted
        $raw = preg_replace('/^(\s*(?:-\s+)?):([A-Za-z_][\w?!]*):(\s|$)/m', '$1$2:$3', $raw) ?? $raw;

        $value = Yaml::parse($raw, Yaml::PARSE_CUSTOM_TAGS | Yaml::PARSE_DATETIME);

        return self::normalize($value);
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof TaggedValue) {
            if ('rubybinary' === $value->getTag()) {
                return base64_decode(preg_replace('/\s+/', '', (string) $value->getValue()) ?? '', true) ?: '';
            }

            return self::normalize($value->getValue());
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (\is_string($value) && preg_match('/^:([A-Za-z_][\w?!]*)$/', $value, $m)) {
            return $m[1];
        }
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (\is_string($k) && preg_match('/^:([A-Za-z_][\w?!]*)$/', $k, $m)) {
                    $k = $m[1];
                }
                $out[$k] = self::normalize($v);
            }

            return $out;
        }

        return $value;
    }
}

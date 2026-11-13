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
        // quoted symbol keys (:"foo bar": value)
        $raw = preg_replace('/^(\s*(?:-\s+)?):("(?:[^"\\\\]|\\\\.)*"):(\s|$)/m', '$1$2:$3', $raw) ?? $raw;
        // complex binary keys emitted by Psych ("? !binary |-\n  base64\n: value")
        $raw = preg_replace_callback('/^([ ]*)\?\s+!rubybinary \|-?\n((?:\1[ ]+[A-Za-z0-9+\/=]+\n)+)\1:/m', static function (array $m): string {
            $key = base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true);

            return $m[1].json_encode(false === $key ? '' : $key, \JSON_UNESCAPED_UNICODE).':';
        }, $raw) ?? $raw;

        // a tag on the document start line ("--- !ruby/object:Foo") is dropped by the parser: keep it
        $rootTag = null;
        if (preg_match('/\A\s*---[ \t]+!(\S+)[ \t]*\n/', $raw, $m)) {
            $rootTag = $m[1];
            $raw = "---\n".substr($raw, \strlen($m[0]));
        }

        $value = Yaml::parse($raw, Yaml::PARSE_CUSTOM_TAGS | Yaml::PARSE_DATETIME);
        if (null !== $rootTag && 'rubybinary' !== $rootTag) {
            $value = new TaggedValue($rootTag, $value);
        }

        return self::normalize($value);
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof TaggedValue) {
            $tag = $value->getTag();
            if ('rubybinary' === $tag) {
                return base64_decode(preg_replace('/\s+/', '', (string) $value->getValue()) ?? '', true) ?: '';
            }
            $inner = $value->getValue();
            // ActionController::Parameters (queries saved from controller params): keep the parameters hash
            if (str_starts_with($tag, 'ruby/object:ActionController::Parameters') && \is_array($inner) && \array_key_exists('parameters', $inner)) {
                return self::normalize($inner['parameters']);
            }
            // Ruby Set: serialized as {hash: {elem: true}}
            if ('ruby/object:Set' === $tag && \is_array($inner) && \is_array($inner['hash'] ?? null)) {
                return array_map(self::normalize(...), array_keys($inner['hash']));
            }
            // ActiveSupport::TimeWithZone: keep the UTC time
            if ('ruby/object:ActiveSupport::TimeWithZone' === $tag && \is_array($inner) && isset($inner['utc'])) {
                return self::normalize($inner['utc']);
            }

            return self::normalize($inner);
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

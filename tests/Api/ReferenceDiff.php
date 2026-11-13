<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Structural comparison of Redmine and Symine API payloads (live differential tests).
 *
 * - datetimes are compared by type only ("<datetime>"), volatile keys (last_login_on, updated_on, ...) are ignored;
 * - absolute URLs lose their scheme/host ("http://localhost:3300/x" == "http://localhost/x");
 * - lists of objects with an "id" are compared by id: records that only exist in the reference (created by
 *   somebody else on the shared reference instance) are ignored, records missing from the reference are reported;
 * - every other difference (missing/extra key, type, value, order) is reported with its path.
 */
final class ReferenceDiff
{
    /** keys whose value changes with usage of the reference instance */
    public const array VOLATILE = ['last_login_on', 'updated_on', 'passwd_changed_on', 'last_activity_on', 'api_key', 'twofa_scheme'];

    /** @var list<string> */
    public array $diffs = [];
    /** @var list<string> */
    public array $ignored = [];

    public function __construct(private readonly array $ignoreKeys = [])
    {
    }

    public static function normalize(mixed $v): mixed
    {
        if (\is_string($v)) {
            if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?(Z|[+-]\d\d:?\d\d)$/', $v)) {
                return '<datetime>';
            }

            return (string) preg_replace('#https?://[^/\s"<]+/#', 'http://HOST/', $v);
        }
        if (\is_array($v)) {
            return array_map(self::normalize(...), $v);
        }

        return $v;
    }

    public function compare(mixed $ref, mixed $sym, string $path = ''): void
    {
        $ref = self::normalize($ref);
        $sym = self::normalize($sym);
        $this->walk($ref, $sym, $path);
    }

    private function walk(mixed $ref, mixed $sym, string $path): void
    {
        if (\is_array($ref) && \is_array($sym)) {
            if (array_is_list($ref) && array_is_list($sym) && ([] !== $ref || [] !== $sym)) {
                $this->walkList($ref, $sym, $path);

                return;
            }
            if (!array_is_list($ref) || !array_is_list($sym)) {
                foreach ($ref as $k => $v) {
                    if (\in_array($k, self::VOLATILE, true) || \in_array($k, $this->ignoreKeys, true)) {
                        continue;
                    }
                    if (!\array_key_exists($k, $sym)) {
                        $this->diffs[] = "$path.$k: missing in Symine (Redmine: ".self::short($v).')';
                        continue;
                    }
                    $this->walk($v, $sym[$k], "$path.$k");
                }
                foreach ($sym as $k => $v) {
                    if (!\array_key_exists($k, $ref) && !\in_array($k, self::VOLATILE, true) && !\in_array($k, $this->ignoreKeys, true)) {
                        $this->diffs[] = "$path.$k: extra in Symine (".self::short($v).')';
                    }
                }
                if (array_keys(array_intersect_key($ref, $sym)) !== array_keys(array_intersect_key($sym, $ref))) {
                    $this->diffs[] = "$path: key order differs (Redmine: ".implode(',', array_keys($ref)).' / Symine: '.implode(',', array_keys($sym)).')';
                }

                return;
            }

            return;
        }
        if (get_debug_type($ref) !== get_debug_type($sym)) {
            $this->diffs[] = "$path: type ".get_debug_type($ref).' vs '.get_debug_type($sym).' (Redmine: '.self::short($ref).' / Symine: '.self::short($sym).')';

            return;
        }
        if ($ref !== $sym) {
            $this->diffs[] = "$path: Redmine ".self::short($ref).' / Symine '.self::short($sym);
        }
    }

    private function walkList(array $ref, array $sym, string $path): void
    {
        $byId = static fn (array $l) => array_reduce($l, static fn ($c, $i) => $c && \is_array($i) && isset($i['id']) && !\is_array($i['id']), true)
            && \count($l) === \count(array_unique(array_map(static fn ($i) => (string) $i['id'], $l)));
        if ($byId($ref) && $byId($sym)) {
            $refIds = array_map(static fn ($i) => $i['id'], $ref);
            $symIds = array_map(static fn ($i) => $i['id'], $sym);
            $refOnly = array_values(array_diff($refIds, $symIds));
            foreach ($refOnly as $id) {
                $this->ignored[] = "$path: id $id only exists in the reference";
            }
            foreach (array_diff($symIds, $refIds) as $id) {
                $this->diffs[] = "$path: id $id missing in Redmine";
            }
            $commonRef = array_values(array_filter($refIds, static fn ($id) => \in_array($id, $symIds, true)));
            $commonSym = array_values(array_filter($symIds, static fn ($id) => \in_array($id, $refIds, true)));
            if ($commonRef !== $commonSym) {
                $this->diffs[] = "$path: order Redmine [".implode(',', $commonRef).'] / Symine ['.implode(',', $commonSym).']';
            }
            $symById = array_combine(array_map('strval', $symIds), $sym);
            foreach ($ref as $item) {
                if (isset($symById[(string) $item['id']])) {
                    $this->walk($item, $symById[(string) $item['id']], $path.'[id='.$item['id'].']');
                }
            }

            return;
        }
        if (\count($ref) !== \count($sym)) {
            $this->diffs[] = "$path: ".\count($ref).' items in Redmine / '.\count($sym).' in Symine (Redmine: '.self::short($ref).' / Symine: '.self::short($sym).')';

            return;
        }
        foreach ($ref as $i => $v) {
            $this->walk($v, $sym[$i], $path."[$i]");
        }
    }

    /** XML document as a comparable array: element name, attributes and children/text */
    public static function xmlToArray(\SimpleXMLElement $el): array
    {
        $attrs = [];
        foreach ($el->attributes() as $k => $v) {
            $attrs[$k] = (string) $v;
        }
        $children = [];
        foreach ($el->children() as $child) {
            if (!\in_array($child->getName(), self::VOLATILE, true)) {
                $children[] = self::xmlToArray($child);
            }
        }
        $node = ['@name' => $el->getName()];
        if ([] !== $attrs) {
            $node['@attributes'] = $attrs;
        }
        if ([] !== $children) {
            $node['@children'] = $children;
        } else {
            $node['@text'] = (string) $el;
        }

        return $node;
    }

    private static function short(mixed $v): string
    {
        $s = json_encode($v, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return mb_strlen((string) $s) > 160 ? mb_substr((string) $s, 0, 160).'…' : (string) $s;
    }
}

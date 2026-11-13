<?php

declare(strict_types=1);

namespace App\Query;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
/** Port of Redmine::SortCriteria: at most 3 [key, 'asc'|'desc'] pairs. */
#[Exclude]
final class SortCriteria
{
    /** @var list<array{0: string, 1: string}> */
    private array $criteria = [];

    /** @param string|array<mixed>|null $arg "priority:desc,id" or [[key, order], ...] or {"0": [key, order]} */
    public function __construct(string|array|null $arg = null)
    {
        $list = [];
        if (\is_string($arg)) {
            foreach (explode(',', $arg) as $s) {
                $parts = \array_slice(explode(':', $s), 0, 2);
                $list[] = $parts;
            }
        } elseif (\is_array($arg)) {
            $list = array_values($arg);
        }
        $this->criteria = self::normalize($list);
    }

    /** @param list<mixed> $list @return list<array{0: string, 1: string}> */
    private static function normalize(array $list): array
    {
        $out = [];
        $seen = [];
        foreach ($list as $s) {
            $s = \is_array($s) ? array_values($s) : [$s];
            $key = isset($s[0]) ? trim((string) $s[0]) : '';
            if ('' === $key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $last = \count($s) > 0 ? $s[\count($s) - 1] : null;
            $order = (false === $last || 'desc' === (string) $last) ? 'desc' : 'asc';
            $out[] = [$key, $order];
        }

        return \array_slice($out, 0, 3);
    }

    /** @return list<array{0: string, 1: string}> */
    public function toArray(): array
    {
        return $this->criteria;
    }

    public function toParam(): string
    {
        return implode(',', array_map(static fn (array $c) => $c[0].('desc' === $c[1] ? ':desc' : ''), $this->criteria));
    }

    public function isEmpty(): bool
    {
        return [] === $this->criteria;
    }

    public function add(string $key, ?string $order): self
    {
        $c = clone $this;
        $list = array_values(array_filter($c->criteria, static fn ($x) => $x[0] !== $key));
        array_unshift($list, [$key, $order ?? 'asc']);
        $c->criteria = self::normalize($list);

        return $c;
    }

    public function firstKey(): ?string
    {
        return $this->criteria[0][0] ?? null;
    }

    public function isFirstAsc(): bool
    {
        return 'asc' === ($this->criteria[0][1] ?? null);
    }

    public function keyAt(int $i): ?string
    {
        return $this->criteria[$i][0] ?? null;
    }

    public function orderAt(int $i): ?string
    {
        return $this->criteria[$i][1] ?? null;
    }

    public function orderFor(string $key): ?string
    {
        foreach ($this->criteria as [$k, $o]) {
            if ($k === $key) {
                return $o;
            }
        }

        return null;
    }

    /**
     * @param array<string, list<string>|null> $sortableColumns name => SQL expressions
     *
     * @return list<string>
     */
    public function sortClause(array $sortableColumns): array
    {
        $sql = [];
        foreach ($this->criteria as [$k, $o]) {
            $s = $sortableColumns[$k] ?? null;
            if (null === $s) {
                continue;
            }
            foreach ($s as $c) {
                $sql[] = self::appendOrder($c, $o);
            }
        }

        return $sql;
    }

    public static function appendOrder(string $criterion, string $order): string
    {
        if (preg_match('/ (asc|desc)$/i', $criterion)) {
            return $criterion;
        }

        return $criterion.' '.strtoupper($order);
    }
}

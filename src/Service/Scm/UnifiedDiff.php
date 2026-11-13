<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Port of lib/redmine/unified_diff.rb, diff_table.rb and diff.rb: parses a unified diff into
 * tables (one per file) of lines, inline or side by side ("sbs").
 */
#[Exclude]
final class UnifiedDiff
{
    /** @var list<DiffTable> */
    public array $tables = [];
    public bool $truncated = false;

    /** @param list<string>|string $diff */
    public function __construct(array|string $diff, public readonly string $type = 'inline', ?int $maxLines = null)
    {
        $lines = \is_string($diff) ? explode("\n", $diff) : array_map(static fn ($l) => rtrim($l, "\r\n"), $diff);
        $n = \count($lines);
        if ($n > 1 && str_starts_with($lines[$n - 2], '--') && preg_match('/^[0-9]/', $lines[$n - 1])) {
            array_splice($lines, -2);
        }
        $count = 0;
        $table = new DiffTable($type);
        foreach ($lines as $raw) {
            $line = mb_check_encoding($raw, 'UTF-8') ? $raw : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
            if (!$table->addLine($line)) {
                if (\count($table->lines) > 0) {
                    $this->tables[] = $table;
                }
                $table = new DiffTable($type);
            }
            ++$count;
            if (null !== $maxLines && $count > $maxLines) {
                $this->truncated = true;
                break;
            }
        }
        if (\count($table->lines) > 0) {
            $this->tables[] = $table;
        }
    }
}

/** @internal */
final class DiffTable
{
    public ?string $fileName = null;
    public ?string $previousFileName = null;
    /** @var list<DiffLine> */
    public array $lines = [];
    private bool $parsing = false;
    private int $added = 0;
    private int $removed = 0;
    private int $lineNumL = 0;
    private int $lineNumR = 0;
    private bool $gitDiff = false;

    public function __construct(private readonly string $type = 'inline')
    {
    }

    public function addLine(string $line): bool
    {
        if (!$this->parsing) {
            if (preg_match('/^(---|\+\+\+) (.*)$/', $line, $m)) {
                $this->setFileName($m[2]);
            } elseif (preg_match('/^@@ (\+|\-)(\d+)(,\d+)? (\+|\-)(\d+)(,\d+)? @@/', $line, $m)) {
                $this->lineNumL = (int) $m[2];
                $this->lineNumR = (int) $m[5];
                $this->parsing = true;
            }
        } else {
            if (preg_match('/^[^\+\-\s@\\\\]/', $line)) {
                $this->parsing = false;

                return false;
            }
            if (preg_match('/^@@ (\+|\-)(\d+)(,\d+)? (\+|\-)(\d+)(,\d+)? @@/', $line, $m)) {
                $this->lineNumL = (int) $m[2];
                $this->lineNumR = (int) $m[5];
            } else {
                $this->parseLine($line);
            }
        }

        return true;
    }

    /** @return list<array{0: bool, 1: DiffLine}> [spacing, line] */
    public function eachLine(): array
    {
        $out = [];
        $prevL = null;
        $prevR = null;
        foreach ($this->lines as $line) {
            $spacing = null !== $prevL && null !== $prevR && $line->nbLineLeft !== (string) ($prevL + 1) && $line->nbLineRight !== (string) ($prevR + 1);
            $out[] = [$spacing, $line];
            if ((int) $line->nbLineLeft > 0) {
                $prevL = (int) $line->nbLineLeft;
            }
            if ((int) $line->nbLineRight > 0) {
                $prevR = (int) $line->nbLineRight;
            }
        }

        return $out;
    }

    private function setFileName(string $arg): void
    {
        $bothGit = false;
        if (null === $this->fileName) {
            if (preg_match('#^(a/|/dev/null)#', $arg)) {
                $this->gitDiff = true;
            }
        } else {
            $bothGit = $this->gitDiff && preg_match('#^(b/|/dev/null)#', $arg);
        }
        if ($bothGit) {
            if (null !== $this->fileName && '/dev/null' === $arg) {
                $this->fileName = self::stripPrefix($this->fileName, 'a/');
            } else {
                if ('/dev/null' !== $this->fileName) {
                    $this->previousFileName = self::stripPrefix((string) $this->fileName, 'a/');
                }
                $this->fileName = self::stripPrefix($arg, 'b/');
                if ($this->previousFileName === $this->fileName) {
                    $this->previousFileName = null;
                }
            }
        } else {
            $this->fileName = $arg;
        }
    }

    private static function stripPrefix(string $s, string $prefix): string
    {
        return str_starts_with($s, $prefix) ? substr($s, \strlen($prefix)) : $s;
    }

    private function diffForAddedLine(): DiffLine
    {
        if ('sbs' === $this->type && $this->removed > 0 && $this->added < $this->removed) {
            return $this->lines[\count($this->lines) - ($this->removed - $this->added)];
        }
        $d = new DiffLine();
        $this->lines[] = $d;

        return $d;
    }

    private function parseLine(string $line): void
    {
        if (str_starts_with($line, '+')) {
            $d = $this->diffForAddedLine();
            $d->lineRight = substr($line, 1);
            $d->nbLineRight = (string) $this->lineNumR;
            $d->typeDiffRight = 'diff_in';
            ++$this->lineNumR;
            ++$this->added;
        } elseif (str_starts_with($line, '-')) {
            $d = new DiffLine();
            $d->lineLeft = substr($line, 1);
            $d->nbLineLeft = (string) $this->lineNumL;
            $d->typeDiffLeft = 'diff_out';
            $this->lines[] = $d;
            ++$this->lineNumL;
            ++$this->removed;
        } else {
            $this->writeOffsets();
            if ('' === $line || ctype_space($line[0])) {
                $d = new DiffLine();
                $d->lineRight = $d->lineLeft = (string) substr($line, 1);
                $d->nbLineRight = (string) $this->lineNumR;
                $d->nbLineLeft = (string) $this->lineNumL;
                $this->lines[] = $d;
                ++$this->lineNumL;
                ++$this->lineNumR;
            }
        }
    }

    private function writeOffsets(): void
    {
        if ($this->added > 0 && $this->added === $this->removed) {
            $n = \count($this->lines);
            for ($i = 0; $i < $this->added; ++$i) {
                $line = $this->lines[$n - 1 - $i];
                $removed = 'sbs' === $this->type ? $line : $this->lines[$n - 1 - $this->added - $i];
                $offsets = self::offsets($removed->lineLeft, $line->lineRight);
                $removed->offsets = $offsets;
                $line->offsets = $offsets;
            }
        }
        $this->added = 0;
        $this->removed = 0;
    }

    /** @return array{0: int, 1: int}|null */
    private static function offsets(string $left, string $right): ?array
    {
        if ('' === $left || '' === $right || $left === $right) {
            return null;
        }
        $l = mb_str_split($left);
        $r = mb_str_split($right);
        $max = min(\count($l), \count($r));
        $start = 0;
        while ($start < $max && $l[$start] === $r[$start]) {
            ++$start;
        }
        $end = -1;
        while ($end >= -($max - $start) && $l[\count($l) + $end] === $r[\count($r) + $end]) {
            --$end;
        }

        return 0 === $start && -1 === $end ? null : [$start, $end];
    }
}

/** @internal one line of a diff table (Redmine::Diff) */
final class DiffLine
{
    public string $nbLineLeft = '';
    public string $lineLeft = '';
    public string $nbLineRight = '';
    public string $lineRight = '';
    public string $typeDiffRight = '';
    public string $typeDiffLeft = '';
    /** @var array{0: int, 1: int}|null */
    public ?array $offsets = null;

    public function typeDiff(): string
    {
        return 'diff_in' === $this->typeDiffRight ? $this->typeDiffRight : $this->typeDiffLeft;
    }

    public function line(): string
    {
        return 'diff_in' === $this->typeDiffRight ? $this->lineRight : $this->lineLeft;
    }

    public function htmlLineLeft(): string
    {
        return self::toHtml($this->lineLeft, $this->offsets);
    }

    public function htmlLineRight(): string
    {
        return self::toHtml($this->lineRight, $this->offsets);
    }

    public function htmlLine(): string
    {
        return self::toHtml($this->line(), $this->offsets);
    }

    private static function toHtml(string $line, ?array $offsets): string
    {
        $e = static fn (string $s) => htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        if (null === $offsets) {
            return $e($line);
        }
        $chars = mb_str_split($line);
        $n = \count($chars);
        [$start, $end] = $offsets;
        $endIdx = $n + $end; // inclusive index of last highlighted char
        $s = 0 !== $start ? $e(implode('', \array_slice($chars, 0, $start))) : '';
        $s .= '<span>'.$e(implode('', \array_slice($chars, $start, max(0, $endIdx - $start + 1)))).'</span>';
        if (-1 !== $end) {
            $s .= $e(implode('', \array_slice($chars, $endIdx + 1)));
        }

        return $s;
    }
}

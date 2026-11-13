<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Port of lib/redmine/scm/adapters/git_adapter.rb: read-only access to a Git repository via the git CLI.
 * Methods return null when the git command fails (ScmCommandAborted in Redmine).
 */
#[Exclude]
class GitAdapter extends AbstractAdapter
{
    public const array DEFAULT_BRANCH_NAMES = ['main', 'master'];

    /** @var list<ScmBranch>|null */
    private ?array $branches = null;
    /** @var list<string>|null */
    private ?array $tags = null;

    public function __construct(string $url, string $gitBin = 'git', ?string $pathEncoding = null, ?string $rootUrl = null)
    {
        parent::__construct($url, $rootUrl, null, null, $pathEncoding, $gitBin);
    }

    public static function defaultCommand(): string
    {
        return 'git';
    }

    public function adapterName(): string
    {
        return 'Git';
    }

    public static function clientAvailable(?string $bin = null): bool
    {
        return [] !== static::clientVersion($bin);
    }

    public function pathEncoding(): ?string
    {
        return $this->pathEncoding;
    }

    public function info(): ?ScmInfo
    {
        try {
            return new ScmInfo($this->url, $this->lastrev('', null));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Git dir: the configured path, or its .git subdirectory for a working copy */
    public function gitDir(): string
    {
        $url = rtrim($this->url, '/');
        if (is_dir($url.'/.git')) {
            return $url.'/.git';
        }

        return $url;
    }

    /** @return list<ScmBranch>|null */
    public function branches(): ?array
    {
        if (null !== $this->branches) {
            return $this->branches;
        }
        $out = $this->git(['branch', '--no-color', '--verbose', '--no-abbrev']);
        if (null === $out) {
            return null;
        }
        $branches = [];
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^\s*(\*?)\s*(.*?)\s+([0-9a-f]{40}).*$/', $line, $m)) {
                $branches[] = new ScmBranch((string) $this->fromRepo($m[2]), $m[3], '*' === $m[1]);
            }
        }
        usort($branches, static fn (ScmBranch $a, ScmBranch $b) => strcmp($a->name, $b->name));

        return $this->branches = $branches;
    }

    /** @return list<string>|null */
    public function tags(): ?array
    {
        if (null !== $this->tags) {
            return $this->tags;
        }
        $out = $this->git(['tag']);
        if (null === $out) {
            return null;
        }
        $tags = array_values(array_filter(array_map('trim', explode("\n", $out)), static fn ($t) => '' !== $t));
        sort($tags);

        return $this->tags = array_map(fn ($t) => (string) $this->fromRepo($t), $tags);
    }

    public function defaultBranch(): ?string
    {
        $branches = $this->branches();
        if (null === $branches || [] === $branches) {
            return null;
        }
        foreach ($branches as $b) {
            if ($b->isDefault) {
                return $b->name;
            }
        }
        foreach ($branches as $b) {
            if (\in_array($b->name, self::DEFAULT_BRANCH_NAMES, true)) {
                return $b->name;
            }
        }

        return $branches[0]->name;
    }

    /** @return list<ScmEntry>|null */
    public function entries(?string $path = null, ?string $identifier = null, array|bool $options = []): ?array
    {
        $reportLastCommit = \is_bool($options) ? $options : (bool) ($options['report_last_commit'] ?? false);
        $p = (string) $this->toRepo((string) $path);
        $identifier ??= 'HEAD';
        $out = $this->git(['ls-tree', '-l', $this->toRepo($identifier).':'.$p]);
        if (null === $out) {
            return null;
        }
        $entries = [];
        foreach (explode("\n", $out) as $line) {
            if (!preg_match('/^\d+\s+(\w+)\s+([0-9a-f]{40})\s+([0-9-]+)\t(.+)$/', $line, $m)) {
                continue;
            }
            $name = $m[4];
            if (isset($entries[$name])) {
                continue;
            }
            $full = '' === $p ? $name : $p.'/'.$name;
            $isDir = 'tree' === $m[1];
            $entries[$name] = new ScmEntry(
                (string) $this->fromRepo($name),
                (string) $this->fromRepo($full),
                $isDir ? 'dir' : 'file',
                $isDir ? null : (int) $m[3],
                $reportLastCommit ? $this->lastrev($full, $identifier) : new ScmRevision(),
            );
        }

        return self::sortByName(array_values($entries));
    }

    public function lastrev(?string $path, ?string $rev): ?ScmRevision
    {
        if (null === $path) {
            return null;
        }
        $args = ['log', '--no-color', '--encoding=UTF-8', '--date=iso', '--pretty=fuller', '--no-merges', '-n', '1'];
        if ($this->versionAbove([2, 9])) {
            $args[] = '--no-renames';
        }
        if (null !== $rev) {
            $args[] = $rev;
        }
        if ('' !== $path) {
            $args[] = '--';
            $args[] = $path;
        }
        $out = $this->git($args);
        if (null === $out) {
            return null;
        }
        $lines = explode("\n", $out);
        if (\count($lines) < 5 || !preg_match('/^commit\s+(\S+)/', $lines[0], $id)
            || !preg_match('/Author:\s+(.*)$/', $lines[1], $author) || !preg_match('/CommitDate:\s+(.*)$/', $lines[4], $date)) {
            return null;
        }

        return new ScmRevision($id[1], $id[1], $author[1], new \DateTimeImmutable($date[1]));
    }

    /**
     * @param array{reverse?: bool, limit?: int, includes?: list<string>, excludes?: list<string>} $options
     *
     * @return list<ScmRevision>
     */
    public function revisions(?string $path = null, ?string $identifierFrom = null, ?string $identifierTo = null, array $options = []): array
    {
        $args = ['log', '--no-color', '--encoding=UTF-8', '--raw', '--date=iso', '--pretty=fuller', '--parents', '--stdin'];
        if ($this->versionAbove([2, 9])) {
            $args[] = '--no-renames';
        }
        if ($options['reverse'] ?? false) {
            $args[] = '--reverse';
        }
        if (isset($options['limit'])) {
            $args[] = '-n';
            $args[] = (string) (int) $options['limit'];
        }
        if (null !== $path && '' !== $path) {
            $args[] = '--';
            $args[] = (string) $this->toRepo($path);
        }
        $revs = [];
        if (null !== $identifierFrom || null !== $identifierTo) {
            $revs[] = (null !== $identifierFrom ? $this->toRepo($identifierFrom).'..' : '').(null !== $identifierTo ? $this->toRepo($identifierTo) : '');
        } else {
            foreach ($options['includes'] ?? [] as $r) {
                $revs[] = $r;
            }
            foreach ($options['excludes'] ?? [] as $r) {
                $revs[] = '^'.$r;
            }
        }
        $out = $this->git($args, implode("\n", $revs)."\n");
        if (null === $out) {
            return [];
        }

        $result = [];
        $changeset = [];
        $files = [];
        $parsing = 0; // 0: headers, 1: description, 2: files
        $flush = function () use (&$changeset, &$files, &$result): void {
            $result[] = new ScmRevision(
                $changeset['commit'],
                $changeset['commit'],
                $changeset['author'] ?? null,
                isset($changeset['date']) ? new \DateTimeImmutable($changeset['date']) : null,
                $changeset['description'] ?? '',
                $files,
                $changeset['parents'] ?? [],
            );
            $changeset = [];
            $files = [];
        };
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^commit ([0-9a-f]{40})((?: [0-9a-f]{40})*)$/', $line, $m)) {
                if (1 === $parsing || 2 === $parsing) {
                    $flush();
                }
                $parsing = 0;
                $changeset['commit'] = $m[1];
                if ('' !== trim($m[2])) {
                    $changeset['parents'] = explode(' ', trim($m[2]));
                }
            } elseif (0 === $parsing && preg_match('/^(\w+):\s*(.*)$/', $line, $m)) {
                if ('Author' === $m[1]) {
                    $changeset['author'] = $m[2];
                } elseif ('CommitDate' === $m[1]) {
                    $changeset['date'] = $m[2];
                }
            } elseif (0 === $parsing && '' === $line) {
                $parsing = 1;
                $changeset['description'] = '';
            } elseif ((1 === $parsing || 2 === $parsing) && preg_match('/^:\d+\s+\d+\s+[0-9a-f.]+\s+[0-9a-f.]+\s+(\w)\t(.+)$/', $line, $m)) {
                $parsing = 2;
                $files[] = ['action' => $m[1], 'path' => (string) $this->fromRepo($m[2])];
            } elseif ((1 === $parsing || 2 === $parsing) && preg_match('/^:\d+\s+\d+\s+[0-9a-f.]+\s+[0-9a-f.]+\s+(\w)\d+\s+(\S+)\t(.+)$/', $line, $m)) {
                $parsing = 2;
                $files[] = ['action' => $m[1], 'path' => (string) $this->fromRepo($m[3])];
            } elseif (1 === $parsing && '' === $line) {
                $parsing = 2;
            } elseif (1 === $parsing) {
                $changeset['description'] .= substr($line, 4)."\n";
            }
        }
        if (isset($changeset['commit'])) {
            $flush();
        }

        return $result;
    }

    /** @return list<string>|null diff lines (with line endings) */
    public function diff(?string $path, ?string $identifierFrom, ?string $identifierTo = null): ?array
    {
        $path = (string) $path;
        $identifierFrom = (string) $identifierFrom;
        $args = null !== $identifierTo ? ['diff', '--no-color', $identifierTo, $identifierFrom] : ['show', '--no-color', $identifierFrom];
        if ($this->versionAbove([2, 9])) {
            $args[] = '--no-renames';
        }
        if ('' !== $path) {
            $args[] = '--';
            $args[] = (string) $this->toRepo($path);
        }
        $out = $this->git($args);
        if (null === $out) {
            return null;
        }
        $lines = self::lines($out);
        if ('' !== $path && [] === array_filter($lines, static fn ($l) => str_starts_with($l, 'diff --'))) {
            return [];
        }

        return $lines;
    }

    public function annotate(string $path, ?string $identifier = null): ?ScmAnnotate
    {
        $identifier = null === $identifier || '' === $identifier ? 'HEAD' : $identifier;
        $content = $this->git(['blame', '--encoding=UTF-8', '-p', (string) $this->toRepo($identifier), '--', (string) $this->toRepo($path)]);
        if (null === $content || self::isBinary($content)) {
            return null;
        }
        $blame = new ScmAnnotate();
        $id = '';
        $authors = [];
        $previous = [];
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^([0-9a-f]{39,40})\s.*/', $line, $m)) {
                $id = $m[1];
            } elseif (preg_match('/^author (.+)/', $line, $m)) {
                $authors[$id] = trim($m[1]);
            } elseif (preg_match('/^previous (.+)/', $line, $m)) {
                $previous[$id] = trim($m[1]);
            } elseif (preg_match('/^\t(.*)/', $line, $m)) {
                $blame->addLine($m[1], new ScmRevision($id, $id, $authors[$id] ?? null), $previous[$id] ?? null);
                $id = '';
            }
        }

        return $blame;
    }

    public function cat(string $path, ?string $identifier = null): ?string
    {
        return $this->git(['show', '--no-color', $this->toRepo($identifier ?? 'HEAD').':'.$this->toRepo($path)]);
    }

    public function isValidName(?string $name): bool
    {
        if (null === $name) {
            return false;
        }
        if ('HEAD' === $name || str_starts_with($name, '-') || str_starts_with($name, '/') || str_starts_with($name, 'refs/heads/') || str_starts_with($name, 'refs/remotes/')) {
            return false;
        }

        return null !== $this->git(['show-ref', '--heads', '--tags', '--quiet', '--', $name]);
    }

    private function versionAbove(array $v): bool
    {
        $cv = self::clientVersion($this->bin);

        return [] !== $cv && $cv >= $v;
    }

    /** UTF-8 => path encoding */
    private function toRepo(?string $s): ?string
    {
        return self::scmIconv($this->pathEncoding, 'UTF-8', $s) ?? $s;
    }

    /** path encoding => UTF-8 */
    private function fromRepo(?string $s): ?string
    {
        return self::scmIconv('UTF-8', $this->pathEncoding, $s) ?? $s;
    }

    /** Runs git with --git-dir; returns stdout or null when the command fails */
    protected function git(array $args, ?string $stdin = null): ?string
    {
        $full = ['--git-dir', $this->gitDir()];
        if ($this->versionAbove([1, 7, 2])) {
            array_push($full, '-c', 'core.quotepath=false', '-c', 'log.decorate=no');
        }
        [$code, $out] = $this->shellout(array_merge($full, $args), $stdin, ['GIT_TERMINAL_PROMPT' => '0']);

        return 0 === $code ? $out : null;
    }
}

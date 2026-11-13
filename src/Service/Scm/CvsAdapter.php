<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Port of lib/redmine/scm/adapters/cvs_adapter.rb.
 *  url      -> the module name, relative to the CVSROOT
 *  root_url -> the CVSROOT (e.g. :pserver:anonymous@foo.bar:/path or /path).
 */
#[Exclude]
class CvsAdapter extends AbstractAdapter
{
    public const string STARTLOG = '----------------------------';
    public const string ENDLOG = '=============================================================================';

    public function __construct(string $url, ?string $rootUrl = null, ?string $login = null, ?string $password = null, ?string $pathEncoding = null, ?string $bin = null)
    {
        if (null === $rootUrl || '' === trim($rootUrl)) {
            throw new ScmCommandFailed('CVSROOT is blank');
        }
        parent::__construct($url, $rootUrl, $login, $password, $pathEncoding, $bin);
    }

    public static function defaultCommand(): string
    {
        return 'cvs';
    }

    public function adapterName(): string
    {
        return 'Cvs';
    }

    public static function clientAvailable(?string $bin = null): bool
    {
        return static::clientVersionAbove([1, 12], $bin);
    }

    public function pathEncoding(): ?string
    {
        return $this->pathEncoding;
    }

    public function info(): ?ScmInfo
    {
        return new ScmInfo($this->rootUrl, null);
    }

    public function previousRevision(string $revision): string
    {
        return (new CvsRevisionHelper($revision))->prevRev();
    }

    /**
     * Entries of a directory; $identifier is a date/time (or null for HEAD).
     *
     * @return list<ScmEntry>|null
     */
    public function entries(?string $path = null, ?string $identifier = null, array|bool $options = []): ?array
    {
        $pathLocale = (string) self::scmIconv($this->pathEncoding, 'UTF-8', (string) $path);
        $args = ['-q', 'rls', '-e'];
        if (null !== $identifier) {
            array_push($args, '-D', self::timeToCvsTime($identifier));
        }
        $args[] = $this->pathWithProj((string) $path);
        try {
            $out = $this->scmCmd($args);
        } catch (ScmCommandAborted) {
            return null;
        }
        $entries = [];
        foreach (self::lines($out) as $line) {
            $fields = explode('/', substr($line, 0, -1));
            $n = \count($fields);
            if ('D' !== $fields[0]) {
                if ($n < 5) {
                    continue;
                }
                $time = null;
                // Thu Dec 13 16:27:22 2007
                $t = preg_split('/\s+/', trim($fields[$n - 3])) ?: [];
                if (5 === \count($t) && 4 === \strlen($t[4])) {
                    $time = self::parseTime($t[1].' '.$t[2].' '.$t[3].' '.$t[4].' GMT');
                }
                $name = $fields[$n - 5];
                $entries[] = new ScmEntry(
                    (string) self::scmIconv('UTF-8', $this->pathEncoding, $name),
                    (string) self::scmIconv('UTF-8', $this->pathEncoding, $pathLocale.'/'.$name),
                    'file',
                    null,
                    new ScmRevision(author: '', time: $time, revision: $fields[$n - 4], name: self::scmIconv('UTF-8', $this->pathEncoding, $fields[$n - 4]), format: ScmRevision::FORMAT_CVS),
                );
            } else {
                $entries[] = new ScmEntry(
                    (string) self::scmIconv('UTF-8', $this->pathEncoding, $fields[1] ?? ''),
                    (string) self::scmIconv('UTF-8', $this->pathEncoding, $pathLocale.'/'.($fields[1] ?? '')),
                    'dir',
                );
            }
        }

        return self::sortByName($entries);
    }

    /**
     * All revisions (one per file revision) found since $identifierFrom (a date/time) in the module.
     *
     * @param array{log_encoding?: ?string} $options
     *
     * @return list<ScmRevision>
     */
    public function revisions(?string $path = null, ?string $identifierFrom = null, ?string $identifierTo = null, array $options = []): array
    {
        $pathWithProjectUtf8 = $this->pathWithProj((string) $path);
        $pathWithProjectLocale = (string) self::scmIconv($this->pathEncoding, 'UTF-8', $pathWithProjectUtf8);
        $args = ['-q', 'rlog'];
        if (null !== $identifierFrom) {
            array_push($args, '-d', '>'.self::timeToCvsTime($identifierFrom));
        }
        $args[] = $pathWithProjectUtf8;
        try {
            $out = $this->scmCmd($args);
        } catch (ScmCommandAborted) {
            return [];
        }
        $revs = [];
        $state = 'entry_start';
        $commitLog = '';
        $revision = null;
        $date = null;
        $author = null;
        $entryPath = null;
        $entryName = null;
        $fileState = null;
        $branchMap = [];
        $logEncoding = $options['log_encoding'] ?? null;
        $rcsRe = '/^RCS file: '.preg_quote($this->rootUrlPath(), '/').'\/'.preg_quote($pathWithProjectLocale, '/').'(.+),v$/';
        foreach (self::lines($out) as $line) {
            $chomped = rtrim($line, "\r\n");
            if ('revision' !== $state && str_starts_with($line, self::ENDLOG)) {
                $commitLog = '';
                $revision = null;
                $state = 'entry_start';
            }
            if ('entry_start' === $state) {
                $branchMap = [];
                if (preg_match($rcsRe, $chomped, $m)) {
                    $entryPath = self::normalizeCvsPath($m[1]);
                    $entryName = self::normalizePath(basename($m[1]));
                } elseif (str_starts_with($line, 'symbolic names:')) {
                    $state = 'symbolic';
                } elseif (str_starts_with($line, self::STARTLOG)) {
                    $commitLog = '';
                    $state = 'revision';
                }
                continue;
            }
            if ('symbolic' === $state) {
                if (preg_match('/^(.*):\s(.*)/', trim($line), $m)) {
                    $branchMap[$m[1]] = $m[2];
                } else {
                    $state = 'tags';
                    continue;
                }
            } elseif ('tags' === $state) {
                if (str_starts_with($line, self::STARTLOG)) {
                    $commitLog = '';
                    $state = 'revision';
                } elseif (str_starts_with($line, self::ENDLOG)) {
                    $state = 'head';
                }
                continue;
            } elseif ('revision' === $state) {
                if (str_starts_with($line, self::ENDLOG) || str_starts_with($line, self::STARTLOG)) {
                    if (null !== $revision) {
                        $helper = new CvsRevisionHelper($revision);
                        $revBranch = 'HEAD';
                        foreach ($branchMap as $branchName => $branchPoint) {
                            if ($helper->isInBranchWithSymbol((string) $branchPoint)) {
                                $revBranch = (string) $branchName;
                            }
                        }
                        $revs[] = new ScmRevision(
                            author: $author,
                            time: $date,
                            message: preg_replace('/\r?\n\z/', '', $commitLog),
                            paths: [[
                                'revision' => $revision,
                                'branch' => $revBranch,
                                'path' => (string) self::scmIconv('UTF-8', $this->pathEncoding, $entryPath),
                                'name' => (string) self::scmIconv('UTF-8', $this->pathEncoding, $entryName),
                                'kind' => 'file',
                                'action' => (string) $fileState,
                            ]],
                            format: ScmRevision::FORMAT_CVS,
                        );
                    }
                    $commitLog = '';
                    $revision = null;
                    if (str_starts_with($line, self::ENDLOG)) {
                        $state = 'entry_start';
                    }
                    continue;
                }
                if (preg_match('/^branches: (.+)$/', $chomped)) {
                    // version.branch = $1
                } elseif (preg_match('/^revision (\d+(?:\.\d+)+).*$/', $chomped, $m)) {
                    $revision = $m[1];
                } elseif (preg_match('/^date:\s+(\d+.\d+.\d+\s+\d+:\d+:\d+)/', $chomped, $m)) {
                    $date = self::parseTime(str_replace('/', '-', $m[1]).' UTC');
                    $lineUtf8 = (string) self::scmIconv('UTF-8', $logEncoding, $chomped);
                    $authorUtf8 = preg_match('/author: ([^;]+)/', $lineUtf8, $am) ? $am[1] : '';
                    $author = self::scmIconv($logEncoding, 'UTF-8', $authorUtf8);
                    $fileState = preg_match('/state: ([^;]+)/', $chomped, $sm) ? $sm[1] : null;
                } elseif (!str_starts_with($line, '*** empty log message ***')) {
                    $commitLog .= $line;
                }
            }
        }

        return $revs;
    }

    /** @return list<string>|null */
    public function diff(?string $path, ?string $identifierFrom, ?string $identifierTo = null): ?array
    {
        try {
            return self::lines($this->scmCmd(['rdiff', '-u', '-r'.$identifierTo, '-r'.$identifierFrom, $this->pathWithProj((string) $path)]));
        } catch (ScmCommandAborted) {
            return null;
        }
    }

    public function cat(string $path, ?string $identifier = null): ?string
    {
        $args = ['-q', 'co', '-D', self::timeToCvsTime($identifier ?? 'HEAD'), '-p', $this->pathWithProj($path)];
        try {
            return $this->scmCmd($args);
        } catch (ScmCommandAborted) {
            return null;
        }
    }

    public function annotate(string $path, ?string $identifier = null): ?ScmAnnotate
    {
        $blame = new ScmAnnotate();
        try {
            $out = $this->scmCmd(['rannotate', '-D', self::timeToCvsTime($identifier ?? 'HEAD'), $this->pathWithProj($path)]);
        } catch (ScmCommandAborted) {
            return $blame;
        }
        foreach (self::lines($out) as $line) {
            if (preg_match('/^([\d.]+)\s+\(([^)]+)\s+[^)]+\):\s(.*)$/s', rtrim($line, "\r\n"), $m)) {
                $blame->addLine(rtrim($m[3]), new ScmRevision(identifier: null, author: trim($m[2]), revision: $m[1], format: ScmRevision::FORMAT_CVS));
            }
        }

        return $blame;
    }

    /** Root url without the connection string (:pserver:anonymous@foo.bar:/path => /path) */
    private function rootUrlPath(): string
    {
        return (string) preg_replace('#^:.+?(?=/)#', '', (string) $this->rootUrl);
    }

    /** A date/time (string, DateTimeInterface or "HEAD" = now) in the CVS format (UTC) */
    public static function timeToCvsTime(string|\DateTimeInterface $time): string
    {
        if (\is_string($time)) {
            $time = 'HEAD' === $time ? new \DateTimeImmutable() : (self::parseTime($time) ?? new \DateTimeImmutable());
        }

        return \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s').' UTC';
    }

    private static function normalizeCvsPath(string $path): string
    {
        return self::normalizePath(str_replace('Attic/', '', $path));
    }

    private static function normalizePath(string $path): string
    {
        $path = (string) preg_replace('#^(/)*(.*)#s', '$2', $path);

        return (string) preg_replace('/(.*)(,v)+/s', '$1', $path);
    }

    private function pathWithProj(string $path): string
    {
        return $this->url.self::withLeadingSlash($path);
    }

    /** @throws ScmCommandAborted */
    private function scmCmd(array $args): string
    {
        $full = array_map(fn ($e) => (string) self::scmIconv($this->pathEncoding, 'UTF-8', (string) $e), array_merge(['-d', (string) $this->rootUrl], $args));
        [$code, $out] = $this->shellout($full, null, ['TZ' => 'UTC']);
        if (0 !== $code) {
            throw new ScmCommandAborted('cvs exited with non-zero status: '.$code);
        }

        return $out;
    }
}

/** Port of CvsRevisionHelper */
#[Exclude]
final class CvsRevisionHelper
{
    public int $revision;
    public string $base;
    public ?string $branchid = null;

    public function __construct(public string $completeRev)
    {
        $pieces = explode('.', $completeRev);
        $this->revision = (int) end($pieces);
        $baseSize = 1 + intdiv(\count($pieces), 2);
        $this->base = implode('.', \array_slice($pieces, 0, \count($pieces) - $baseSize + 1));
        if ($baseSize > 2) {
            $this->branchid = $pieces[\count($pieces) - 2];
        }
    }

    public function branchVersion(): string
    {
        return null !== $this->branchid ? $this->base.'.'.$this->branchid : $this->base;
    }

    public function isBranchRevision(): bool
    {
        return null !== $this->branchid;
    }

    public function prevRev(): string
    {
        return 0 !== $this->revision ? $this->buildRevision($this->revision - 1) : $this->buildRevision($this->revision);
    }

    public function isInBranchWithSymbol(string $branchSymbol): bool
    {
        $b = explode('.', $branchSymbol);
        $branchStart = implode('.', \array_slice($b, 0, max(0, \count($b) - 2))).'.'.end($b);

        return $this->branchVersion() === $branchStart;
    }

    private function buildRevision(int $rev): string
    {
        if (0 === $rev) {
            return null === $this->branchid ? $this->base.'.0' : $this->base;
        }

        return null === $this->branchid ? $this->base.'.'.$rev : $this->base.'.'.$this->branchid.'.'.$rev;
    }
}

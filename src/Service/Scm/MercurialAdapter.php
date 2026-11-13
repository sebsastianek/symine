<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Port of lib/redmine/scm/adapters/mercurial_adapter.rb: uses Redmine's "redminehelper" hg extension
 * (resources/mercurial/redminehelper.py) and an XML log template.
 */
#[Exclude]
class MercurialAdapter extends AbstractAdapter
{
    public const string HELPERS_DIR = __DIR__.'/resources/mercurial';
    public const string HG_HELPER_EXT = self::HELPERS_DIR.'/redminehelper.py';
    public const string TEMPLATE_PATH = self::HELPERS_DIR.'/hg-template-1.0.tmpl';

    // command options which may be processed earlier, by faulty parser in hg
    private const string HG_EARLY_BOOL_ARG = '/^--(debugger|profile|traceback)$/';
    private const string HG_EARLY_LIST_ARG = '/^(--(config|cwd|repo(sitory)?)\b|-R)/';

    /** @var array<string, list<int>> */
    private static array $pythonVersions = [];
    private ?\SimpleXMLElement $summary = null;
    private bool $summaryLoaded = false;

    public static function defaultCommand(): string
    {
        return 'hg';
    }

    public function adapterName(): string
    {
        return 'Mercurial';
    }

    /** Mercurial >= 5.1 running on Python >= 3.5 */
    public static function clientAvailable(?string $bin = null): bool
    {
        if (!static::clientVersionAbove([5, 1], $bin)) {
            return false;
        }
        $py = self::pythonVersion($bin);

        return [] !== $py && $py >= [3, 5];
    }

    /** @return list<int> */
    public static function pythonVersion(?string $bin = null): array
    {
        $bin = null !== $bin && '' !== $bin ? $bin : static::defaultCommand();
        if (!isset(self::$pythonVersions[$bin])) {
            $out = self::capture([$bin, 'debuginstall']);
            self::$pythonVersions[$bin] = preg_match('/checking Python version \(([\d.]+)\)/', $out, $m) ? array_map('intval', explode('.', $m[1])) : [];
        }

        return self::$pythonVersions[$bin];
    }

    public function pathEncoding(): ?string
    {
        return $this->pathEncoding;
    }

    public function info(): ?ScmInfo
    {
        $summary = $this->summary();
        if (null === $summary || !isset($summary->repository->tip)) {
            return null;
        }
        $tip = $summary->repository->tip;

        return new ScmInfo(
            urldecode((string) $summary->repository['root']),
            new ScmRevision(scmid: (string) $tip['node'], revision: (string) $tip['revision'], format: ScmRevision::FORMAT_MERCURIAL),
        );
    }

    /** @return list<string> */
    public function tags(): ?array
    {
        $tags = [];
        foreach ($this->summary()?->repository->tag ?? [] as $e) {
            $tags[] = urldecode((string) $e['name']);
        }

        return $tags;
    }

    /** @return array<string, string> tag => node */
    public function tagmap(): array
    {
        $map = [];
        foreach ($this->summary()?->repository->tag ?? [] as $e) {
            $map[urldecode((string) $e['name'])] = (string) $e['node'];
        }

        return $map;
    }

    /** @return list<ScmBranch> */
    public function branches(): ?array
    {
        $branches = [];
        foreach ($this->summary()?->repository->branch ?? [] as $e) {
            $branches[] = new ScmBranch(urldecode((string) $e['name']), (string) $e['node'], false, (string) $e['revision']);
        }

        return $branches;
    }

    /** @return array<string, string> branch => node */
    public function branchmap(): array
    {
        $map = [];
        foreach ($this->branches() ?? [] as $b) {
            $map[$b->name] = $b->scmid;
        }

        return $map;
    }

    private function summary(): ?\SimpleXMLElement
    {
        if (!$this->summaryLoaded) {
            $this->summaryLoaded = true;
            try {
                $out = $this->hg(['rhsummary']);
                $this->summary = self::parseXml($out);
            } catch (ScmCommandAborted) {
                $this->summary = null;
            }
        }

        return $this->summary;
    }

    /** @return list<ScmEntry>|null */
    public function entries(?string $path = null, ?string $identifier = null, array|bool $options = []): ?array
    {
        $p1 = (string) self::scmIconv($this->pathEncoding, 'UTF-8', (string) $path);
        try {
            $out = $this->hg(['rhmanifest', '-r'.self::cgiEscape($this->hgrev($identifier)), '--', self::cgiEscape(self::withoutLeadingSlash($p1))]);
        } catch (ScmCommandAborted) {
            return null; // means not found
        }
        $manifest = self::parseXml($out)?->repository->manifest;
        if (null === $manifest) {
            return null;
        }
        $prefix = '' === (string) $path ? '' : self::withTrailingSlash($path);
        $entries = [];
        foreach ($manifest->dir ?? [] as $e) {
            $n = urldecode((string) $e['name']);
            $entries[] = new ScmEntry($n, $prefix.$n, 'dir');
        }
        foreach ($manifest->file ?? [] as $e) {
            $n = urldecode((string) $e['name']);
            $lr = new ScmRevision(
                identifier: (string) $e['node'],
                scmid: (string) $e['node'],
                time: (new \DateTimeImmutable())->setTimestamp((int) $e['time']),
                revision: (string) $e['revision'],
                format: ScmRevision::FORMAT_MERCURIAL,
            );
            $entries[] = new ScmEntry($n, $prefix.$n, 'file', (int) $e['size'], $lr);
        }

        return $entries;
    }

    /** @return list<ScmRevision> */
    public function revisions(?string $path = null, ?string $identifierFrom = null, ?string $identifierTo = null, array $options = []): array
    {
        $args = ['log', '--debug', '-C', '--style='.self::TEMPLATE_PATH, '-r'.$this->hgrev($identifierFrom).':'.$this->hgrev($identifierTo)];
        if (isset($options['limit'])) {
            $args[] = '--limit='.(int) $options['limit'];
        }
        if (null !== $path && '' !== $path) {
            $args[] = '--';
            $args[] = $this->hgtarget($path);
        }
        $out = $this->hg($args);
        $log = self::parseXml($out.'</log>');
        $revs = [];
        foreach ($log?->logentry ?? [] as $le) {
            $cpmap = [];
            foreach ($le->paths->{'path-copied'} ?? [] as $e) {
                $cpmap[(string) self::scmIconv('UTF-8', $this->pathEncoding, urldecode((string) $e))] = (string) self::scmIconv('UTF-8', $this->pathEncoding, urldecode((string) $e['copyfrom-path']));
            }
            $paths = [];
            foreach ($le->paths->path ?? [] as $e) {
                $p = (string) self::scmIconv('UTF-8', $this->pathEncoding, urldecode((string) $e));
                $copied = \array_key_exists($p, $cpmap);
                $paths[] = [
                    'action' => (string) $e['action'],
                    'path' => self::withLeadingSlash($p),
                    'from_path' => $copied ? self::withLeadingSlash($cpmap[$p]) : null,
                    'from_revision' => $copied ? (string) $le['node'] : null,
                ];
            }
            usort($paths, static fn ($a, $b) => strcmp($a['path'], $b['path']));
            $parents = [];
            foreach ($le->parents->parent ?? [] as $par) {
                if ('0000000000000000000000000000000000000000' !== (string) $par) {
                    $parents[] = (string) $par;
                }
            }
            $revs[] = new ScmRevision(
                identifier: null,
                scmid: (string) $le['node'],
                author: urldecode((string) ($le->author ?? '')),
                time: self::parseTime((string) $le->date),
                message: urldecode((string) ($le->msg ?? '')),
                paths: $paths,
                parents: $parents,
                revision: (string) $le['revision'],
                format: ScmRevision::FORMAT_MERCURIAL,
            );
        }

        return $revs;
    }

    /**
     * List of nodes in the specified branch.
     *
     * @return list<string>
     */
    public function nodesInBranch(string $branch, array $options = []): array
    {
        $args = ['rhlog', '--template={node}\n', '--rhbranch='.self::cgiEscape($branch), '--from='.self::cgiEscape($branch), '--to=0'];
        if (isset($options['limit'])) {
            $args[] = '--limit='.(int) $options['limit'];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $this->hg($args))), static fn ($l) => '' !== $l));
    }

    /** @return list<string>|null */
    public function diff(?string $path, ?string $identifierFrom, ?string $identifierTo = null): ?array
    {
        $args = ['rhdiff'];
        if (null !== $identifierTo) {
            $args[] = '-r'.$this->hgrev($identifierTo);
            $args[] = '-r'.$this->hgrev($identifierFrom);
        } else {
            $args[] = '-c'.$this->hgrev($identifierFrom);
        }
        if (null !== $path && '' !== $path) {
            $p = (string) self::scmIconv($this->pathEncoding, 'UTF-8', $path);
            $args[] = '--';
            $args[] = self::cgiEscape($this->hgtarget($p));
        }
        try {
            return self::lines($this->hg($args));
        } catch (ScmCommandAborted) {
            return null; // means not found
        }
    }

    public function cat(string $path, ?string $identifier = null): ?string
    {
        $p = self::cgiEscape((string) self::scmIconv($this->pathEncoding, 'UTF-8', $path));
        try {
            return $this->hg(['rhcat', '-r'.self::cgiEscape($this->hgrev($identifier)), '--', $this->hgtarget($p)]);
        } catch (ScmCommandAborted) {
            return null; // means not found
        }
    }

    public function annotate(string $path, ?string $identifier = null): ?ScmAnnotate
    {
        $p = self::cgiEscape((string) self::scmIconv($this->pathEncoding, 'UTF-8', $path));
        $blame = new ScmAnnotate();
        try {
            $out = $this->hg(['rhannotate', '-ncu', '-r'.self::cgiEscape($this->hgrev($identifier)), '--', $this->hgtarget($p)]);
        } catch (ScmCommandAborted) {
            return $blame; // means not found or cannot be annotated
        }
        foreach (self::lines($out) as $line) {
            if (!preg_match('/^([^:]+)\s(\d+)\s([0-9a-f]+):\s(.*)$/s', rtrim($line, "\r\n"), $m)) {
                continue;
            }
            $blame->addLine(rtrim($m[4]), new ScmRevision(identifier: $m[3], scmid: $m[3], author: trim($m[1]), revision: $m[2], format: ScmRevision::FORMAT_MERCURIAL));
        }

        return $blame;
    }

    /** Mercurial's CLI is restrictive enough to reject any invalid names on its own */
    public function isValidName(?string $name): bool
    {
        return true;
    }

    /** CGI.escape */
    public static function cgiEscape(string $s): string
    {
        return str_replace('%7E', '~', urlencode($s));
    }

    private function hgrev(?string $identifier): string
    {
        return null === $identifier || '' === trim($identifier) ? 'tip' : $identifier;
    }

    private function hgtarget(?string $path): string
    {
        return $this->rootUrl().'/'.self::withoutLeadingSlash($path);
    }

    public function rootUrl(): ?string
    {
        // the root of a local Mercurial repository is its path (avoids a rhsummary call)
        return parent::rootUrl() ?? $this->url;
    }

    /**
     * Runs hg with the redminehelper extension.
     *
     * @throws ScmCommandAborted when hg exits with a non-zero status
     */
    private function hg(array $args): string
    {
        foreach ($args as $a) {
            if (preg_match(self::HG_EARLY_BOOL_ARG, (string) $a)) {
                throw new ScmCommandFailed('malicious command argument detected');
            }
        }
        foreach ($args as $a) {
            if ('--' === $a) {
                break;
            }
            if (preg_match(self::HG_EARLY_LIST_ARG, (string) $a)) {
                throw new ScmCommandFailed('malicious command argument detected');
            }
        }
        $repoPath = $this->rootUrlRetrievedValue() ?? $this->url;
        $full = ['-R'.$repoPath, '--encoding=utf-8',
            '--config', 'extensions.redminehelper='.self::HG_HELPER_EXT,
            '--config', 'redminehelper.path_encoding='.$this->pathEncoding,
            '--config', 'diff.git=false'];
        [$code, $out] = $this->shellout(array_merge($full, $args), null, ['HGPLAIN' => '1', 'HGENCODING' => 'utf-8']);
        if (0 !== $code) {
            throw new ScmCommandAborted('hg exited with non-zero status: '.$code);
        }

        return $out;
    }

    /** root_url configured on the repository (never calls hg) */
    private function rootUrlRetrievedValue(): ?string
    {
        return $this->rootUrl;
    }
}

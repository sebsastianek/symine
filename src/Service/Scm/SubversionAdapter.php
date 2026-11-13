<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Port of lib/redmine/scm/adapters/subversion_adapter.rb (svn CLI with --xml output). */
#[Exclude]
class SubversionAdapter extends AbstractAdapter
{
    public static function defaultCommand(): string
    {
        return 'svn';
    }

    public function adapterName(): string
    {
        return 'Subversion';
    }

    /** --xml options are introduced in 1.3 */
    public static function clientAvailable(?string $bin = null): bool
    {
        return static::clientVersionAbove([1, 3], $bin);
    }

    public function info(): ?ScmInfo
    {
        try {
            [$code, $out] = $this->svn(['info', '--xml', $this->svnTarget('')]);
        } catch (ScmCommandFailed) {
            return null;
        }
        if (0 !== $code) {
            return null;
        }
        $doc = self::parseXml($out);
        if (null === $doc || !isset($doc->entry->repository->root)) {
            return null;
        }
        $commit = $doc->entry->commit;

        return new ScmInfo(
            (string) $doc->entry->repository->root,
            new ScmRevision(
                identifier: (string) $commit['revision'],
                author: isset($commit->author) ? (string) $commit->author : '',
                time: self::parseTime(isset($commit->date) ? (string) $commit->date : null),
                format: ScmRevision::FORMAT_DEFAULT,
            ),
        );
    }

    /** @return list<ScmEntry>|null */
    public function entries(?string $path = null, ?string $identifier = null, array|bool $options = []): ?array
    {
        $path = (string) $path;
        [$code, $out] = $this->svn(['list', '--xml', $this->svnTarget($path).'@'.self::rev($identifier, 'HEAD')]);
        if (0 !== $code) {
            return null;
        }
        $entries = [];
        $doc = self::parseXml($out);
        foreach ($doc?->list->entry ?? [] as $entry) {
            $commit = $entry->commit;
            $kind = (string) $entry['kind'];
            // Skip directory if there is no commit date (usually no read access to it)
            if ('dir' === $kind && !isset($commit->date)) {
                continue;
            }
            $name = (string) $entry->name;
            $entries[] = new ScmEntry(
                rawurldecode($name),
                ('' === $path ? '' : $path.'/').$name,
                $kind,
                isset($entry->size) ? (int) $entry->size : null,
                new ScmRevision(
                    identifier: (string) $commit['revision'],
                    author: isset($commit->author) ? (string) $commit->author : null,
                    time: self::parseTime(isset($commit->date) ? (string) $commit->date : null),
                    format: ScmRevision::FORMAT_DEFAULT,
                ),
            );
        }

        return self::sortByName($entries);
    }

    /** @return array<string, string>|null */
    public function properties(?string $path, ?string $identifier = null): ?array
    {
        // proplist xml output supported in svn 1.5.0 and higher
        if (!static::clientVersionAbove([1, 5, 0], $this->bin)) {
            return null;
        }
        [$code, $out] = $this->svn(['proplist', '--verbose', '--xml', $this->svnTarget((string) $path).'@'.self::rev($identifier, 'HEAD')]);
        if (0 !== $code) {
            return null;
        }
        $properties = [];
        foreach (self::parseXml($out)?->target->property ?? [] as $property) {
            $properties[(string) $property['name']] = (string) $property;
        }

        return $properties;
    }

    /** @return list<ScmRevision>|null */
    public function revisions(?string $path = null, ?string $identifierFrom = null, ?string $identifierTo = null, array $options = []): ?array
    {
        $args = ['log', '--xml', '-r', self::rev($identifierFrom, 'HEAD').':'.self::rev($identifierTo, '1')];
        if ($options['with_paths'] ?? false) {
            $args[] = '--verbose';
        }
        if (isset($options['limit'])) {
            $args[] = '--limit';
            $args[] = (string) (int) $options['limit'];
        }
        $args[] = $this->svnTarget((string) $path);
        [$code, $out] = $this->svn($args);
        if (0 !== $code) {
            return null;
        }
        $revisions = [];
        foreach (self::parseXml($out)?->logentry ?? [] as $logentry) {
            $paths = [];
            foreach ($logentry->paths->path ?? [] as $p) {
                $paths[] = [
                    'action' => (string) $p['action'],
                    'path' => (string) $p,
                    'from_path' => isset($p['copyfrom-path']) ? (string) $p['copyfrom-path'] : null,
                    'from_revision' => isset($p['copyfrom-rev']) ? (string) $p['copyfrom-rev'] : null,
                ];
            }
            usort($paths, static fn ($a, $b) => strcmp($a['path'], $b['path']));
            $revisions[] = new ScmRevision(
                identifier: (string) $logentry['revision'],
                author: isset($logentry->author) ? (string) $logentry->author : '',
                time: self::parseTime(isset($logentry->date) ? (string) $logentry->date : null),
                message: isset($logentry->msg) ? (string) $logentry->msg : '',
                paths: $paths,
                format: ScmRevision::FORMAT_DEFAULT,
            );
        }

        return $revisions;
    }

    /** @return list<string>|null */
    public function diff(?string $path, ?string $identifierFrom, ?string $identifierTo = null): ?array
    {
        $from = (int) $identifierFrom > 0 ? (string) (int) $identifierFrom : '';
        $to = (int) $identifierTo > 0 ? (string) (int) $identifierTo : (string) ((int) $from - 1);
        [$code, $out] = $this->svn(['diff', '-r', $to.':'.$from, $this->svnTarget((string) $path).'@'.$from]);

        return 0 === $code ? self::lines($out) : null;
    }

    public function cat(string $path, ?string $identifier = null): ?string
    {
        [$code, $out] = $this->svn(['cat', $this->svnTarget($path).'@'.self::rev($identifier, 'HEAD')]);

        return 0 === $code ? $out : null;
    }

    public function annotate(string $path, ?string $identifier = null): ?ScmAnnotate
    {
        [$code, $out] = $this->svn(['blame', $this->svnTarget($path).'@'.self::rev($identifier, 'HEAD')]);
        if (0 !== $code) {
            return null;
        }
        $blame = new ScmAnnotate();
        foreach (self::lines($out) as $line) {
            if (!preg_match('/^\s*(\d+)\s*(\S+)\s(.*)$/', rtrim($line, "\r\n"), $m)) {
                continue;
            }
            $blame->addLine(rtrim($m[3]), new ScmRevision(identifier: $m[1], author: trim($m[2]), revision: $m[1], format: ScmRevision::FORMAT_DEFAULT));
        }

        return $blame;
    }

    /** "123" for a positive revision number, $default otherwise */
    private static function rev(?string $identifier, string $default): string
    {
        return (int) $identifier > 0 ? (string) (int) $identifier : $default;
    }

    /** SubversionAdapter#target: URL-encoded base/path */
    private function svnTarget(string $path = ''): string
    {
        $base = str_starts_with($path, '/') ? (string) $this->rootUrl() : $this->url;
        $uri = $base.'/'.$path;
        if (preg_match('#^([a-z][a-z0-9+.\-]*://[^/]*)(.*)$#is', $uri, $m)) {
            $uri = $m[1].preg_replace_callback('#[^A-Za-z0-9\-._~!$&\'()*+,;=:@/]#', static fn ($c) => rawurlencode($c[0]), $m[2]);
        }

        return (string) preg_replace('/[?<>*]/', '', $uri);
    }

    private function svn(array $args): array
    {
        if (null !== $this->login) {
            array_push($args, '--username', $this->login);
            if ('' !== (string) $this->password) {
                array_push($args, '--password', (string) $this->password);
            }
        }
        array_push($args, '--no-auth-cache', '--non-interactive');

        return $this->shellout($args);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Port of lib/redmine/scm/adapters/filesystem_adapter.rb: browses a local directory (no history). */
#[Exclude]
class FilesystemAdapter extends AbstractAdapter
{
    public function __construct(string $url, ?string $rootUrl = null, ?string $login = null, ?string $password = null, ?string $pathEncoding = null, ?string $bin = null)
    {
        parent::__construct(self::withTrailingSlash($url), null, null, null, $pathEncoding, $bin);
    }

    public function adapterName(): string
    {
        return 'Filesystem';
    }

    public static function clientAvailable(?string $bin = null): bool
    {
        return true;
    }

    public static function clientVersion(?string $bin = null): array
    {
        return [];
    }

    public function supportsAnnotate(): bool
    {
        return false;
    }

    public function pathEncoding(): ?string
    {
        return $this->pathEncoding;
    }

    public function formatPathEnds(string $path, bool $leading = true, bool $trailing = true): string
    {
        $path = $leading ? self::withLeadingSlash($path) : self::withoutLeadingSlash($path);

        return $trailing ? self::withTrailingSlash($path) : self::withoutTrailingSlash($path);
    }

    public function info(): ?ScmInfo
    {
        return new ScmInfo($this->fsTarget(), null);
    }

    /** @return list<ScmEntry> */
    public function entries(?string $path = '', ?string $identifier = null, array|bool $options = []): ?array
    {
        $path = (string) $path;
        $trgt = (string) self::scmIconv($this->pathEncoding, 'UTF-8', $this->fsTarget($path));
        $dir = @scandir($trgt);
        if (false === $dir) {
            throw new ScmCommandFailed('No such file or directory @ dir_initialize - '.$trgt);
        }
        $entries = [];
        foreach ($dir as $e1) {
            $eUtf8 = (string) self::scmIconv('UTF-8', $this->pathEncoding, $e1);
            if ('' === trim($eUtf8) || preg_match('/^\.+$/', $e1)) {
                continue; // avoid . and ..
            }
            $relativeUtf8 = $this->formatPathEnds($this->formatPathEnds($path, false, true).$eUtf8, false, false);
            $t1 = (string) self::scmIconv($this->pathEncoding, 'UTF-8', $this->fsTarget($relativeUtf8));
            if (!file_exists($t1) || !\in_array(@filetype($t1), ['file', 'dir'], true)) {
                continue; // avoid special types
            }
            $isDir = is_dir($t1);
            $entries[] = new ScmEntry(
                (string) self::scmIconv('UTF-8', $this->pathEncoding, basename($e1)),
                // list unreadable files, but don't link them
                is_readable($t1) ? $relativeUtf8 : '',
                $isDir ? 'dir' : 'file',
                $isDir ? null : (int) filesize($t1),
                new ScmRevision(time: (new \DateTimeImmutable())->setTimestamp((int) filemtime($t1)), format: ScmRevision::FORMAT_DEFAULT),
            );
        }

        return self::sortByName($entries);
    }

    public function cat(string $path, ?string $identifier = null): ?string
    {
        $p = (string) self::scmIconv($this->pathEncoding, 'UTF-8', $this->fsTarget($path));
        $content = is_file($p) ? @file_get_contents($p) : false;
        if (false === $content) {
            throw new ScmCommandFailed('No such file or directory @ rb_sysopen - '.$p);
        }

        return $content;
    }

    /** FilesystemAdapter#target (prevents the use of "..") */
    private function fsTarget(?string $path = null): string
    {
        if (null !== $path && !preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $path)) {
            return $this->url.self::withoutLeadingSlash($path);
        }

        return $this->url;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Port of lib/redmine/scm/adapters/bazaar_adapter.rb (bzr / brz CLI). */
#[Exclude]
class BazaarAdapter extends AbstractAdapter
{
    private ?bool $aro = null;

    public function __construct(string $url, ?string $rootUrl = null, ?string $login = null, ?string $password = null, ?string $pathEncoding = null, ?string $bin = null)
    {
        parent::__construct($url, $url, null, null, 'UTF-8', $bin);
    }

    public static function defaultCommand(): string
    {
        return 'bzr';
    }

    public function adapterName(): string
    {
        return 'Bazaar';
    }

    public static function clientAvailable(?string $bin = null): bool
    {
        return [] !== static::clientVersion($bin);
    }

    protected static function versionFromCommandLine(string $bin): string
    {
        return self::capture([$bin, '--version'], self::bzrEnv());
    }

    /** Repository::Bazaar sets the path encoding from its log_encoding */
    public function setBzrPathEncoding(?string $encoding): void
    {
        $this->pathEncoding = null !== $encoding && '' !== trim($encoding) ? $encoding : 'UTF-8';
    }

    public function info(): ?ScmInfo
    {
        try {
            $out = $this->scmCmd(['revno', $this->target('')]);
        } catch (ScmCommandAborted) {
            return null;
        }
        if (preg_match('/^(\d+)\r?$/m', $out, $m)) {
            return new ScmInfo($this->url, new ScmRevision(identifier: $m[1], format: ScmRevision::FORMAT_DEFAULT));
        }

        return null;
    }

    /** @return list<ScmEntry>|null */
    public function entries(?string $path = null, ?string $identifier = null, array|bool $options = []): ?array
    {
        $path = (string) $path;
        $identifier = (int) $identifier > 0 ? (int) $identifier : -1;
        try {
            $out = $this->scmCmd(['ls', '-v', '--show-ids', '-r'.$identifier, $this->target($path)]);
        } catch (ScmCommandAborted) {
            return null;
        }
        $prefixUtf8 = str_replace('\\', '/', $this->url.'/'.$path);
        $prefix = (string) self::scmIconv($this->pathEncoding, 'UTF-8', $prefixUtf8);
        $re = '/^V\s+('.preg_quote($prefix, '/').')?(\/?)([^\/]+)(\/?)\s+(\S+)\r?$/';
        $entries = [];
        foreach (self::lines($out) as $line) {
            if (!preg_match($re, rtrim($line, "\n"), $m)) {
                continue;
            }
            $name = (string) self::scmIconv('UTF-8', $this->pathEncoding, trim($m[3]));
            $entries[] = new ScmEntry(
                $name,
                ('' === $path ? '' : $path.'/').$name,
                '' === trim($m[4]) ? 'file' : 'dir',
                null,
                new ScmRevision(revision: trim($m[5]), format: ScmRevision::FORMAT_DEFAULT),
            );
        }

        return self::sortByName($entries);
    }

    /** @return list<ScmRevision>|null */
    public function revisions(?string $path = null, ?string $identifierFrom = null, ?string $identifierTo = null, array $options = []): ?array
    {
        $from = (int) $identifierFrom > 0 ? (string) (int) $identifierFrom : 'last:1';
        $to = (int) $identifierTo > 0 ? (string) (int) $identifierTo : '1';
        try {
            $out = $this->scmCmd(['log', '-v', '--show-ids', '-r'.$to.'..'.$from, $this->target((string) $path)]);
        } catch (ScmCommandAborted) {
            return null;
        }
        $revisions = [];
        $revision = null;
        $parsing = null;
        foreach (self::lines($out) as $rawLine) {
            $line = rtrim($rawLine, "\n");
            if (str_starts_with($line, '----')) {
                if (null !== $revision) {
                    $revisions[] = $revision;
                }
                $revision = new ScmRevision(message: '', paths: [], format: ScmRevision::FORMAT_DEFAULT);
                $parsing = null;
                continue;
            }
            if (null === $revision) {
                continue;
            }
            if (preg_match('/^revno: (\d+)($|\s\[merge\]$)/', $line, $m)) {
                $revision->identifier = $m[1];
                $revision->name = $m[1];
            } elseif (preg_match('/^committer: (.+)$/', $line, $m)) {
                $revision->author = trim($m[1]);
            } elseif (preg_match('/^revision-id:(.+)$/', $line, $m)) {
                $revision->scmid = trim($m[1]);
            } elseif (preg_match('/^timestamp: (.+)$/', $line, $m)) {
                $revision->time = self::parseBzrTime($m[1]);
            } elseif (str_starts_with($line, '    -----')) {
                // partial revisions
                if ('message' !== $parsing) {
                    $parsing = null;
                }
            } elseif (preg_match('/^(message|added|modified|removed|renamed):/', $line, $m)) {
                $parsing = $m[1];
            } elseif (preg_match('/^  (.*)$/', $line, $m)) {
                if ('message' === $parsing) {
                    $revision->message .= $m[1]."\n";
                } elseif (preg_match('/^(.*)\s+(\S+)$/', $m[1], $pm)) {
                    $p = (string) self::scmIconv('UTF-8', $this->pathEncoding, trim($pm[1]));
                    $revid = $pm[2];
                    switch ($parsing) {
                        case 'added':
                            $revision->paths[] = ['action' => 'A', 'path' => '/'.$p, 'revision' => $revid];
                            break;
                        case 'modified':
                            $revision->paths[] = ['action' => 'M', 'path' => '/'.$p, 'revision' => $revid];
                            break;
                        case 'removed':
                            $revision->paths[] = ['action' => 'D', 'path' => '/'.$p, 'revision' => $revid];
                            break;
                        case 'renamed':
                            $parts = explode('=>', $p);
                            $new = end($parts);
                            if (false !== $new && '' !== $new) {
                                $revision->paths[] = ['action' => 'M', 'path' => '/'.trim($new), 'revision' => $revid];
                            }
                            break;
                    }
                }
            } else {
                $parsing = null;
            }
        }
        if (null !== $revision) {
            $revisions[] = $revision;
        }

        return $revisions;
    }

    /** @return list<string>|null */
    public function diff(?string $path, ?string $identifierFrom, ?string $identifierTo = null): ?array
    {
        $to = null !== $identifierTo ? (int) $identifierTo : (int) $identifierFrom - 1;
        $from = (int) $identifierFrom;
        [, $out] = $this->shellout($this->localeArgs(['diff', '-r'.$to.'..'.$from, $this->target((string) $path)]), null, self::bzrEnv());

        return self::lines($out);
    }

    public function cat(string $path, ?string $identifier = null): ?string
    {
        $args = ['cat'];
        if ((int) $identifier > 0) {
            $args[] = '-r'.(int) $identifier;
        }
        $args[] = $this->target($path);
        try {
            return $this->scmCmd($args);
        } catch (ScmCommandAborted) {
            return null;
        }
    }

    public function annotate(string $path, ?string $identifier = null): ?ScmAnnotate
    {
        $args = ['annotate', '-q', '--all'];
        if ((int) $identifier > 0) {
            $args[] = '-r'.(int) $identifier;
        }
        $args[] = $this->target($path);
        try {
            $out = $this->scmCmd($args);
        } catch (ScmCommandAborted) {
            return null;
        }
        $blame = new ScmAnnotate();
        foreach (self::lines($out) as $line) {
            if (preg_match('/^(\d+) ([^|]+)\| (.*)$/s', rtrim($line, "\r\n"), $m)) {
                $blame->addLine(rtrim($m[3]), new ScmRevision(identifier: $m[1], author: trim($m[2]), revision: $m[1], format: ScmRevision::FORMAT_DEFAULT));
            }
        }

        return $blame;
    }

    public static function branchConfPath(?string $path): ?string
    {
        if (null === $path) {
            return null;
        }
        $bcp = preg_match('#^(.*[/\\\\])\.bzr.*$#', $path, $m) ? $m[1] : $path;
        $bcp = (string) preg_replace('#[/\\\\]$#', '', $bcp);

        return $bcp.'/.bzr/branch/branch.conf';
    }

    public function appendRevisionsOnly(): bool
    {
        if (null !== $this->aro) {
            return $this->aro;
        }
        $this->aro = false;
        $bcp = self::branchConfPath($this->url);
        if (null !== $bcp && is_file($bcp)) {
            $cnt = 0;
            foreach (file($bcp) ?: [] as $line) {
                if (preg_match('/^\s*append_revisions_only\s*=\s*(\w+)\s*$/', rtrim($line, "\r\n"), $m)) {
                    if (0 === strcasecmp($m[1], 'TRUE')) {
                        $this->aro = true;
                        ++$cnt;
                    } elseif (0 === strcasecmp($m[1], 'FALSE')) {
                        $this->aro = false;
                        ++$cnt;
                    }
                    if ($cnt > 1) {
                        $this->aro = false;
                        break;
                    }
                }
            }
        }

        return $this->aro;
    }

    /** Bazaar timestamps: "Mon 2007-12-03 18:52:24 +0100" */
    private static function parseBzrTime(string $s): ?\DateTimeImmutable
    {
        $s = trim($s);
        if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\s+([+-]\d{4}))?/', $s, $m)) {
            return self::parseTime($m[1].' '.($m[2] ?? '+0000'));
        }

        return self::parseTime($s);
    }

    private function localeArgs(array $args): array
    {
        return array_map(fn ($e) => (string) self::scmIconv($this->pathEncoding, 'UTF-8', (string) $e), $args);
    }

    private static function bzrEnv(): array
    {
        $home = sys_get_temp_dir().'/symine-bzr-home';
        if (!is_dir($home)) {
            @mkdir($home, 0o700, true);
        }

        return ['HOME' => $home, 'BRZ_LOG' => '/dev/null', 'BZR_LOG' => '/dev/null', 'BRZ_DISABLE_PLUGINS' => '1'];
    }

    /** @throws ScmCommandAborted */
    private function scmCmd(array $args): string
    {
        [$code, $out] = $this->shellout($this->localeArgs($args), null, self::bzrEnv());
        if (0 !== $code) {
            throw new ScmCommandAborted('bzr exited with non-zero status: '.$code);
        }

        return $out;
    }
}

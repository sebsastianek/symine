<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Process\Process;

/**
 * Port of lib/redmine/scm/adapters/abstract_adapter.rb: common interface of the SCM adapters
 * (Subversion, Mercurial, Cvs, Bazaar, Git, Filesystem).
 *
 * Read methods return null when the path/revision does not exist (ScmCommandAborted in Redmine);
 * ScmCommandFailed is thrown when the SCM command cannot be run at all.
 */
#[Exclude]
abstract class AbstractAdapter
{
    /** @var array<string, list<int>> client versions by "class|bin" */
    private static array $clientVersions = [];

    protected string $url;
    protected ?string $rootUrl = null;
    protected ?string $login = null;
    protected ?string $password = null;
    protected string $pathEncoding = 'UTF-8';
    protected string $bin;
    private bool $rootUrlRetrieved = false;

    public function __construct(string $url, ?string $rootUrl = null, ?string $login = null, ?string $password = null, ?string $pathEncoding = null, ?string $bin = null)
    {
        $this->url = $url;
        if (null !== $login && '' !== trim($login)) {
            $this->login = $login;
            $this->password = $password ?? '';
        }
        $this->bin = null !== $bin && '' !== $bin ? $bin : static::defaultCommand();
        if (null !== $rootUrl && '' !== trim($rootUrl)) {
            $this->rootUrl = $rootUrl;
            $this->rootUrlRetrieved = true;
        }
        $this->pathEncoding = null !== $pathEncoding && '' !== trim($pathEncoding) ? $pathEncoding : 'UTF-8';
    }

    /** Default command name of the SCM client (scm_xxx_command) */
    public static function defaultCommand(): string
    {
        return '';
    }

    public function adapterName(): string
    {
        return 'Abstract';
    }

    // ------------------------------------------------------------------ client

    /** Output of "<bin> --version" (overridden by adapters) */
    protected static function versionFromCommandLine(string $bin): string
    {
        return self::capture([$bin, '--version']);
    }

    /** @return list<int> e.g. [1, 14, 2], [] when unknown */
    public static function clientVersion(?string $bin = null): array
    {
        $bin = null !== $bin && '' !== $bin ? $bin : static::defaultCommand();
        $key = static::class.'|'.$bin;
        if (!isset(self::$clientVersions[$key])) {
            self::$clientVersions[$key] = '' === $bin ? [] : self::parseVersion(static::versionFromCommandLine($bin));
        }

        return self::$clientVersions[$key];
    }

    /** "svn, version 1.6.13 (r1002816)" => [1, 6, 13] */
    public static function parseVersion(string $output): array
    {
        if (preg_match('/\A(.*?)((\d+\.)+\d+)/s', $output, $m)) {
            return array_map('intval', explode('.', $m[2]));
        }

        return [];
    }

    public static function clientVersionString(?string $bin = null): string
    {
        $v = static::clientVersion($bin);

        return [] === $v ? 'Unknown version' : implode('.', $v);
    }

    /** @param list<int> $v */
    public static function clientVersionAbove(array $v, ?string $bin = null, bool $unknown = false): bool
    {
        $cv = static::clientVersion($bin);

        return ([] !== $cv && $cv >= $v) || ([] === $cv && $unknown);
    }

    public static function clientAvailable(?string $bin = null): bool
    {
        return true;
    }

    /** Resets the cached client versions (tests) */
    public static function resetClientVersions(): void
    {
        self::$clientVersions = [];
    }

    // ------------------------------------------------------------------ repository

    public function url(): string
    {
        return $this->url;
    }

    public function rootUrl(): ?string
    {
        if (!$this->rootUrlRetrieved) {
            $this->rootUrlRetrieved = true;
            $this->rootUrl = $this->info()?->rootUrl;
        }

        return $this->rootUrl;
    }

    public function pathEncoding(): ?string
    {
        return null;
    }

    public function supportsCat(): bool
    {
        return true;
    }

    public function supportsAnnotate(): bool
    {
        return true;
    }

    public function info(): ?ScmInfo
    {
        return null;
    }

    /** Entry identified by path and revision identifier, null if it doesn't exist */
    public function entry(?string $path = null, ?string $identifier = null): ?ScmEntry
    {
        $parts = array_values(array_filter(preg_split('#[/\\\\]#', (string) $path) ?: [], static fn ($n) => '' !== trim($n)));
        if ([] === $parts) {
            return new ScmEntry('', '', 'dir');
        }
        $name = array_pop($parts);
        foreach ($this->entries(implode('/', $parts), $identifier) ?? [] as $e) {
            if ($e->name === $name) {
                return $e;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|bool $options
     *
     * @return list<ScmEntry>|null
     */
    public function entries(?string $path = null, ?string $identifier = null, array|bool $options = []): ?array
    {
        return null;
    }

    /** @return list<ScmBranch>|null */
    public function branches(): ?array
    {
        return null;
    }

    /** @return list<string>|null */
    public function tags(): ?array
    {
        return null;
    }

    public function defaultBranch(): ?string
    {
        return null;
    }

    /** @return array<string, string>|null */
    public function properties(?string $path, ?string $identifier = null): ?array
    {
        return null;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<ScmRevision>|null
     */
    public function revisions(?string $path = null, ?string $identifierFrom = null, ?string $identifierTo = null, array $options = []): ?array
    {
        return null;
    }

    /** @return list<string>|null diff lines (with line endings) */
    public function diff(?string $path, ?string $identifierFrom, ?string $identifierTo = null): ?array
    {
        return null;
    }

    public function cat(string $path, ?string $identifier = null): ?string
    {
        return null;
    }

    public function annotate(string $path, ?string $identifier = null): ?ScmAnnotate
    {
        return null;
    }

    public function isValidName(?string $name): bool
    {
        return null === $name || 1 === preg_match('/\A[0-9]*\z/', $name);
    }

    // ------------------------------------------------------------------ helpers

    public static function withLeadingSlash(?string $path): string
    {
        $path = (string) $path;

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    public static function withTrailingSlash(?string $path): string
    {
        $path = (string) $path;

        return str_ends_with($path, '/') ? $path : $path.'/';
    }

    public static function withoutLeadingSlash(?string $path): string
    {
        return (string) preg_replace('#^/+#', '', (string) $path);
    }

    public static function withoutTrailingSlash(?string $path): string
    {
        $path = (string) $path;

        return str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
    }

    /** AbstractAdapter#target without shell quoting */
    protected function target(?string $path = ''): string
    {
        $path = (string) $path;
        $base = str_starts_with($path, '/') ? (string) $this->rootUrl() : $this->url;

        return (string) preg_replace('/[?<>*]/', '', $base.'/'.$path);
    }

    /** scm_iconv: converts $str from $from to $to (null on failure) */
    public static function scmIconv(?string $to, ?string $from, ?string $str): ?string
    {
        if (null === $str) {
            return null;
        }
        $to = null !== $to && '' !== $to ? $to : 'UTF-8';
        $from = null !== $from && '' !== $from ? $from : 'UTF-8';
        if (0 === strcasecmp($to, $from)) {
            return $str;
        }
        try {
            $out = @mb_convert_encoding($str, self::mbName($to), self::mbName($from));
        } catch (\ValueError) {
            $out = @iconv($from, $to, $str);
        }

        return false === $out ? null : $out;
    }

    private static function mbName(string $enc): string
    {
        return match (strtolower($enc)) {
            'windows-31j' => 'SJIS-win',
            'cp932' => 'SJIS-win',
            default => $enc,
        };
    }

    /** Redmine::CodesetUtil.replace_invalid_utf8 */
    public static function replaceInvalidUtf8(?string $str): ?string
    {
        if (null === $str || mb_check_encoding($str, 'UTF-8')) {
            return $str;
        }

        return mb_scrub($str, 'UTF-8');
    }

    /** Redmine::CodesetUtil.to_utf8 */
    public static function toUtf8(?string $str, ?string $encoding): ?string
    {
        if (null === $str || '' === $str) {
            return $str;
        }
        $enc = null !== $encoding && '' !== trim($encoding) ? trim($encoding) : 'UTF-8';
        if (0 !== strcasecmp($enc, 'UTF-8')) {
            $out = self::scmIconv('UTF-8', $enc, $str);

            return null === $out ? self::replaceInvalidUtf8($str) : self::replaceInvalidUtf8($out);
        }

        return self::replaceInvalidUtf8($str);
    }

    /** Entries#sort_by_name: by kind ("dir" before "file"), then by name */
    public static function sortByName(array $entries): array
    {
        usort($entries, static fn (ScmEntry $a, ScmEntry $b) => $a->kind === $b->kind ? strcmp($a->name, $b->name) : strcmp($a->kind, $b->kind));

        return array_values($entries);
    }

    /** Redmine::Scm::Adapters::ScmData.binary? */
    public static function isBinary(string $data): bool
    {
        if ('' === $data) {
            return false;
        }
        if (str_contains($data, "\0")) {
            return true;
        }

        return preg_match_all('/[\x01-\x08\x0b\x0c\x0e-\x1f\x7f]/', $data) / \strlen($data) > 0.1;
    }

    /** Splits a command output into lines (keeping the line endings) */
    protected static function lines(string $out): array
    {
        return preg_split('/(?<=\n)/', $out, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Runs a command, returns its output whatever the exit status ('' if it cannot be run) */
    protected static function capture(array $cmd, array $env = []): string
    {
        try {
            $p = new Process($cmd, null, $env + self::commandEnv());
            $p->setTimeout(30);
            $p->run();

            return $p->getOutput().$p->getErrorOutput();
        } catch (\Throwable) {
            return '';
        }
    }

    protected static function commandEnv(): array
    {
        return ['LC_ALL' => 'C.UTF-8', 'LANG' => 'C.UTF-8', 'LANGUAGE' => 'C'];
    }

    /**
     * shellout: runs the SCM command.
     *
     * @return array{0: int, 1: string} [exit code, stdout]
     *
     * @throws ScmCommandFailed when the command cannot be run
     */
    protected function shellout(array $args, ?string $stdin = null, array $env = [], ?string $cwd = null): array
    {
        $process = new Process(array_map('strval', array_merge([$this->bin], $args)), $cwd, $env + static::commandEnv());
        $process->setTimeout(600);
        if (null !== $stdin) {
            $process->setInput($stdin);
        }
        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new ScmCommandFailed(self::stripCredential($e->getMessage()), 0, $e);
        }
        if (127 === $process->getExitCode() || (126 === $process->getExitCode() && '' === $process->getOutput())) {
            throw new ScmCommandFailed(\sprintf('%s: command not found', $this->bin));
        }

        return [(int) $process->getExitCode(), $process->getOutput()];
    }

    /** Hides username/password in a given message */
    protected static function stripCredential(string $cmd): string
    {
        return (string) preg_replace('/(--(password|username))\s+(\'[^\']+\'|\S+)/', '$1 xxxx', $cmd);
    }

    /** Parses an XML document (null when invalid) */
    protected static function parseXml(string $xml): ?\SimpleXMLElement
    {
        if ('' === trim($xml)) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, \SimpleXMLElement::class, \LIBXML_NONET | \LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        return false === $doc ? null : $doc;
    }

    protected static function parseTime(?string $s): ?\DateTimeImmutable
    {
        if (null === $s || '' === trim($s)) {
            return null;
        }
        try {
            return new \DateTimeImmutable(trim($s));
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Scm;

/**
 * Redmine::Scm::Base + the SCM settings of config/configuration.yml, read from environment variables:
 *
 *   SYMINE_SCM_<NAME>_COMMAND      scm_<name>_command      (default: svn, hg, git, cvs, bzr)
 *   SYMINE_SCM_<NAME>_PATH_REGEXP  scm_<name>_path_regexp  (required, like Redmine 7: an SCM without a
 *                                                           path regexp cannot be enabled or used)
 *   SYMINE_SCM_<NAME>_PATH_INFO    scm_<name>_path_info    (hint shown on the repository form)
 *
 * with NAME = SUBVERSION, MERCURIAL, CVS, BAZAAR, GIT, FILESYSTEM.
 */
final class ScmConfiguration
{
    /** Redmine::Scm::Base.all (registration order) => adapter class */
    public const array ADAPTERS = [
        'Subversion' => SubversionAdapter::class,
        'Mercurial' => MercurialAdapter::class,
        'Cvs' => CvsAdapter::class,
        'Bazaar' => BazaarAdapter::class,
        'Git' => GitAdapter::class,
        'Filesystem' => FilesystemAdapter::class,
    ];

    /** Repository::Xxx.scm_name */
    public const array SCM_NAMES = [
        'Subversion' => 'Subversion',
        'Mercurial' => 'Mercurial',
        'Cvs' => 'CVS',
        'Bazaar' => 'Bazaar',
        'Git' => 'Git',
        'Filesystem' => 'Filesystem',
    ];

    /** @return list<string> */
    public function all(): array
    {
        return array_keys(self::ADAPTERS);
    }

    /** "Repository::Subversion" => "Subversion" (null when unknown) */
    public static function nameOfType(?string $type): ?string
    {
        $name = str_replace('Repository::', '', (string) $type);

        return isset(self::ADAPTERS[$name]) ? $name : null;
    }

    /** Repository.repository_class: camelized class name or null */
    public static function normalizeName(?string $name): ?string
    {
        $name = str_replace(' ', '', ucwords(str_replace('_', ' ', (string) $name)));

        return isset(self::ADAPTERS[$name]) ? $name : null;
    }

    /** @return class-string<AbstractAdapter> */
    public function adapterClass(string $scm): string
    {
        return self::ADAPTERS[$scm] ?? throw new \InvalidArgumentException('Unknown SCM '.$scm);
    }

    public function scmName(string $scm): string
    {
        return self::SCM_NAMES[$scm] ?? $scm;
    }

    /** scm_command: configured command or the adapter default ('' for Filesystem) */
    public function command(string $scm): string
    {
        if ('Filesystem' === $scm) {
            return '';
        }
        $cmd = self::env('SYMINE_SCM_'.strtoupper($scm).'_COMMAND');

        return null !== $cmd && '' !== trim($cmd) ? trim($cmd) : $this->adapterClass($scm)::defaultCommand();
    }

    public function pathRegexp(string $scm): ?string
    {
        $re = self::env('SYMINE_SCM_'.strtoupper($scm).'_PATH_REGEXP');

        return null !== $re && '' !== trim($re) ? trim($re) : null;
    }

    public function pathInfo(string $scm): ?string
    {
        $info = self::env('SYMINE_SCM_'.strtoupper($scm).'_PATH_INFO');

        return null !== $info && '' !== trim($info) ? $info : null;
    }

    /** Repository.scm_client_available */
    public function clientAvailable(string $scm): bool
    {
        try {
            return $this->adapterClass($scm)::clientAvailable($this->command($scm));
        } catch (\Throwable) {
            return false;
        }
    }

    /** Repository.scm_version_string */
    public function versionString(string $scm): string
    {
        try {
            return $this->adapterClass($scm)::clientVersionString($this->command($scm));
        } catch (\Throwable) {
            return '';
        }
    }

    /** Repository.scm_available: client available and path regexp configured */
    public function available(string $scm): bool
    {
        return null !== $this->pathRegexp($scm) && $this->clientAvailable($scm);
    }

    /** Repository#validate_repository_path: does $value match the configured regexp (%project% = identifier)? */
    public function pathAllowed(string $scm, ?string $value, ?string $projectIdentifier): bool
    {
        $re = $this->pathRegexp($scm);
        if (null === $re) {
            return true;
        }
        $re = str_replace('%project%', preg_quote((string) $projectIdentifier, '~'), $re);

        return 1 === @preg_match('~\A'.str_replace('~', '\~', $re).'\z~u', (string) $value);
    }

    private static function env(string $name): ?string
    {
        $v = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($v) ? $v : null;
    }
}

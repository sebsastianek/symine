<?php

declare(strict_types=1);

namespace App\Tests\Scm;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Test repositories: Redmine's fixtures (reference/redmine/test/fixtures/repositories, like
 * `rake test:scm:setup:all`) and small repositories created with the SCM clients.
 * Tests needing a missing SCM binary are skipped.
 */
final class ScmFixtures
{
    private static ?string $dir = null;
    /** @var array<string, string> */
    private static array $paths = [];

    public static function dir(): string
    {
        if (null === self::$dir) {
            self::$dir = sys_get_temp_dir().'/symine-scm-'.getmypid();
            if (!is_dir(self::$dir)) {
                mkdir(self::$dir, 0o777, true);
            }
            register_shutdown_function(static fn () => (new Process(['rm', '-rf', self::$dir]))->run());
        }

        return self::$dir;
    }

    public static function referenceDir(): string
    {
        return \dirname(__DIR__, 2).'/reference/redmine/test/fixtures/repositories';
    }

    public static function hasBinary(string $bin): bool
    {
        $p = new Process(['sh', '-c', 'command -v '.escapeshellarg($bin)]);
        $p->run();

        return $p->isSuccessful() && '' !== trim($p->getOutput());
    }

    public static function requireBinary(string ...$bins): void
    {
        foreach ($bins as $bin) {
            if (!self::hasBinary($bin)) {
                TestCase::markTestSkipped($bin.' is not installed');
            }
        }
        if (!is_dir(self::referenceDir())) {
            TestCase::markTestSkipped('Redmine test repositories not found');
        }
    }

    public static function run(array $cmd, ?string $cwd = null, array $env = [], ?string $input = null): string
    {
        $p = new Process($cmd, $cwd, $env + ['HOME' => self::dir(), 'LC_ALL' => 'C.UTF-8', 'TZ' => 'UTC']);
        $p->setTimeout(120);
        if (null !== $input) {
            $p->setInput($input);
        }
        $p->mustRun();

        return $p->getOutput();
    }

    /** Path of a Redmine fixture repository (svn: file:// URL) */
    public static function redmine(string $scm): string
    {
        if (isset(self::$paths['redmine-'.$scm])) {
            return self::$paths['redmine-'.$scm];
        }
        $ref = self::referenceDir();
        $base = self::dir().'/redmine';
        if (!is_dir($base)) {
            mkdir($base, 0o777, true);
        }
        switch ($scm) {
            case 'subversion':
                self::requireBinary('svn', 'svnadmin');
                $path = $base.'/subversion_repository';
                self::run(['svnadmin', 'create', $path]);
                self::run(['sh', '-c', 'gunzip < '.escapeshellarg($ref.'/subversion_repository.dump.gz').' | svnadmin load -q '.escapeshellarg($path)]);
                $result = 'file://'.$path;
                break;
            case 'mercurial':
                self::requireBinary('hg');
                $path = $base.'/mercurial_repository';
                self::run(['hg', 'init', $path]);
                self::run(['hg', '-R', $path, 'pull', '-q', $ref.'/mercurial_repository.hg']);
                $result = $path;
                break;
            default: // cvs, bazaar, git, filesystem
                self::run(['tar', '-xzf', $ref.'/'.$scm.'_repository.tar.gz', '-C', $base]);
                $result = $base.'/'.$scm.'_repository';
        }

        return self::$paths['redmine-'.$scm] = $result;
    }

    /**
     * Small repository with two commits by jsmith: "Initial import refs #13" (README, src/hello.rb)
     * then "Update readme fixes #14" (README modified). Returns [url, root_url (CVS)].
     *
     * @return array{0: string, 1: ?string}
     */
    public static function small(string $scm): array
    {
        $key = 'small-'.$scm;
        if (isset(self::$paths[$key])) {
            return [self::$paths[$key], self::$paths[$key.'-root'] ?? null];
        }
        $base = self::dir().'/small-'.$scm;
        $wc = $base.'-wc';
        @mkdir($wc.'/src', 0o777, true);
        file_put_contents($wc.'/README', "hello\nworld\n");
        file_put_contents($wc.'/src/hello.rb', "class Hello\n  def say # greet\n    puts \"hi\"\n  end\nend\n");
        $root = null;
        $email = 'jsmith <jsmith@somenet.foo>';
        switch ($scm) {
            case 'subversion':
                self::requireBinary('svn', 'svnadmin');
                self::run(['svnadmin', 'create', $base]);
                file_put_contents($base.'/hooks/pre-revprop-change', "#!/bin/sh\nexit 0\n");
                chmod($base.'/hooks/pre-revprop-change', 0o755);
                $url = 'file://'.$base;
                self::run(['svn', 'import', '-q', '--username', 'jsmith', '-m', 'Initial import refs #13', $wc, $url]);
                $co = $base.'-co';
                self::run(['svn', 'checkout', '-q', $url, $co]);
                file_put_contents($co.'/README', "hello\nthere world\n");
                self::run(['svn', 'commit', '-q', '--username', 'jsmith', '-m', 'Update readme fixes #14', $co]);
                break;
            case 'mercurial':
                self::requireBinary('hg');
                self::run(['hg', 'init'], $wc);
                self::run(['hg', 'commit', '-q', '-A', '-u', $email, '-m', 'Initial import refs #13'], $wc);
                file_put_contents($wc.'/README', "hello\nthere world\n");
                self::run(['hg', 'branch', '-q', 'feature'], $wc);
                self::run(['hg', 'commit', '-q', '-u', $email, '-m', 'Update readme fixes #14'], $wc);
                $url = $wc;
                break;
            case 'bazaar':
                self::requireBinary('bzr');
                $env = ['BZR_EMAIL' => $email, 'BRZ_EMAIL' => $email];
                self::run(['bzr', 'init', '-q'], $wc, $env);
                self::run(['bzr', 'add', '-q'], $wc, $env);
                self::run(['bzr', 'commit', '-q', '-m', 'Initial import refs #13'], $wc, $env);
                file_put_contents($wc.'/README', "hello\nthere world\n");
                self::run(['bzr', 'commit', '-q', '-m', 'Update readme fixes #14'], $wc, $env);
                $url = $wc;
                break;
            case 'cvs':
                self::requireBinary('cvs');
                $root = $base;
                self::run(['cvs', '-d', $root, 'init']);
                self::run(['cvs', '-d', $root, '-q', 'import', '-m', 'Initial import refs #13', 'small', 'vendor', 'start'], $wc, ['USER' => 'jsmith', 'LOGNAME' => 'jsmith']);
                $co = $base.'-co';
                @mkdir($co);
                self::run(['cvs', '-d', $root, '-q', 'checkout', 'small'], $co);
                sleep(1);
                file_put_contents($co.'/small/README', "hello\nthere world\n");
                self::run(['cvs', '-d', $root, '-q', 'commit', '-m', 'Update readme fixes #14'], $co.'/small', ['USER' => 'jsmith', 'LOGNAME' => 'jsmith']);
                $url = 'small';
                break;
            case 'git':
                self::requireBinary('git');
                $env = ['GIT_AUTHOR_NAME' => 'jsmith', 'GIT_AUTHOR_EMAIL' => 'jsmith@somenet.foo', 'GIT_COMMITTER_NAME' => 'jsmith', 'GIT_COMMITTER_EMAIL' => 'jsmith@somenet.foo'];
                self::run(['git', 'init', '-q', '-b', 'main'], $wc);
                $at = static fn (string $d) => $env + ['GIT_AUTHOR_DATE' => $d, 'GIT_COMMITTER_DATE' => $d];
                self::run(['git', 'add', '.'], $wc);
                self::run(['git', 'commit', '-qm', 'Initial import refs #13'], $wc, $at('2026-10-01T10:00:00Z'));
                self::run(['git', 'checkout', '-qb', 'feature'], $wc);
                file_put_contents($wc.'/README', "hello\nthere world\n");
                self::run(['git', 'commit', '-qam', 'Update readme fixes #14'], $wc, $at('2026-10-02T10:00:00Z'));
                self::run(['git', 'checkout', '-q', 'main'], $wc);
                self::run(['git', 'merge', '-q', '--no-ff', '-m', 'Merge feature', 'feature'], $wc, $at('2026-10-03T10:00:00Z'));
                $url = $wc;
                break;
            default:
                $url = $wc;
        }
        self::$paths[$key] = $url;
        if (null !== $root) {
            self::$paths[$key.'-root'] = $root;
        }

        return [$url, $root];
    }
}

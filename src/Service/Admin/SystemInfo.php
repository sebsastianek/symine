<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * What Administration > Information reports about the installation (admin#info): the checklist of the
 * things to set up and the versions of the environment.
 */
final class SystemInfo
{
    public const string VERSION = '0.1.0';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire('%symine.files_path%')]
        private readonly string $filesPath,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    /** "Symine x.y.z" (Redmine::Info.versioned_name) */
    public function versionedName(): string
    {
        return 'Symine '.self::VERSION;
    }

    /** @return list<array{0: string, 1: bool}> label key and whether the check passes */
    public function checklist(): array
    {
        $admin = $this->em->getRepository(User::class)->findOneBy(['login' => 'admin', 'status' => User::STATUS_ACTIVE]);
        $defaultAdminChanged = true;
        if ($admin instanceof User && null !== $admin->getPassword()) {
            try {
                $defaultAdminChanged = !$this->hasher->isPasswordValid($admin, 'admin');
            } catch (\Throwable) {
            }
        }

        return [
            ['text_default_administrator_account_changed', $defaultAdminChanged],
            ['text_file_repository_writable', is_dir($this->filesPath) ? is_writable($this->filesPath) : is_writable(\dirname($this->filesPath))],
            ['text_minimagick_available', \extension_loaded('imagick') || \extension_loaded('gd')],
            ['text_convert_available', self::commandAvailable('convert') || self::commandAvailable('magick')],
            ['text_gs_available', self::commandAvailable('gs')],
        ];
    }

    /** @return array<string, mixed> label => value */
    public function environment(): array
    {
        $conn = $this->em->getConnection();
        $platform = (new \ReflectionClass($conn->getDatabasePlatform()))->getShortName();

        return [
            'Symine version' => self::VERSION.' (Redmine 7.0 compatible)',
            'PHP version' => \PHP_VERSION.' ('.\PHP_SAPI.')',
            'Symfony version' => Kernel::VERSION,
            'Environment' => $this->environment,
            'Database adapter' => preg_replace('/Platform$/', '', $platform),
            'Mailer delivery' => self::mailerScheme(),
            'Files directory' => $this->filesPath,
        ];
    }

    private static function commandAvailable(string $cmd): bool
    {
        if (!\function_exists('shell_exec')) {
            return false;
        }
        $out = @shell_exec('command -v '.escapeshellarg($cmd).' 2>/dev/null');

        return \is_string($out) && '' !== trim($out);
    }

    private static function mailerScheme(): string
    {
        $dsn = (string) ($_SERVER['MAILER_DSN'] ?? $_ENV['MAILER_DSN'] ?? getenv('MAILER_DSN') ?: '');

        return '' === $dsn ? 'n/a' : (string) (parse_url($dsn, \PHP_URL_SCHEME) ?: strtok($dsn, ':'));
    }
}

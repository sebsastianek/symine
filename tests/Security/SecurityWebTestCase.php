<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security / permission tests (anonymous, non member, member without/with permission, admin), run against
 * var/test{TEST_TOKEN}.db, a fresh copy of tests/fixtures/symine-seeded.db for every test.
 *
 * Seeded memberships: jsmith = Manager of ecommerce & checkout, Developer of internal (private);
 * dlopper = Developer of ecommerce (+ group Developers); rhill = Reporter of ecommerce; anna = no membership.
 */
abstract class SecurityWebTestCase extends WebTestCase
{
    public const int ROLE_NON_MEMBER = 1;
    public const int ROLE_ANONYMOUS = 2;
    public const int ROLE_MANAGER = 3;
    public const int ROLE_DEVELOPER = 4;
    public const int ROLE_REPORTER = 5;

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $dir = \dirname(__DIR__, 2);
        copy($dir.'/tests/fixtures/symine-seeded.db', $dir.'/var/test'.($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '').'.db');
        $this->client = static::createClient();
        self::restoreSeededFiles();
        // every legacy (SHA1) hash becomes bcrypt("password123") so that logged-in sessions survive reboots
        $this->conn()->executeStatement('UPDATE users SET hashed_password = ?, salt = NULL WHERE login <> \'\'', [password_hash('password123', \PASSWORD_BCRYPT, ['cost' => 4])]);
    }

    protected function tearDown(): void
    {
        // tests deleting attachment #1 remove its file from the shared files directory: put it back
        self::restoreSeededFiles();
        parent::tearDown();
    }

    private static function restoreSeededFiles(): void
    {
        $dir = \dirname(__DIR__, 2);
        $files = static::getContainer()->get(\App\Service\AttachmentService::class)->storagePath();
        $rel = '2026/11/261107134754_notes.txt';
        if (!is_file($files.'/'.$rel) && is_file($dir.'/var/redmine-src/files/'.$rel)) {
            @mkdir(\dirname($files.'/'.$rel), 0o775, true);
            copy($dir.'/var/redmine-src/files/'.$rel, $files.'/'.$rel);
        }
    }

    protected function conn(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    protected function dbOne(string $sql, array $params = []): mixed
    {
        return $this->conn()->fetchOne($sql, $params);
    }

    /** Starts a new browser session as the given user (null = anonymous) */
    protected function as(?string $login): void
    {
        $this->client->restart();
        if (null === $login) {
            return;
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['login' => $login]);
        self::assertNotNull($user, $login);
        $this->client->loginUser($user, 'main');
    }

    /** @param list<string> $add @param list<string> $remove */
    protected function rolePermissions(int $roleId, array $add = [], array $remove = []): void
    {
        $perms = json_decode((string) $this->dbOne('SELECT permissions FROM roles WHERE id = ?', [$roleId]), true) ?: [];
        $perms = array_values(array_diff(array_unique(array_merge($perms, $add)), $remove));
        $this->conn()->executeStatement('UPDATE roles SET permissions = ? WHERE id = ?', [json_encode($perms), $roleId]);
    }

    protected function setting(string $name, string $value): void
    {
        $id = $this->dbOne('SELECT id FROM settings WHERE name = ?', [$name]);
        if (false === $id) {
            $this->conn()->executeStatement("INSERT INTO settings (name, value, updated_on) VALUES (?, ?, datetime('now'))", [$name, $value]);
        } else {
            $this->conn()->executeStatement('UPDATE settings SET value = ? WHERE id = ?', [$value, $id]);
        }
    }

    protected function get(string $url, array $server = []): Response
    {
        $this->client->request('GET', $url, [], [], $server);

        return $this->client->getResponse();
    }

    protected function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    protected function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** Global CSRF token (layout meta tag) of the current session */
    protected function csrf(): string
    {
        $crawler = $this->client->request('GET', '/my/page');
        if (0 === $crawler->filter('meta[name="csrf-token"]')->count()) {
            $crawler = $this->client->request('GET', '/');
        }

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }

    /** Classifies a response like Redmine's deny_access: login redirect, 403, 404 or success */
    protected function outcome(): string
    {
        $r = $this->client->getResponse();
        if ($r->isRedirect()) {
            $location = (string) $r->headers->get('Location');

            return str_contains($location, '/login') ? 'login' : 'redirect';
        }

        return match (true) {
            $r->isSuccessful() => 'ok',
            401 === $r->getStatusCode() => 'login',
            default => (string) $r->getStatusCode(),
        };
    }

    /**
     * Checksum of the whole database (columns changed by any request - last login, token usage - excluded),
     * used to prove that a rejected request wrote nothing.
     */
    protected function fingerprint(array $ignoreTables = []): string
    {
        $conn = $this->conn();
        $tables = [];
        foreach ($conn->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            if (\in_array($table, $ignoreTables, true)) {
                continue;
            }
            $hash = hash_init('sha256');
            foreach ($conn->fetchAllAssociative('SELECT * FROM "'.$table.'"') as $row) {
                unset($row['last_login_on']);
                if ('tokens' === $table) {
                    unset($row['updated_on']);
                }
                hash_update($hash, json_encode($row));
            }
            $tables[] = $table.':'.substr(hash_final($hash), 0, 12);
        }

        // one line per table: a failed comparison shows which tables changed
        return implode("\n", $tables);
    }
}

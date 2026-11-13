<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Service\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST API parity tests (port of reference/redmine/test/integration/api_test/*.rb), run against
 * var/test{TEST_TOKEN}.db, a fresh copy of tests/fixtures/symine-seeded.db for every test.
 *
 * Every request goes through a rebooted kernel (stateless, like a real API client).
 * Identities: 'jsmith' (seeded API key), any other login (an API token is created on demand),
 * null (anonymous). Seeded data: see tests/fixtures/seed_redmine.rb.
 */
abstract class ApiTestCase extends WebTestCase
{
    public const string JSMITH_KEY = '3de6a93a7c5f8921fa98e0fecd9ae25695aa7536';

    /** Seeded ids (tests/fixtures/symine-seeded.db) */
    public const int ADMIN = 1;
    public const int JSMITH = 5;
    public const int DLOPPER = 6;
    public const int RHILL = 7;
    public const int LOCKED = 8;
    public const int ANNA = 9;
    public const int GROUP_DEVELOPERS = 10;
    public const int P_ECOMMERCE = 1;
    public const int P_INTERNAL = 2;
    public const int P_CHECKOUT = 3;
    public const int P_LEGACY = 4; // archived
    public const int ISSUE_CART = 13;
    public const int ISSUE_WISHLIST = 14;
    public const int ISSUE_SHARING = 15;
    public const int ISSUE_EMAILS = 16;
    public const int ISSUE_SAFARI = 17; // private, project checkout
    public const int ISSUE_INTERNAL = 18; // private project
    public const int ISSUE_LOGIN = 19; // closed

    protected KernelBrowser $client;

    /** @var array<string, string> */
    private array $keys = ['jsmith' => self::JSMITH_KEY];

    protected function setUp(): void
    {
        $dir = \dirname(__DIR__, 2);
        copy($dir.'/tests/fixtures/symine-seeded.db', $dir.'/var/test'.($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '').'.db');
        $this->client = static::createClient();
        self::restoreSeededFiles();
    }

    protected function tearDown(): void
    {
        self::restoreSeededFiles();
        parent::tearDown();
    }

    /** Tests deleting attachment #1 remove its file from the shared files directory: put it back */
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

    /** @return list<array<string, mixed>> */
    protected function db(string $sql, array $params = []): array
    {
        return $this->conn()->fetchAllAssociative($sql, $params);
    }

    protected function dbOne(string $sql, array $params = []): mixed
    {
        return $this->conn()->fetchOne($sql, $params);
    }

    protected function rows(string $table, string $where = '1=1', array $params = []): int
    {
        return (int) $this->dbOne("SELECT COUNT(*) FROM $table WHERE $where", $params);
    }

    /** API key of a user (created when missing) */
    protected function key(string $login): string
    {
        if (isset($this->keys[$login])) {
            return $this->keys[$login];
        }
        $conn = $this->conn();
        $key = $conn->fetchOne("SELECT t.value FROM tokens t JOIN users u ON u.id = t.user_id WHERE u.login = ? AND t.action = 'api'", [$login]);
        if (!$key) {
            $userId = $conn->fetchOne('SELECT id FROM users WHERE login = ?', [$login]);
            self::assertNotFalse($userId, "user $login");
            $key = substr(hash('sha1', 'api-'.$login), 0, 40);
            $conn->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) VALUES (?, 'api', ?, datetime('now'), datetime('now'))", [$userId, $key]);
        }

        return $this->keys[$login] = (string) $key;
    }

    /** Changes a setting in the test database (effective from the next request) */
    protected function setting(string $name, mixed $value): void
    {
        $settings = static::getContainer()->get(Settings::class);
        $settings->set($name, $value);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /**
     * Raw API request.
     *
     * @param string|array|null $body    array => JSON (or form params when $form), string => raw body
     * @param string|null       $as      login authenticated with its API key (X-Redmine-API-Key header), null = anonymous
     * @param array<string, string> $headers extra server params (HTTP_*)
     */
    protected function request(string $method, string $url, string|array|null $body = null, ?string $as = 'jsmith', array $headers = [], bool $form = false): Response
    {
        $server = $headers;
        if (null !== $as) {
            $server['HTTP_X_REDMINE_API_KEY'] = $this->key($as);
        }
        $params = [];
        $content = null;
        if (\is_array($body) && $form) {
            $params = $body;
        } elseif (\is_array($body)) {
            $content = json_encode($body);
            $server['CONTENT_TYPE'] ??= 'application/json';
        } elseif (\is_string($body)) {
            $content = $body;
            $server['CONTENT_TYPE'] ??= str_starts_with(ltrim($body), '<') ? 'application/xml' : 'application/json';
        }
        $this->client->request($method, $url, $params, [], $server, $content);

        return $this->client->getResponse();
    }

    /** JSON API request, returns the decoded body ([] for empty bodies) */
    protected function json(string $method, string $url, ?array $body = null, ?string $as = 'jsmith', array $headers = []): array
    {
        $content = (string) $this->request($method, $url, $body, $as, $headers)->getContent();
        if ('' === trim($content)) {
            return [];
        }
        $data = json_decode($content, true);

        return \is_array($data) ? $data : [];
    }

    /** XML API request, returns the parsed document */
    protected function xml(string $method, string $url, ?string $body = null, ?string $as = 'jsmith', array $headers = []): \SimpleXMLElement
    {
        $content = (string) $this->request($method, $url, $body, $as, $headers)->getContent();
        if ('' === trim($content)) {
            return new \SimpleXMLElement('<empty/>');
        }
        $xml = @simplexml_load_string($content);
        self::assertNotFalse($xml, 'invalid XML: '.mb_substr($content, 0, 500));

        return $xml;
    }

    /** HTTP basic credentials */
    protected static function basic(string $user, string $password = 'password123'): array
    {
        return ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $password];
    }

    protected function response(): Response
    {
        return $this->client->getResponse();
    }

    protected function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    protected function assertStatus(int $expected, string $message = ''): void
    {
        $r = $this->client->getResponse();
        self::assertSame($expected, $r->getStatusCode(), trim($message.' '.($r->getStatusCode() !== $expected ? mb_substr(strip_tags((string) $r->getContent()), 0, 1500) : '')));
    }

    protected function assertJsonResponse(int $status = 200): void
    {
        $this->assertStatus($status);
        self::assertStringStartsWith('application/json', (string) $this->response()->headers->get('Content-Type'));
    }

    protected function assertXmlResponse(int $status = 200): void
    {
        $this->assertStatus($status);
        self::assertStringStartsWith('application/xml', (string) $this->response()->headers->get('Content-Type'));
    }

    /** 422 with the given error messages (each one must be present) */
    protected function assertErrors(array $data, string ...$messages): void
    {
        $this->assertStatus(422);
        self::assertArrayHasKey('errors', $data);
        foreach ($messages as $m) {
            self::assertContains($m, $data['errors'], 'errors: '.json_encode($data['errors']));
        }
    }

    /** Collection payload: offset/limit/total_count meta like Redmine (api_meta) */
    protected function assertPaging(array $data, int $total, int $offset, int $limit): void
    {
        self::assertSame($total, $data['total_count'] ?? null, 'total_count');
        self::assertSame($offset, $data['offset'] ?? null, 'offset');
        self::assertSame($limit, $data['limit'] ?? null, 'limit');
    }

    /** ids of a collection */
    protected static function ids(array $items): array
    {
        return array_column($items, 'id');
    }
}

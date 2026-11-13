<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests of the content modules (wiki, news, documents, files, forums, repository)
 * against a fresh copy of the seeded database (ecommerce project: wiki with 2 pages, 1 news, 1 document, forum "General").
 */
abstract class ContentWebTestCase extends WebTestCase
{
    public const string JSMITH_KEY = '3de6a93a7c5f8921fa98e0fecd9ae25695aa7536';

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $dir = \dirname(__DIR__, 2);
        copy($dir.'/tests/fixtures/symine-seeded.db', $dir.'/var/test'.($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '').'.db');
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function login(string $login): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['login' => $login]);
        self::assertNotNull($user);
        // Real form login: loginUser() does not survive the token refresh for users with legacy
        // (salted SHA1) Redmine password hashes, the form login upgrades the hash.
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/login');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/login', ['username' => $login, 'password' => 'admin' === $login ? 'admin' : 'password123', '_csrf_token' => $token]);

        return $user;
    }

    protected function csrf(string $url = '/'): string
    {
        $crawler = $this->client->request('GET', $url);

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }

    protected function get(string $url): string
    {
        $this->client->request('GET', $url);

        return (string) $this->client->getResponse()->getContent();
    }

    protected function submit(string $url, array $params = [], string $method = 'POST', array $files = []): int
    {
        $params['_token'] ??= $this->csrf();
        $this->client->request($method, $url, $params, $files);

        return $this->client->getResponse()->getStatusCode();
    }

    protected function api(string $method, string $url, ?array $body = null, string $key = self::JSMITH_KEY): array
    {
        $this->client->request($method, $url, [], [], ['HTTP_X_REDMINE_API_KEY' => $key, 'CONTENT_TYPE' => 'application/json'], null === $body ? null : json_encode($body));
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : (json_decode($content, true) ?? []);
    }

    protected function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected function location(): string
    {
        return (string) $this->client->getResponse()->headers->get('Location');
    }

    protected function adminKey(): string
    {
        $conn = $this->em->getConnection();
        $key = $conn->fetchOne("SELECT t.value FROM tokens t JOIN users u ON u.id = t.user_id WHERE u.login = 'admin' AND t.action = 'api'");
        if (!$key) {
            $key = str_repeat('b', 40);
            $conn->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) SELECT id, 'api', ?, datetime('now'), datetime('now') FROM users WHERE login = 'admin'", [$key]);
        }

        return (string) $key;
    }

    /** Asserts 200 for the given user on all URLs */
    protected function assertPagesOk(array $urls): void
    {
        foreach ($urls as $url) {
            $this->client->request('GET', $url);
            $code = $this->httpStatus();
            if (200 !== $code) {
                $content = (string) $this->client->getResponse()->getContent();
                preg_match('#<title>(.*?)</title>#s', $content, $m);
                self::fail("GET $url returned $code: ".($m[1] ?? substr(strip_tags($content), 0, 500)));
            }
        }
    }
}

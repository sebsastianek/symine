<?php

declare(strict_types=1);

namespace App\Tests\Projects;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests of the projects area (projects, members, versions, categories, activity, search)
 * against a fresh copy of the seeded database.
 */
abstract class ProjectsWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $dir = \dirname(__DIR__, 2);
        copy($dir.'/tests/fixtures/symine-seeded.db', $dir.'/var/test'.($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '').'.db');
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function login(string $login): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['login' => $login]);
        self::assertNotNull($user);
        $this->client->loginUser($user, 'main');

        return $user;
    }

    /** CSRF token from the layout meta tag of a GET page */
    protected function csrf(string $url = '/'): string
    {
        $crawler = $this->client->request('GET', $url);

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }

    protected function get(string $url, array $server = []): string
    {
        $this->client->request('GET', $url, [], [], $server);

        return (string) $this->client->getResponse()->getContent();
    }

    /** POST (or other method via _method) with a CSRF token */
    protected function submit(string $url, array $params = [], string $method = 'POST'): int
    {
        $params['_token'] ??= $this->csrf();
        $this->client->request($method, $url, $params);

        return $this->client->getResponse()->getStatusCode();
    }

    /** API call with jsmith's (or given) API key */
    protected function api(string $method, string $url, ?array $body = null, string $key = '3de6a93a7c5f8921fa98e0fecd9ae25695aa7536'): array
    {
        $this->client->request($method, $url, [], [], ['HTTP_X_REDMINE_API_KEY' => $key, 'CONTENT_TYPE' => 'application/json'], null === $body ? null : json_encode($body));
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : (json_decode($content, true) ?? []);
    }

    protected function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected function adminKey(): string
    {
        $conn = $this->em->getConnection();
        $key = $conn->fetchOne("SELECT t.value FROM tokens t JOIN users u ON u.id = t.user_id WHERE u.login = 'admin' AND t.action = 'api'");
        if (!$key) {
            $key = str_repeat('a', 40);
            $conn->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) SELECT id, 'api', ?, datetime('now'), datetime('now') FROM users WHERE login = 'admin'", [$key]);
        }

        return (string) $key;
    }
}

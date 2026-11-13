<?php

declare(strict_types=1);

namespace App\Tests\Timelog;

use App\Entity\User;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests base (timelog, my page, account) on a fresh copy of the seeded database.
 * Seed: project ecommerce (id 1) with time entries #1 (dlopper, issue 13, 2.5h) and #2 (jsmith, 1h).
 */
abstract class TimelogWebTestCase extends WebTestCase
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

    protected function login(string $login, ?string $password = null): void
    {
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/login');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/login', ['username' => $login, 'password' => $password ?? ('admin' === $login ? 'admin' : 'password123'), '_csrf_token' => $token]);
    }

    protected function user(string $login): User
    {
        $u = $this->em->getRepository(User::class)->findOneBy(['login' => $login]);
        self::assertNotNull($u);

        return $u;
    }

    protected function setting(string $name, mixed $value): void
    {
        $s = static::getContainer()->get(Settings::class);
        $s->set($name, $value);
        $this->em->flush();
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

    protected function submit(string $url, array $params = [], string $method = 'POST', array $server = []): int
    {
        $params['_token'] ??= $this->csrf();
        $this->client->request($method, $url, $params, [], $server);

        return $this->client->getResponse()->getStatusCode();
    }

    protected function api(string $method, string $url, ?array $body = null, string $key = self::JSMITH_KEY): array
    {
        $this->client->request($method, $url, [], [], ['HTTP_X_REDMINE_API_KEY' => $key, 'CONTENT_TYPE' => 'application/json'], null === $body ? null : json_encode($body));
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : (json_decode($content, true) ?? []);
    }

    protected function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected function location(): string
    {
        return (string) $this->client->getResponse()->headers->get('Location');
    }

    protected function content(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    protected function assertPagesOk(array $urls): void
    {
        foreach ($urls as $url) {
            $this->client->request('GET', $url);
            $code = $this->code();
            if (200 !== $code) {
                preg_match('#<title>(.*?)</title>#s', $this->content(), $m);
                self::fail("GET $url returned $code: ".($m[1] ?? substr(strip_tags($this->content()), 0, 500)));
            }
        }
    }

    protected function sql(string $sql, array $params = []): mixed
    {
        return $this->em->getConnection()->fetchOne($sql, $params);
    }
}

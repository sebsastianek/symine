<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests of the administration area, run against var/test{TEST_TOKEN}.db
 * (a fresh copy of tests/fixtures/symine-seeded.db for every test).
 */
abstract class AdminWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $project = \dirname(__DIR__, 2);
        copy($project.'/tests/fixtures/symine-seeded.db', $project.'/var/test'.($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '').'.db');
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function user(string $login): User
    {
        $u = $this->em->getRepository(User::class)->findOneBy(['login' => $login]);
        self::assertNotNull($u, "user $login");

        return $u;
    }

    /**
     * Logs the user in (KernelBrowser::loginUser). Legacy Redmine SHA1 hashes are replaced by a
     * bcrypt hash of "password123" first: the salt is not serialized with the user, so the token
     * of a legacy-hash user would be considered changed and deauthenticated on the next request.
     */
    protected function loginAs(string $login): void
    {
        $user = $this->user($login);
        if (null !== $user->getSalt() || !str_starts_with($user->hashedPassword, '$')) {
            $user->hashedPassword = password_hash('password123', \PASSWORD_BCRYPT, ['cost' => 4]);
            $user->salt = null;
            $this->em->flush();
        }
        $this->client->loginUser($user, 'main');
    }

    /** Global CSRF token (meta csrf-token of any page) */
    protected function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/');

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }

    protected function get(string $url): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->request('GET', $url);
    }

    /** POST (or other method via _method) with the CSRF token added */
    protected function post(string $url, array $params = [], string $method = 'POST'): \Symfony\Component\HttpFoundation\Response
    {
        $params['_token'] ??= $this->csrfToken();
        if ('POST' !== $method) {
            $params['_method'] = $method;
        }
        $this->client->request('POST', $url, $params);

        return $this->client->getResponse();
    }

    /** API request authenticated with HTTP basic auth (admin/admin by default) */
    protected function api(string $method, string $url, ?array $json = null, string $login = 'admin', string $password = 'admin'): \Symfony\Component\HttpFoundation\Response
    {
        $this->client->request($method, $url, [], [], [
            'PHP_AUTH_USER' => $login,
            'PHP_AUTH_PW' => $password,
            'CONTENT_TYPE' => 'application/json',
        ], null === $json ? null : json_encode($json));

        return $this->client->getResponse();
    }

    protected function assertStatus(int $expected, ?string $message = null): void
    {
        $r = $this->client->getResponse();
        $body = $r->getStatusCode() !== $expected ? mb_substr(strip_tags((string) $r->getContent()), 0, 1500) : '';
        self::assertSame($expected, $r->getStatusCode(), ($message ?? '').' '.$body);
    }

    protected function assertRedirectTo(string $path): void
    {
        $r = $this->client->getResponse();
        self::assertTrue($r->isRedirect(), 'expected redirect, got '.$r->getStatusCode().' '.mb_substr(strip_tags((string) $r->getContent()), 0, 800));
        self::assertSame($path, parse_url((string) $r->headers->get('Location'), \PHP_URL_PATH).(($q = parse_url((string) $r->headers->get('Location'), \PHP_URL_QUERY)) ? '?'.$q : ''));
    }
}

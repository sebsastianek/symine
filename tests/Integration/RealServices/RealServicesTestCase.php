<?php

declare(strict_types=1);

namespace App\Tests\Integration\RealServices;

use App\Tests\Admin\AdminWebTestCase;

/**
 * Tests run against real services (LDAP, IMAP/POP3, SMTP catcher, HTTP receiver). They are skipped
 * unless the corresponding SYMINE_TEST_* environment variables are set. Start the services with
 *
 *   docker compose -f tests/Integration/RealServices/compose.yaml up -d
 *
 * and run them with the variables listed in that file, e.g.
 *
 *   SYMINE_TEST_LDAP_URL=ldap://localhost:13890 SYMINE_TEST_LDAPS_URL=ldaps://localhost:16360 \
 *   SYMINE_TEST_IMAP_URL=imap://localhost:13143 SYMINE_TEST_POP3_URL=pop3://localhost:13110 \
 *   SYMINE_TEST_MAILPIT_URL=http://localhost:18125 SYMINE_TEST_SMTP_DSN=smtp://localhost:11125 \
 *   SYMINE_TEST_WEBHOOK_RECEIVER_URL=http://localhost:18190 \
 *   php bin/phpunit tests/Integration/RealServices
 */
abstract class RealServicesTestCase extends AdminWebTestCase
{
    /** Value of an environment variable, or skips the test when it is not set */
    protected static function env(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        if (!\is_string($value) || '' === $value) {
            self::markTestSkipped("$name is not set (see tests/Integration/RealServices/compose.yaml)");
        }

        return $value;
    }

    protected static function optionalEnv(string $name, string $default): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($value) && '' !== $value ? $value : $default;
    }

    /** @return array{scheme: string, host: string, port: int} */
    protected static function parseUrl(string $url, int $defaultPort): array
    {
        $p = parse_url($url);

        return ['scheme' => (string) ($p['scheme'] ?? ''), 'host' => (string) ($p['host'] ?? 'localhost'), 'port' => (int) ($p['port'] ?? $defaultPort)];
    }

    /** Logs in through the login form with any password (LDAP users) */
    protected function formLogin(string $login, string $password): \Symfony\Component\HttpFoundation\Response
    {
        $crawler = $this->client->request('GET', '/login');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/login', ['username' => $login, 'password' => $password, '_csrf_token' => $token]);

        return $this->client->getResponse();
    }
}

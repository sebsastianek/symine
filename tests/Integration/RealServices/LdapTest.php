<?php

declare(strict_types=1);

namespace App\Tests\Integration\RealServices;

use App\Entity\AuthSource;
use App\Entity\User;
use App\Service\LdapService;

/**
 * LDAP authentication against a real OpenLDAP server seeded with fixtures/ldap-users.ldif
 * (SYMINE_TEST_LDAP_URL, e.g. ldap://localhost:13890; optional SYMINE_TEST_LDAPS_URL).
 * Behaviour verified against the reference Redmine configured with the same directory.
 */
final class LdapTest extends RealServicesTestCase
{
    private function source(?string $url = null, array $overrides = []): AuthSource
    {
        $u = self::parseUrl($url ?? self::env('SYMINE_TEST_LDAP_URL'), 389);
        $s = new AuthSource();
        $s->name = 'Real LDAP';
        $s->host = $u['host'];
        $s->port = $u['port'];
        LdapService::setLdapMode($s, 'ldaps' === $u['scheme'] ? 'ldaps_verify_none' : 'ldap');
        $s->baseDn = self::optionalEnv('SYMINE_TEST_LDAP_BASE_DN', 'ou=people,dc=symine,dc=test');
        $s->account = self::optionalEnv('SYMINE_TEST_LDAP_BIND_DN', 'cn=admin,dc=symine,dc=test');
        $s->accountPassword = self::optionalEnv('SYMINE_TEST_LDAP_BIND_PASSWORD', 'adminpw');
        $s->attrLogin = 'uid';
        $s->attrFirstname = 'givenName';
        $s->attrLastname = 'sn';
        $s->attrMail = 'mail';
        $s->timeout = 5;
        $s->ontheflyRegister = true;
        foreach ($overrides as $k => $v) {
            $s->{$k} = $v;
        }
        $this->em->persist($s);
        $this->em->flush();

        return $s;
    }

    private function ldap(): LdapService
    {
        return self::getContainer()->get(LdapService::class);
    }

    public function testConnection(): void
    {
        $this->ldap()->testConnection($this->source());

        try {
            $this->ldap()->testConnection($this->source(null, ['accountPassword' => 'wrong']));
            self::fail('bind with a wrong password must fail');
        } catch (\RuntimeException $e) {
            // AuthSourceException raised inside with_timeout: no "LDAP:" prefix
            self::assertSame('Invalid LDAP Account/Password', $e->getMessage());
        }

        try {
            $this->ldap()->testConnection($this->source(null, ['port' => 1]));
            self::fail('connection to a closed port must fail');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('LDAP: ', $e->getMessage());
        }
    }

    public function testConnectionThroughAdmin(): void
    {
        $source = $this->source();
        $this->loginAs('admin');
        $this->client->request('GET', '/auth_sources/'.$source->id.'/test_connection');
        $this->client->followRedirect();
        self::assertStringContainsString('Successful connection.', (string) $this->client->getResponse()->getContent());
    }

    public function testAuthenticateMapsAttributes(): void
    {
        $source = $this->source();
        self::assertSame(
            ['firstname' => 'Lisa', 'lastname' => 'Dapper', 'mail' => 'lisa.dapper@ldap.symine.test', 'auth_source_id' => $source->id],
            $this->ldap()->authenticate($source, 'ldapuser', 'ldappass1'),
        );
        self::assertNull($this->ldap()->authenticate($source, 'ldapuser', 'wrong'));
        self::assertNull($this->ldap()->authenticate($source, 'nosuchuser', 'whatever'));
        // without on-the-fly registration only the DN is looked up
        $source->ontheflyRegister = false;
        self::assertSame([], $this->ldap()->authenticate($source, 'ldapuser', 'ldappass1'));
    }

    public function testFilter(): void
    {
        $source = $this->source(null, ['filter' => '(!(departmentNumber=external))']);
        self::assertNull($this->ldap()->authenticate($source, 'outsider', 'outpass1'));
        self::assertNotNull($this->ldap()->authenticate($source, 'bob', 'bobpass1'));
    }

    public function testBindAsUser(): void
    {
        // account with $login: the user's own credentials are used to search the directory
        $source = $this->source(null, ['account' => 'uid=$login,ou=people,dc=symine,dc=test', 'accountPassword' => null]);
        self::assertSame('Bob', $this->ldap()->authenticate($source, 'bob', 'bobpass1')['firstname'] ?? null);
        self::assertNull($this->ldap()->authenticate($source, 'bob', 'wrong'));
    }

    public function testSearch(): void
    {
        $this->source();
        $results = $this->ldap()->search('bo');
        self::assertSame(['bob'], array_column($results, 'login'));
        self::assertSame('bob@ldap.symine.test', $results[0]['mail']);
    }

    public function testOnTheFlyRegistrationThroughLoginForm(): void
    {
        $source = $this->source();
        $response = $this->formLogin('ldapuser', 'wrong');
        self::assertStringEndsWith('/login', (string) $response->headers->get('Location'));
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['login' => 'ldapuser']));

        $response = $this->formLogin('ldapuser', 'ldappass1');
        self::assertTrue($response->isRedirect(), (string) $response->getContent());
        self::assertStringEndsWith('/my/page', (string) $response->headers->get('Location'));
        $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['login' => 'ldapuser']);
        self::assertNotNull($user);
        self::assertSame(['Lisa', 'Dapper', 'lisa.dapper@ldap.symine.test', $source->id, User::STATUS_ACTIVE], [$user->firstname, $user->lastname, $user->getMail(), $user->authSourceId, $user->status]);
        self::assertSame('', (string) $user->hashedPassword);
        self::assertNotNull($user->lastLoginOn);

        // a local user (no auth source) is not authenticated against LDAP
        $this->client->getCookieJar()->clear();
        $response = $this->formLogin('jsmith', 'jsmithldap');
        self::assertStringEndsWith('/login', (string) $response->headers->get('Location'));
    }

    public function testFailedOnTheFlyRegistrationShowsTheRegistrationForm(): void
    {
        $source = $this->source();
        // no mail attribute in the directory
        $response = $this->formLogin('nomail', 'nomailpass');
        self::assertStringEndsWith('/account/register', (string) $response->headers->get('Location'));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Email cannot be blank', $crawler->filter('#errorExplanation')->text());
        self::assertSame('No', $crawler->filter('#user_firstname')->attr('value'));
        self::assertSame('Mail', $crawler->filter('#user_lastname')->attr('value'));
        self::assertCount(0, $crawler->filter('#user_login'));
        self::assertCount(0, $crawler->filter('#user_password'));

        $this->client->submit($crawler->filter('#new_user')->form(['user[mail]' => 'nomail@ldap.symine.test']));
        self::assertStringEndsWith('/my/account', (string) $this->client->getResponse()->headers->get('Location'));
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['login' => 'nomail']);
        self::assertSame([$source->id, User::STATUS_ACTIVE, 'nomail@ldap.symine.test'], [$user?->authSourceId, $user?->status, $user?->getMail()]);
    }

    public function testExistingUserWhenTheServerIsDown(): void
    {
        $source = $this->source();
        self::assertSame(302, $this->formLogin('bob', 'bobpass1')->getStatusCode());
        $this->client->getCookieJar()->clear();
        $source = $this->em->find(AuthSource::class, $source->id);
        $source->port = 1;
        $this->em->flush();
        // AccountController#login rescues AuthSourceException with an error page
        $response = $this->formLogin('bob', 'bobpass1');
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('LDAP: ', (string) $response->getContent());
    }

    public function testApiBasicAuth(): void
    {
        $this->source();
        $this->client->request('GET', '/users/current.json', server: ['PHP_AUTH_USER' => 'bob', 'PHP_AUTH_PW' => 'bobpass1']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('bob', json_decode((string) $this->client->getResponse()->getContent(), true)['user']['login']);
        $this->client->request('GET', '/users/current.json', server: ['PHP_AUTH_USER' => 'bob', 'PHP_AUTH_PW' => 'wrong']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testLdaps(): void
    {
        $url = self::env('SYMINE_TEST_LDAPS_URL');
        $source = $this->source($url);
        $this->ldap()->testConnection($source);
        self::assertNotNull($this->ldap()->authenticate($source, 'bob', 'bobpass1'));

        // the test server uses a self-signed certificate
        LdapService::setLdapMode($source, 'ldaps_verify_peer');
        try {
            $this->ldap()->testConnection($source);
            self::fail('certificate verification must fail');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('certificate verify failed', $e->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AuthSource;
use App\Entity\User;
use App\Service\LdapService;
use App\Tests\Admin\Fixtures\FakeLdap;

final class AuthSourcesTest extends AdminWebTestCase
{
    private function createSource(bool $onTheFly = true): AuthSource
    {
        $s = new AuthSource();
        $s->name = 'Corporate LDAP';
        $s->host = 'ldap.example.net';
        $s->port = 389;
        $s->baseDn = 'ou=people,dc=example,dc=net';
        $s->account = 'cn=admin,dc=example,dc=net';
        $s->accountPassword = 'secret';
        $s->attrLogin = 'uid';
        $s->attrFirstname = 'givenName';
        $s->attrLastname = 'sn';
        $s->attrMail = 'mail';
        $s->ontheflyRegister = $onTheFly;
        $this->em->persist($s);
        $this->em->flush();

        return $s;
    }

    private function fakeDirectory(): FakeLdap
    {
        $ldap = new FakeLdap([
            'uid=ldapuser,ou=people,dc=example,dc=net' => ['password' => 'ldappass', 'attributes' => ['uid' => ['ldapuser'], 'givenName' => ['Lara'], 'sn' => ['Dap'], 'mail' => ['lara@example.net']]],
            'uid=jsmith,ou=people,dc=example,dc=net' => ['password' => 'fromldap', 'attributes' => ['uid' => ['jsmith'], 'givenName' => ['John'], 'sn' => ['Smith'], 'mail' => ['jsmith@somenet.foo']]],
        ], 'cn=admin,dc=example,dc=net', 'secret');
        self::getContainer()->get(LdapService::class)->setLdapFactory(static fn () => $ldap);

        return $ldap;
    }

    private function login(string $login, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/login', ['username' => $login, 'password' => $password, '_csrf_token' => $token, 'back_url' => '/']);
    }

    public function testCrud(): void
    {
        $this->loginAs('admin');
        $this->post('/auth_sources', ['type' => 'AuthSourceLdap', 'auth_source' => ['name' => '', 'host' => '', 'port' => '389', 'attr_login' => '', 'filter' => '(uid=']]);
        $this->assertStatus(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('errorExplanation', $content);
        self::assertStringContainsString('LDAP filter', $content);

        $this->post('/auth_sources', ['type' => 'AuthSourceLdap', 'auth_source' => [
            'name' => 'My LDAP', 'host' => 'ldap.example.net', 'port' => '636', 'ldap_mode' => 'ldaps_verify_peer',
            'account' => 'cn=admin', 'account_password' => 'pw', 'base_dn' => 'dc=example,dc=net', 'filter' => '(objectClass=person)',
            'timeout' => '5', 'onthefly_register' => '1', 'attr_login' => ' uid ', 'attr_firstname' => 'givenName', 'attr_lastname' => 'sn', 'attr_mail' => 'mail',
        ]]);
        $this->assertRedirectTo('/auth_sources');
        $source = $this->em->getRepository(AuthSource::class)->findOneBy(['name' => 'My LDAP']);
        self::assertNotNull($source);
        self::assertTrue($source->tls);
        self::assertTrue($source->verifyPeer);
        self::assertSame(636, $source->port);
        self::assertSame('uid', $source->attrLogin);
        self::assertTrue($source->ontheflyRegister);

        $crawler = $this->get('/auth_sources');
        self::assertCount(1, $crawler->filter('#auth-source-'.$source->id));
        $crawler = $this->get('/auth_sources/'.$source->id.'/edit');
        $this->assertStatus(200);
        self::assertSame('ldaps_verify_peer', $crawler->filter('#auth_source_ldap_mode option[selected]')->attr('value'));

        $this->post('/auth_sources/'.$source->id, ['auth_source' => ['name' => 'Renamed', 'ldap_mode' => 'ldap', 'port' => '389']], 'PATCH');
        $this->assertRedirectTo('/auth_sources');
        $this->em->clear();
        $source = $this->em->find(AuthSource::class, $source->id);
        self::assertSame('Renamed', $source->name);
        self::assertFalse($source->tls);
        self::assertSame('pw', $source->accountPassword, 'password kept when not submitted');

        // in use: cannot be deleted
        $jsmith = $this->user('jsmith');
        $jsmith->authSourceId = $source->id;
        $this->em->flush();
        $this->post('/auth_sources/'.$source->id, [], 'DELETE');
        $this->assertRedirectTo('/auth_sources');
        self::assertNotNull($this->em->find(AuthSource::class, $source->id));
        $this->user('jsmith')->authSourceId = null; // entities are detached after each request
        $this->em->flush();
        $this->post('/auth_sources/'.$source->id, [], 'DELETE');
        $this->em->clear();
        self::assertNull($this->em->find(AuthSource::class, $source->id));
    }

    public function testTestConnection(): void
    {
        $source = $this->createSource();
        $this->loginAs('admin');
        $this->fakeDirectory();
        $this->get('/auth_sources/'.$source->id.'/test_connection');
        $this->assertRedirectTo('/auth_sources');
        $this->client->followRedirect();
        self::assertStringContainsString('Successful connection', (string) $this->client->getResponse()->getContent());

        $this->em->find(AuthSource::class, $source->id)->accountPassword = 'wrong';
        $this->em->flush();
        $this->get('/auth_sources/'.$source->id.'/test_connection');
        $this->client->followRedirect();
        self::assertStringContainsString('Unable to connect', (string) $this->client->getResponse()->getContent());
    }

    public function testOnTheFlyRegistrationAndLogin(): void
    {
        $source = $this->createSource();
        $this->fakeDirectory();
        $this->login('ldapuser', 'wrong');
        $this->assertRedirectTo('/login');
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['login' => 'ldapuser']));

        $this->login('ldapuser', 'ldappass');
        $this->assertRedirectTo('/');
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['login' => 'ldapuser']);
        self::assertNotNull($user, 'user created on the fly');
        self::assertSame($source->id, $user->authSourceId);
        self::assertSame('Lara', $user->firstname);
        self::assertSame('lara@example.net', $user->getMail());
        $crawler = $this->get('/');
        self::assertStringContainsString('@ldapuser', $crawler->filter('#account')->text());

        // existing LDAP user logs in again through LDAP
        $this->client->getCookieJar()->clear();
        $this->login('ldapuser', 'ldappass');
        $this->assertRedirectTo('/');
    }

    public function testExistingUserBoundToSourceAndLocalUsers(): void
    {
        $source = $this->createSource(false);
        $this->fakeDirectory();
        $jsmith = $this->user('jsmith');
        $jsmith->authSourceId = $source->id;
        $this->em->flush();
        $this->login('jsmith', 'password123');
        $this->assertRedirectTo('/login');
        $this->login('jsmith', 'fromldap');
        $this->assertRedirectTo('/');

        // local accounts are still handled by the form login
        $this->client->getCookieJar()->clear();
        $this->login('dlopper', 'password123');
        $this->assertRedirectTo('/');
        // unknown login without on-the-fly source
        $this->client->getCookieJar()->clear();
        $this->login('ldapuser', 'ldappass');
        $this->assertRedirectTo('/login');
    }
}

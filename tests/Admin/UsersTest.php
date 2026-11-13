<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AnonymousUser;
use App\Entity\EmailAddress;
use App\Entity\Issue;
use App\Entity\Member;
use App\Entity\Principal;
use App\Entity\User;

final class UsersTest extends AdminWebTestCase
{
    use FormLoginTrait;

    public function testPagesAsAdmin(): void
    {
        $this->loginAs('admin');
        $jsmith = $this->user('jsmith');
        foreach (['/users', '/users?status=3', '/users?status=&name=smith', '/users?group_id=10', '/users?sort=lastname:desc', '/users/new',
            '/users/'.$jsmith->id, '/users/'.$jsmith->id.'/edit', '/users/'.$jsmith->id.'/edit?tab=memberships',
            '/users/'.$jsmith->id.'/edit?tab=groups', '/users/'.$jsmith->id.'/email_addresses', '/users/'.$jsmith->id.'/memberships/new',
            '/users/current'] as $url) {
            $this->get($url);
            $this->assertStatus(200, $url);
        }
        $crawler = $this->get('/users?status=');
        self::assertGreaterThanOrEqual(6, $crawler->filter('table.users tbody tr')->count());
        self::assertSame(1, $this->get('/users?name=jsmith')->filter('table.users tbody tr')->count());
    }

    public function testAdminPagesForbiddenToNonAdmin(): void
    {
        $this->loginAs('jsmith');
        $dl = $this->user('dlopper');
        foreach (['/users', '/users/new', '/users/'.$dl->id.'/edit', '/users/'.$dl->id.'/memberships/new', '/users/'.$dl->id.'/email_addresses'] as $url) {
            $this->get($url);
            $this->assertStatus(403, $url);
        }
        // profile pages are visible to logged in users
        $this->get('/users/'.$dl->id);
        $this->assertStatus(200);
        // own email addresses page is allowed
        $this->get('/users/'.$this->user('jsmith')->id.'/email_addresses');
        $this->assertStatus(200);
        // locked users are not visible
        $this->get('/users/'.$this->user('locked')->id);
        $this->assertStatus(404);
    }

    public function testCreateUser(): void
    {
        $this->loginAs('admin');
        $this->post('/users', ['user' => [
            'login' => 'newbie', 'firstname' => 'New', 'lastname' => 'Bie', 'mail' => 'newbie@example.net',
            'password' => 'secret123', 'password_confirmation' => 'secret123', 'language' => 'en', 'mail_notification' => 'none',
            'must_change_passwd' => '1', 'custom_field_values' => [6 => '+42'],
        ], 'pref' => ['hide_mail' => '1', 'time_zone' => 'Berlin', 'comments_sorting' => 'desc']]);
        $this->em->clear();
        $u = $this->em->getRepository(User::class)->findOneBy(['login' => 'newbie']);
        self::assertNotNull($u);
        $this->assertRedirectTo('/users/'.$u->id.'/edit');
        self::assertSame('newbie@example.net', $u->getMail());
        self::assertTrue($u->mustChangePasswd);
        self::assertSame('none', $u->mailNotification);
        self::assertTrue($u->getPref()->hideMail);
        self::assertSame('Berlin', $u->getPref()->timeZone);
        self::assertSame('desc', $u->getPref()->commentsSorting());
        self::assertNotSame('', $u->hashedPassword);
        $cv = $this->em->getConnection()->fetchOne("SELECT value FROM custom_values WHERE customized_type='Principal' AND customized_id=? AND custom_field_id=6", [$u->id]);
        self::assertSame('+42', $cv);
        // new user can log in
        $this->client->request('GET', '/logout');
    }

    public function testCreateUserValidation(): void
    {
        $this->loginAs('admin');
        $this->post('/users', ['user' => ['login' => 'jsmith', 'firstname' => '', 'lastname' => 'X', 'mail' => 'jsmith@example.net', 'password' => 'abc', 'password_confirmation' => 'abd']]);
        $this->assertStatus(200);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('errorExplanation', $html);
        self::assertStringContainsString('Login has already been taken', $html);
        self::assertStringContainsString('First name cannot be blank', $html);
        self::assertStringContainsString('Email has already been taken', $html);
        self::assertStringContainsString("Password doesn&#039;t match confirmation", $html);
        self::assertStringContainsString('Password is too short', $html);
    }

    public function testCreateWithGeneratedPasswordAndContinue(): void
    {
        $this->loginAs('admin');
        $this->post('/users', ['continue' => '1', 'user' => ['login' => 'gen', 'firstname' => 'Gen', 'lastname' => 'Erated', 'mail' => 'gen@example.net', 'generate_password' => '1']]);
        $r = $this->client->getResponse();
        self::assertTrue($r->isRedirect());
        self::assertStringContainsString('/users/new', (string) $r->headers->get('Location'));
        $this->em->clear();
        $u = $this->em->getRepository(User::class)->findOneBy(['login' => 'gen']);
        self::assertNotNull($u);
        self::assertNotSame('', $u->hashedPassword);
    }

    public function testUpdateUserAndGroups(): void
    {
        $this->loginAs('admin');
        $u = $this->user('anna');
        $this->get('/users/'.$u->id.'/edit');
        $this->post('/users/'.$u->id, ['user' => ['firstname' => 'Annie', 'mail' => 'annie@example.net', 'admin' => '1', 'mail_notification' => 'all']], 'PUT');
        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->post('/users/'.$u->id, ['user' => ['group_ids' => ['10', '']]], 'PUT');
        $this->em->clear();
        $u = $this->em->find(User::class, $u->id);
        self::assertSame('Annie', $u->firstname);
        self::assertSame('annie@example.net', $u->getMail());
        self::assertTrue($u->admin);
        self::assertSame([10], $u->getGroupIds());
        // inherited membership from group Developers (eCommerce)
        $members = $this->em->getRepository(Member::class)->findBy(['principal' => $u]);
        self::assertNotEmpty($members);
        // remove from groups
        $this->post('/users/'.$u->id, ['user' => ['group_ids' => ['']]], 'PUT');
        $this->em->clear();
        self::assertSame([], $this->em->find(User::class, $u->id)->getGroupIds());
    }

    public function testLockAndUnlock(): void
    {
        $this->loginAs('admin');
        $u = $this->user('rhill');
        $this->post('/users/'.$u->id.'?user%5Bstatus%5D=3', [], 'PUT');
        $this->em->clear();
        self::assertSame(Principal::STATUS_LOCKED, $this->em->find(User::class, $u->id)->status);
        $this->post('/users/'.$u->id.'?user%5Bstatus%5D=1', [], 'PUT');
        $this->em->clear();
        self::assertSame(Principal::STATUS_ACTIVE, $this->em->find(User::class, $u->id)->status);
    }

    public function testDestroyRequiresConfirmationAndAnonymizes(): void
    {
        $this->loginAs('admin');
        $u = $this->user('rhill');
        $id = $u->id;
        $authored = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM issues WHERE author_id = ?', [$id]);
        self::assertGreaterThan(0, $authored);
        $this->post('/users/'.$id, [], 'DELETE');
        $this->assertStatus(200);
        self::assertStringContainsString('name="confirm"', (string) $this->client->getResponse()->getContent());
        $this->post('/users/'.$id, ['confirm' => 'rhill'], 'DELETE');
        $this->assertRedirectTo('/users');
        $this->em->clear();
        self::assertNull($this->em->find(Principal::class, $id));
        $anon = $this->em->getRepository(AnonymousUser::class)->findOneBy([]);
        $c = $this->em->getConnection();
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM issues WHERE author_id = ?', [$id]));
        self::assertGreaterThanOrEqual($authored, (int) $c->fetchOne('SELECT COUNT(*) FROM issues WHERE author_id = ?', [$anon->id]));
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM members WHERE user_id = ?', [$id]));
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM email_addresses WHERE user_id = ?', [$id]));
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM journals WHERE user_id = ?', [$id]));
    }

    public function testLockFromDestroyPage(): void
    {
        $this->loginAs('admin');
        $u = $this->user('anna');
        $this->post('/users/bulk_lock?ids%5B%5D='.$u->id, ['lock' => '']);
        $this->assertRedirectTo('/users');
        $this->em->clear();
        self::assertSame(Principal::STATUS_LOCKED, $this->em->find(User::class, $u->id)->status);
    }

    public function testMemberships(): void
    {
        $this->loginAs('admin');
        $u = $this->user('anna');
        $project = $this->em->getRepository(\App\Entity\Project::class)->findOneBy(['identifier' => 'internal']) ?? $this->em->getRepository(\App\Entity\Project::class)->findAll()[1];
        $this->post('/users/'.$u->id.'/memberships', ['membership' => ['project_ids' => [$project->id], 'role_ids' => [4]]]);
        $this->assertRedirectTo('/users/'.$u->id.'/edit?tab=memberships');
        $this->em->clear();
        $m = $this->em->getRepository(Member::class)->findOneBy(['principal' => $u->id, 'project' => $project->id]);
        self::assertNotNull($m);
        self::assertSame([4], array_map(static fn ($r) => $r->id, $m->getRoles()));
        $this->get('/users/'.$u->id.'/memberships/'.$m->id.'/edit');
        $this->assertStatus(200);
        $this->post('/users/'.$u->id.'/memberships/'.$m->id, ['membership' => ['role_ids' => ['3', '5', '']]], 'PUT');
        $this->em->clear();
        $m = $this->em->find(Member::class, $m->id);
        self::assertSame([3, 5], array_map(static fn ($r) => $r->id, $m->getRoles()));
        $this->post('/users/'.$u->id.'/memberships/'.$m->id, [], 'DELETE');
        $this->em->clear();
        self::assertNull($this->em->find(Member::class, $m->id));
    }

    public function testEmailAddresses(): void
    {
        $this->loginAs('admin');
        $this->em->getConnection()->executeStatement("INSERT INTO settings (name, value, updated_on) VALUES ('max_additional_emails', '5', CURRENT_TIMESTAMP)");
        $u = $this->user('jsmith');
        $this->post('/users/'.$u->id.'/email_addresses', ['email_address' => ['address' => 'john.alt@example.net']]);
        $this->assertRedirectTo('/users/'.$u->id.'/email_addresses');
        $this->em->clear();
        $ea = $this->em->getRepository(EmailAddress::class)->findOneBy(['address' => 'john.alt@example.net']);
        self::assertNotNull($ea);
        self::assertTrue($ea->notify);
        // duplicate is rejected
        $this->post('/users/'.$u->id.'/email_addresses', ['email_address' => ['address' => 'JOHN.alt@example.net']]);
        $this->assertStatus(422);
        $this->post('/users/'.$u->id.'/email_addresses/'.$ea->id.'?notify=0', [], 'PUT');
        $this->em->clear();
        self::assertFalse($this->em->find(EmailAddress::class, $ea->id)->notify);
        $this->post('/users/'.$u->id.'/email_addresses/'.$ea->id, [], 'DELETE');
        $this->em->clear();
        self::assertNull($this->em->find(EmailAddress::class, $ea->id));
    }

    public function testShowProfile(): void
    {
        $this->loginAs('admin');
        $u = $this->user('jsmith');
        $crawler = $this->get('/users/'.$u->id);
        $this->assertStatus(200);
        self::assertStringContainsString('John Smith', $crawler->filter('h2')->text());
        self::assertGreaterThan(0, $crawler->filter('table.list.projects tbody tr')->count());
        self::assertSame(1, $crawler->filter('table.issue-report')->count());
    }
}

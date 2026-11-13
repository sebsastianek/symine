<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\Group;
use App\Entity\Member;
use App\Entity\Principal;
use App\Entity\User;

final class GroupsTest extends AdminWebTestCase
{
    use FormLoginTrait;

    public function testPagesAsAdmin(): void
    {
        $this->loginAs('admin');
        foreach (['/groups', '/groups?name=dev', '/groups/new', '/groups/10', '/groups/10/edit', '/groups/10/edit?tab=users',
            '/groups/10/edit?tab=memberships', '/groups/10/users/new', '/groups/10/autocomplete_for_user?q=jo', '/groups/2/edit', '/groups/3/edit',
            '/groups/10/memberships/new'] as $url) {
            $this->get($url);
            $this->assertStatus(200, $url);
        }
        $crawler = $this->get('/groups');
        self::assertSame(3, $crawler->filter('table.groups tbody tr')->count());
        self::assertSame(2, $crawler->filter('table.groups tr.builtin')->count());
        // builtin group: no users tab
        $crawler = $this->get('/groups/2/edit');
        self::assertSame(0, $crawler->filter('#tab-users')->count());
        self::assertSame(1, $crawler->filter('#tab-memberships')->count());
    }

    public function testForbiddenToNonAdmin(): void
    {
        $this->loginAs('jsmith');
        foreach (['/groups', '/groups/new', '/groups/10/edit', '/groups/10/users/new'] as $url) {
            $this->get($url);
            $this->assertStatus(403, $url);
        }
        $this->get('/groups/10');
        $this->assertStatus(200);
    }

    public function testCrudAndUsers(): void
    {
        $this->loginAs('admin');
        $this->post('/groups', ['group' => ['name' => 'QA team']]);
        $this->assertRedirectTo('/groups');
        $g = $this->em->getRepository(Group::class)->findOneBy(['lastname' => 'QA team']);
        self::assertNotNull($g);
        // duplicate name
        $this->post('/groups', ['group' => ['name' => 'qa TEAM']]);
        $this->assertStatus(200);
        self::assertStringContainsString('has already been taken', (string) $this->client->getResponse()->getContent());

        $this->post('/groups/'.$g->id, ['group' => ['name' => 'QA']], 'PUT');
        $this->em->clear();
        self::assertSame('QA', $this->em->find(Group::class, $g->id)->lastname);

        // group membership then add user: user inherits roles
        $this->post('/groups/'.$g->id.'/memberships', ['membership' => ['project_ids' => [1], 'role_ids' => [5]]]);
        $this->assertRedirectTo('/groups/'.$g->id.'/edit?tab=memberships');
        $anna = $this->user('anna');
        $this->post('/groups/'.$g->id.'/users', ['user_ids' => [$anna->id]]);
        $this->em->clear();
        $g = $this->em->find(Group::class, $g->id);
        self::assertCount(1, $g->users);
        $m = $this->em->getRepository(Member::class)->findOneBy(['principal' => $anna->id, 'project' => 1]);
        self::assertNotNull($m);
        self::assertContains(5, array_map(static fn ($r) => $r->id, $m->getRoles()));

        // removing a user requires confirmation
        $this->post('/groups/'.$g->id.'/users/'.$anna->id, [], 'DELETE');
        $this->assertStatus(200);
        self::assertStringContainsString('name="confirm"', (string) $this->client->getResponse()->getContent());
        $this->post('/groups/'.$g->id.'/users?user_ids%5B%5D='.$anna->id, ['confirm' => 'Yes'], 'DELETE');
        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->em->clear();
        self::assertCount(0, $this->em->find(Group::class, $g->id)->users);
        self::assertNull($this->em->getRepository(Member::class)->findOneBy(['principal' => $anna->id, 'project' => 1]));

        $this->post('/groups/'.$g->id, [], 'DELETE');
        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->em->clear();
        self::assertNull($this->em->find(Principal::class, $g->id));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM members WHERE user_id = ?', [$g->id]));
    }

    public function testBuiltinGroupCannotBeDeleted(): void
    {
        $this->loginAs('admin');
        $this->post('/groups/2', [], 'DELETE');
        $this->assertStatus(403);
        $this->em->clear();
        self::assertNotNull($this->em->find(Principal::class, 2));
    }

    public function testDestroyGroupRemovesInheritedUserRoles(): void
    {
        $this->loginAs('admin');
        $dl = $this->user('dlopper'); // member of Developers (id 10), inherits Developer on eCommerce
        $before = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM member_roles mr JOIN members m ON m.id = mr.member_id WHERE m.user_id = ? AND mr.inherited_from IS NOT NULL', [$dl->id]);
        self::assertGreaterThan(0, $before);
        $this->post('/groups/10', [], 'DELETE');
        $after = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM member_roles mr JOIN members m ON m.id = mr.member_id WHERE m.user_id = ? AND mr.inherited_from IS NOT NULL', [$dl->id]);
        self::assertSame(0, $after);
    }
}

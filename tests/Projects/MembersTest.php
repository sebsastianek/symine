<?php

declare(strict_types=1);

namespace App\Tests\Projects;

use App\Entity\Member;
use App\Entity\Project;

final class MembersTest extends ProjectsWebTestCase
{
    private function memberOf(string $project, string $login): ?Member
    {
        $this->em->clear();
        $p = $this->em->getRepository(Project::class)->findOneBy(['identifier' => $project]);

        return $this->em->createQuery('SELECT m FROM App\Entity\Member m JOIN m.principal u WHERE m.project = :p AND u.login = :l')
            ->setParameters(['p' => $p, 'l' => $login])->getOneOrNullResult();
    }

    public function testSettingsMembersTab(): void
    {
        $this->login('jsmith');
        $html = $this->get('/projects/ecommerce/settings/members');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('<table class="list members">', $html);
        self::assertStringContainsString('id="member-1"', $html);
        self::assertStringContainsString('href="/memberships/1/edit"', $html);
    }

    public function testNewModalAndAutocomplete(): void
    {
        $this->login('admin');
        $this->client->xmlHttpRequest('GET', '/projects/ecommerce/memberships/new', [], [], ['HTTP_ACCEPT' => 'text/javascript, application/javascript']);
        $js = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("showModal('ajax-modal'", $js);
        self::assertStringContainsString('Anna', $js);
        self::assertStringNotContainsString('John Smith', $js, 'existing members are not proposed');
        $js = $this->get('/projects/ecommerce/memberships/autocomplete.js?q=ann');
        self::assertStringContainsString('principals_for_new_member', $js);
        self::assertStringContainsString('membership[user_ids][]', $js);
    }

    public function testCreateUpdateDestroy(): void
    {
        $this->login('admin');
        $anna = $this->em->getConnection()->fetchOne("SELECT id FROM users WHERE login = 'anna'");
        $code = $this->submit('/projects/internal/memberships', ['membership' => ['user_ids' => [(string) $anna], 'role_ids' => ['4', '5']]]);
        self::assertSame(302, $code);
        $m = $this->memberOf('internal', 'anna');
        self::assertNotNull($m);
        self::assertCount(2, $m->getRoles());
        $this->submit('/memberships/'.$m->id, ['_method' => 'PUT', 'membership' => ['role_ids' => ['5', '']]]);
        $m = $this->memberOf('internal', 'anna');
        self::assertSame([5], array_map(static fn ($r) => $r->id, $m->getRoles()));
        $this->submit('/memberships/'.$m->id, ['_method' => 'DELETE']);
        self::assertNull($this->memberOf('internal', 'anna'));
    }

    public function testCreateWithXhrReturnsJs(): void
    {
        $this->login('admin');
        $anna = $this->em->getConnection()->fetchOne("SELECT id FROM users WHERE login = 'anna'");
        $token = $this->csrf();
        $this->client->xmlHttpRequest('POST', '/projects/internal/memberships', ['_token' => $token, 'membership' => ['user_ids' => [(string) $anna], 'role_ids' => ['5']]], [], ['HTTP_ACCEPT' => 'text/javascript']);
        $js = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("#tab-content-members", $js);
        self::assertStringContainsString('hideModal();', $js);
    }

    public function testInheritedMembersOnSubproject(): void
    {
        $this->login('admin');
        $this->submit('/projects/checkout', ['_method' => 'PATCH', 'project' => ['inherit_members' => '1']]);
        $m = $this->memberOf('checkout', 'dlopper');
        self::assertNotNull($m, 'members inherited from parent');
        self::assertNotNull($m->memberRoles->first()->inheritedFrom);
        // inherited membership cannot be removed
        $this->submit('/memberships/'.$m->id, ['_method' => 'DELETE']);
        self::assertNotNull($this->memberOf('checkout', 'dlopper'));
        $html = $this->get('/memberships/'.$m->id.'/edit');
        self::assertStringContainsString('Inherited from parent project', $html);
    }

    public function testApi(): void
    {
        $data = $this->api('GET', '/projects/ecommerce/memberships.json');
        self::assertSame(200, $this->code());
        self::assertSame(4, $data['total_count']);
        $first = $data['memberships'][0];
        self::assertSame(['id' => 1, 'name' => 'eCommerce'], $first['project']);
        self::assertSame(['id' => 5, 'name' => 'John Smith'], $first['user']);
        $groupMember = array_values(array_filter($data['memberships'], static fn ($m) => isset($m['group'])));
        self::assertSame('Developers', $groupMember[0]['group']['name']);

        $key = $this->adminKey();
        $anna = (int) $this->em->getConnection()->fetchOne("SELECT id FROM users WHERE login = 'anna'");
        $created = $this->api('POST', '/projects/internal/memberships.json', ['membership' => ['user_id' => $anna, 'role_ids' => [5]]], $key);
        self::assertSame(201, $this->code(), json_encode($created));
        $id = $created['membership']['id'];
        self::assertSame([['id' => 5, 'name' => 'Reporter']], $created['membership']['roles']);
        $show = $this->api('GET', "/memberships/$id.json", null, $key);
        self::assertSame($anna, $show['membership']['user']['id']);
        $this->api('PUT', "/memberships/$id.json", ['membership' => ['role_ids' => [4]]], $key);
        self::assertSame(204, $this->code());
        $this->api('DELETE', "/memberships/$id.json", null, $key);
        self::assertSame(204, $this->code());
        $this->api('GET', "/memberships/$id.json", null, $key);
        self::assertSame(404, $this->code());
        $err = $this->api('POST', '/projects/internal/memberships.json', ['membership' => ['user_id' => $anna]], $key);
        self::assertSame(422, $this->code());
        self::assertNotEmpty($err['errors']);
    }
}

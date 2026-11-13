<?php

declare(strict_types=1);

namespace App\Tests\Gaps;

use App\Entity\Principal;
use App\Entity\User;
use App\Entity\UserQuery;
use App\Tests\Admin\AdminWebTestCase;
use App\Tests\Admin\FormLoginTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Redmine 7 admin user list: UserQuery based /users (filters, columns, sort, CSV, saved queries),
 * users context menu, bulk destroy / lock / unlock and REST API backwards compatibility.
 */
final class UsersListTest extends AdminWebTestCase
{
    use FormLoginTrait;

    /** @return list<string> logins of the listed users */
    private function logins(Crawler $crawler): array
    {
        return $crawler->filter('table.users tbody tr.user td.login')->each(static fn (Crawler $td) => trim($td->text()));
    }

    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testDefaultListIsActiveUsersWithRedmineMarkup(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/users');
        $this->assertStatus(200);
        self::assertSame(['admin', 'anna', 'dlopper', 'jsmith', 'rhill'], $this->logins($crawler));
        $headers = $crawler->filter('table.users thead th')->each(static fn (Crawler $th) => $th->attr('class'));
        self::assertSame(['checkbox hide-when-print', 'login', 'firstname', 'lastname', 'mail', 'admin', 'created_on', 'last_login_on', null], $headers);
        self::assertSame('sort asc icon icon-sorted-desc', $crawler->filter('th.login a')->attr('class'));
        self::assertSame('/users?sort=login%3Adesc', $crawler->filter('th.login a')->attr('href'));
        self::assertSame('/users?sort=firstname%2Clogin', $crawler->filter('th.firstname a')->attr('href'));
        // context menu form, checkboxes and row markup
        $form = $crawler->filter('form[data-cm-url]');
        self::assertSame('/users/context_menu', $form->attr('data-cm-url'));
        $row = $crawler->filter('tr#user-'.$this->user('jsmith')->id);
        self::assertSame('hascontextmenu even user active', $row->attr('class'));
        self::assertSame((string) $this->user('jsmith')->id, $row->filter('td.checkbox input[name="ids[]"]')->attr('value'));
        self::assertSame('/users/'.$this->user('jsmith')->id.'/edit', $row->filter('td.login a')->attr('href'));
        self::assertSame('jsmith@example.net', $row->filter('td.mail')->text());
        self::assertSame('No', $row->filter('td.admin')->text());
        self::assertCount(1, $row->filter('td.buttons a.js-contextmenu'));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('addFilter("status", "=", ["1"]);', $html);
        self::assertStringContainsString('"is_member_of_group":{"type":"list_optional","name":"Member of group","remote":true}', $html);
        self::assertStringContainsString('var filtersUrl = "\/queries\/filter?type=UserQuery";', $html);
        self::assertStringContainsString('/assets/context_menu.js', $html);
        self::assertCount(1, $crawler->filter('#sidebar #admin-menu'));
        self::assertCount(1, $crawler->filter('#csv-export-options form#csv-export-form[action="/users.csv"]'));
        self::assertCount(1, $crawler->filter('p.other-formats a.csv'));
        // available columns of the options fieldset
        $available = $crawler->filter('#available_c option')->each(static fn (Crawler $o) => $o->attr('value'));
        self::assertSame(['updated_on', 'passwd_changed_on', 'status', 'auth_source.name', 'twofa_scheme', 'cf_6'], $available);
    }

    public function testFilters(): void
    {
        $this->loginAs('admin');
        $cases = [
            '/users?set_filter=1&f[]=status&op[status]==&v[status][]=3' => ['locked'],
            '/users?set_filter=1&f[]=status&op[status]=*' => ['admin', 'anna', 'dlopper', 'jsmith', 'locked', 'rhill'],
            '/users?set_filter=1&f[]=status&op[status]=!&v[status][]=1' => ['locked'],
            '/users?set_filter=1&f[]=name&op[name]=~&v[name][]=smi' => ['jsmith'],
            '/users?set_filter=1&f[]=name&op[name]=~&v[name][]=dlopper@example' => ['dlopper'],
            '/users?set_filter=1&f[]=status&op[status]=*&f[]=name&op[name]=!~&v[name][]=example' => ['admin'],
            '/users?set_filter=1&f[]=is_member_of_group&op[is_member_of_group]==&v[is_member_of_group][]=10' => ['dlopper'],
            '/users?set_filter=1&f[]=is_member_of_group&op[is_member_of_group]=!*' => ['admin', 'anna', 'jsmith', 'locked', 'rhill'],
            '/users?set_filter=1&f[]=admin&op[admin]==&v[admin][]=1' => ['admin'],
            '/users?set_filter=1&f[]=admin&op[admin]=!&v[admin][]=1' => ['anna', 'dlopper', 'jsmith', 'locked', 'rhill'],
            '/users?set_filter=1&f[]=login&op[login]=^&v[login][]=j' => ['jsmith'],
            '/users?set_filter=1&f[]=lastname&op[lastname]==&v[lastname][]=Hill' => ['rhill'],
            '/users?set_filter=1&f[]=mail&op[mail]=$&v[mail][]=dummy.invalid' => ['admin'],
            '/users?set_filter=1&f[]=created_on&op[created_on]=*' => ['admin', 'anna', 'dlopper', 'jsmith', 'locked', 'rhill'],
            '/users?set_filter=1&f[]=last_login_on&op[last_login_on]=!*&f[]=status&op[status]=*' => ['anna', 'dlopper', 'jsmith', 'locked', 'rhill'], // admin just signed in
            '/users?set_filter=1&f[]=last_login_on&op[last_login_on]=t' => ['admin'],
            '/users?set_filter=1&f[]=status&op[status]==&v[status][]=1&f[]=twofa_scheme&op[twofa_scheme]=!*' => ['admin', 'anna', 'dlopper', 'jsmith', 'rhill'],
            '/users?set_filter=1&f[]=auth_source_id&op[auth_source_id]=*' => [],
            // short filters (status=3) and legacy blank status (all users)
            '/users?status=3' => ['locked'],
            '/users?status=' => ['admin', 'anna', 'dlopper', 'jsmith', 'locked', 'rhill'],
            '/users?name=jsmith' => ['jsmith'],
        ];
        foreach ($cases as $url => $expected) {
            $crawler = $this->get($url);
            $this->assertStatus(200, $url);
            self::assertSame($expected, $this->logins($crawler), $url);
        }
        // invalid filter: error message, no list
        $crawler = $this->get('/users?set_filter=1&f[]=login&op[login]==&v[login][]=');
        $this->assertStatus(200);
        self::assertCount(1, $crawler->filter('#errorExplanation'));
        self::assertCount(0, $crawler->filter('table.users'));
    }

    public function testSortColumnsAndPagination(): void
    {
        $this->loginAs('admin');
        self::assertSame(['rhill', 'jsmith', 'dlopper', 'anna', 'admin'], $this->logins($this->get('/users?sort=login:desc')));
        self::assertSame(['admin', 'anna', 'dlopper', 'jsmith', 'rhill'], $this->logins($this->get('/users?sort=mail')));
        self::assertSame(['admin', 'rhill', 'dlopper', 'anna', 'jsmith'], $this->logins($this->get('/users?sort=lastname')));
        $crawler = $this->get('/users?set_filter=1&f[]=status&op[status]=*&c[]=login&c[]=status&c[]=auth_source.name&c[]=updated_on&c[]=cf_6&sort=status:desc,login');
        $this->assertStatus(200);
        self::assertSame(['login', 'status', 'auth_source-name', 'updated_on', 'cf_6 string'], $crawler->filter('table.users thead th')->slice(1, 5)->each(static fn (Crawler $th) => (string) $th->attr('class')));
        self::assertSame('locked', $this->logins($crawler)[0]);
        self::assertSame('locked', $crawler->filter('tr.user.locked td.status')->text());
        self::assertSame('active', $crawler->filter('tr#user-1 td.status')->text());
        // sorting by the auth source / custom field columns works
        foreach (['auth_source.name', 'cf_6:desc', 'twofa_scheme', 'passwd_changed_on', 'admin:desc'] as $sort) {
            $this->get('/users?set_filter=1&c[]=login&c[]=cf_6&sort='.$sort);
            $this->assertStatus(200, $sort);
        }
        $this->em->getConnection()->executeStatement("DELETE FROM settings WHERE name = 'per_page_options'");
        $this->em->getConnection()->executeStatement("INSERT INTO settings (name, value, updated_on) VALUES ('per_page_options', '2,25', '2026-01-01 00:00:00')");
        self::getContainer()->get(\App\Service\Settings::class)->clearCache();
        $crawler = $this->get('/users?set_filter=1&per_page=2&page=2');
        self::assertSame(['dlopper', 'jsmith'], $this->logins($crawler));
        self::assertStringContainsString('(3-4/5)', $crawler->filter('span.pagination')->text());
    }

    public function testCsvExport(): void
    {
        $this->loginAs('admin');
        $this->get('/users.csv');
        $this->assertStatus(200);
        $r = $this->client->getResponse();
        self::assertStringStartsWith('text/csv', (string) $r->headers->get('Content-Type'));
        self::assertStringContainsString('filename="users.csv"', (string) $r->headers->get('Content-Disposition'));
        $lines = explode("\n", trim((string) $r->getContent()));
        self::assertSame('Login,First name,Last name,Email,Administrator,Created,Last sign in', $lines[0]);
        self::assertCount(6, $lines);
        self::assertStringStartsWith('admin,Redmine,Admin,admin@dummy.invalid,Yes,', $lines[1]);

        $this->get('/users.csv?encoding=UTF-8&set_filter=1&f[]=status&op[status]==&v[status][]=3&c[]=login&c[]=status&c[]=twofa_scheme&c[]=auth_source.name&field_separator=;&query_name=Locked users');
        $r = $this->client->getResponse();
        self::assertStringContainsString('filename="locked_users.csv"', (string) $r->headers->get('Content-Disposition'));
        $lines = explode("\n", trim(substr((string) $r->getContent(), 3)));
        self::assertSame(['Login;Status;Two-factor authentication scheme;Authentication mode', 'locked;locked;disabled;""'], $lines);

        // all columns
        $this->get('/users.csv?set_filter=1&f[]=login&op[login]==&v[login][]=jsmith&c[]=all_inline');
        $lines = explode("\n", trim((string) $this->client->getResponse()->getContent()));
        self::assertSame('Login,First name,Last name,Email,Administrator,Created,Updated,Last sign in,Password last changed,Status,Authentication mode,Two-factor authentication scheme,Phone', $lines[0]);
        self::assertStringStartsWith('jsmith,John,Smith,jsmith@example.net,No,', $lines[1]);
        self::assertStringEndsWith(',active,"",disabled,+1 555 0100', $lines[1]);
        // blank custom value => ""
        $this->get('/users.csv?set_filter=1&f[]=login&op[login]==&v[login][]=admin&c[]=login&c[]=cf_6');
        self::assertSame(['Login,Phone', 'admin,""'], explode("\n", trim((string) $this->client->getResponse()->getContent())));

        // invalid query
        $this->get('/users.csv?set_filter=1&f[]=login&op[login]==&v[login][]=');
        $this->assertStatus(422);
    }

    public function testSavedUserQuery(): void
    {
        $this->loginAs('admin');
        $this->post('/queries', [
            'type' => 'UserQuery', 'query' => ['name' => 'Locked people', 'visibility' => '0'],
            'f' => ['status'], 'op' => ['status' => '='], 'v' => ['status' => ['3']], 'c' => ['login', 'status'],
        ]);
        $record = $this->em->getRepository(UserQuery::class)->findOneBy(['name' => 'Locked people']);
        self::assertNotNull($record);
        $this->assertRedirectTo('/users?query_id='.$record->id);
        $crawler = $this->get('/users?query_id='.$record->id);
        $this->assertStatus(200);
        self::assertSame('Locked people', $crawler->filter('#content h2')->text());
        self::assertSame(['locked'], $this->logins($crawler));
        self::assertSame(['checkbox hide-when-print', 'login', 'status', null], $crawler->filter('table.users thead th')->each(static fn (Crawler $th) => $th->attr('class')));
        // sidebar of saved queries (before the administration menu)
        self::assertSame('/users?query_id='.$record->id, $crawler->filter('#sidebar ul.queries a.query.selected')->attr('href'));
        self::assertStringContainsString('My custom queries', $crawler->filter('#sidebar h3')->first()->text());
        // session keeps the saved query
        self::assertSame(['locked'], $this->logins($this->get('/users')));
        self::assertSame(['admin', 'anna', 'dlopper', 'jsmith', 'rhill'], $this->logins($this->get('/users?set_filter=1&sort=')));
    }

    public function testContextMenu(): void
    {
        $this->loginAs('admin');
        $js = $this->user('jsmith')->id;
        $locked = $this->user('locked')->id;
        $crawler = $this->get('/users/context_menu?ids[]='.$js.'&back_url=/users');
        $this->assertStatus(200);
        self::assertSame('/users/'.$js.'?back_url=/users&user%5Bstatus%5D=3', $crawler->filter('a.icon-lock')->attr('href'));
        self::assertSame('put', $crawler->filter('a.icon-lock')->attr('data-method'));
        self::assertSame('/users/'.$js.'/edit?back_url=/users', $crawler->filter('a.icon-edit')->attr('href'));
        self::assertSame('delete', $crawler->filter('a.icon-del')->attr('data-method'));
        self::assertSame(['Add to group'], $crawler->filter('li.folder > a.submenu')->each(static fn (Crawler $a) => $a->text()));
        self::assertSame('/groups/10/users?back_url=/users&user_ids%5B0%5D='.$js, $crawler->filter('li.folder ul a')->attr('href'));

        // locked user: unlock
        $crawler = $this->client->request('POST', '/users/context_menu', ['ids' => [$locked], '_token' => $this->csrfToken()]);
        $this->assertStatus(200);
        self::assertCount(1, $crawler->filter('a.icon-unlock'));
        self::assertCount(0, $crawler->filter('a.icon-lock'));

        // current user: no lock, no delete
        $crawler = $this->get('/users/context_menu?ids[]=1');
        self::assertCount(0, $crawler->filter('a.icon-lock'));
        self::assertCount(0, $crawler->filter('a.icon-del'));
        self::assertCount(1, $crawler->filter('a.icon-edit'));

        // several users: bulk lock + bulk delete, remove from group for members
        $dl = $this->user('dlopper')->id;
        $crawler = $this->get('/users/context_menu?ids[]='.$js.'&ids[]='.$dl);
        self::assertSame('/users/bulk_lock?ids%5B0%5D='.$js.'&ids%5B1%5D='.$dl, $crawler->filter('a.icon-lock')->attr('href'));
        self::assertSame('post', $crawler->filter('a.icon-lock')->attr('data-method'));
        self::assertSame('/users/bulk_destroy?ids%5B0%5D='.$js.'&ids%5B1%5D='.$dl, $crawler->filter('a.icon-del')->attr('href'));
        self::assertSame('delete', $crawler->filter('a.icon-del')->attr('data-method'));
        self::assertSame(['Add to group', 'Remove from group'], $crawler->filter('li.folder > a.submenu')->each(static fn (Crawler $a) => $a->text()));
        self::assertCount(0, $crawler->filter('a.icon-edit'));
        // all locked: bulk unlock
        $crawler = $this->get('/users/context_menu?ids[]='.$locked);
        self::assertCount(1, $crawler->filter('a.icon-unlock'));

        $this->get('/users/context_menu?ids[]=999999');
        $this->assertStatus(404);
        $this->get('/users/context_menu');
        $this->assertStatus(404);
    }

    public function testContextMenuAddToGroupLink(): void
    {
        $this->loginAs('admin');
        $js = $this->user('jsmith')->id;
        // rails-ujs posts the link with its parameters in the query string
        $this->client->request('POST', '/groups/10/users?back_url=/users&user_ids[]='.$js, ['_token' => $this->csrfToken()]);
        $this->assertRedirectTo('/users');
        $this->em->clear();
        self::assertContains(10, $this->user('jsmith')->getGroupIds());
    }

    public function testNonAdminIsDenied(): void
    {
        $this->loginAs('jsmith');
        foreach (['/users', '/users.csv', '/users/context_menu?ids[]=6'] as $url) {
            $this->get($url);
            $this->assertStatus(403, $url);
        }
        $this->post('/users/bulk_destroy?ids[]=6', [], 'DELETE');
        $this->assertStatus(403);
    }

    public function testBulkDestroy(): void
    {
        $this->loginAs('admin');
        $js = $this->user('jsmith')->id;
        $rh = $this->user('rhill')->id;
        // confirmation page
        $this->post('/users/bulk_destroy?ids[]='.$js.'&ids[]='.$rh.'&ids[]=1', [], 'DELETE');
        $this->assertStatus(200);
        $crawler = new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost');
        self::assertSame('Confirmation', $crawler->filter('#content h2')->text());
        self::assertSame(['John Smith', 'Robert Hill'], $crawler->filter('div.warning p strong')->each(static fn (Crawler $s) => $s->text()));
        self::assertStringContainsString('To confirm, please enter "Yes" below.', $crawler->filter('div.warning')->text());
        self::assertCount(1, $crawler->filter('input[name="confirm"]'));
        self::assertCount(1, $crawler->filter('form.button_to[action="/users/bulk_lock?ids%5B0%5D='.$js.'&ids%5B1%5D='.$rh.'"] input[name="lock"]'));
        // wrong confirmation: nothing deleted
        $this->post('/users/bulk_destroy?ids[]='.$js.'&ids[]='.$rh, ['confirm' => 'no'], 'DELETE');
        $this->assertStatus(200);
        $this->em->clear();
        self::assertNotNull($this->em->find(Principal::class, $js));
        // confirmed
        $this->post('/users/bulk_destroy?ids[]='.$js.'&ids[]='.$rh, ['confirm' => 'Yes'], 'DELETE');
        $this->assertRedirectTo('/users');
        $this->em->clear();
        self::assertNull($this->em->find(Principal::class, $js));
        self::assertNull($this->em->find(Principal::class, $rh));
        self::assertNotNull($this->em->find(Principal::class, 1));
        // only the current user (or unknown ids): 404
        $this->post('/users/bulk_destroy?ids[]=1', ['confirm' => 'Yes'], 'DELETE');
        $this->assertStatus(404);
        // DELETE only (Redmine route)
        $this->client->request('GET', '/users/bulk_destroy?ids[]=6');
        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testBulkLockAndUnlock(): void
    {
        $this->loginAs('admin');
        $js = $this->user('jsmith')->id;
        $dl = $this->user('dlopper')->id;
        $this->post('/users/bulk_lock?ids[]='.$js.'&ids[]='.$dl.'&ids[]=1');
        $this->assertRedirectTo('/users');
        $this->em->clear();
        self::assertSame(Principal::STATUS_LOCKED, $this->user('jsmith')->status);
        self::assertSame(Principal::STATUS_LOCKED, $this->user('dlopper')->status);
        self::assertSame(Principal::STATUS_ACTIVE, $this->em->find(User::class, 1)->status);
        $this->post('/users/bulk_unlock', ['ids' => [$js, $dl]]);
        $this->assertRedirectTo('/users');
        $this->em->clear();
        self::assertSame(Principal::STATUS_ACTIVE, $this->user('jsmith')->status);
        $this->post('/users/bulk_lock', ['ids' => [1]]);
        $this->assertStatus(404);
    }

    public function testApiCompatibility(): void
    {
        $this->api('GET', '/users.json');
        $this->assertStatus(200);
        self::assertSame(5, $this->json()['total_count']);
        self::assertSame(['admin', 'anna', 'dlopper', 'jsmith', 'rhill'], array_column($this->json()['users'], 'login'));
        $this->api('GET', '/users.json?status=3');
        self::assertSame(['locked'], array_column($this->json()['users'], 'login'));
        $this->api('GET', '/users.json?status=');
        self::assertSame(6, $this->json()['total_count']);
        // legacy name: "contains" on login, names and email
        $this->api('GET', '/users.json?name=smi');
        self::assertSame(['jsmith'], array_column($this->json()['users'], 'login'));
        $this->api('GET', '/users.json?name=example.net&status=*');
        self::assertSame(['anna', 'dlopper', 'jsmith', 'locked', 'rhill'], array_column($this->json()['users'], 'login'));
        $this->api('GET', '/users.json?group_id=10');
        self::assertSame(['dlopper'], array_column($this->json()['users'], 'login'));
        // query filters and sort
        $this->api('GET', '/users.json?f[]=admin&op[admin]==&v[admin][]=0&sort=login:desc&limit=2');
        self::assertSame(5, $this->json()['total_count']);
        self::assertSame(['rhill', 'locked'], array_column($this->json()['users'], 'login'));
        self::assertSame(2, $this->json()['limit']);
        // invalid query: 422 with errors
        $this->api('GET', '/users.json?f[]=login&op[login]==&v[login][]=');
        $this->assertStatus(422);
        self::assertSame(['Login cannot be blank'], $this->json()['errors']);
        $this->api('GET', '/users.xml?status=3');
        self::assertStringContainsString('<users type="array" total_count="1"', (string) $this->client->getResponse()->getContent());
        $this->api('GET', '/users.json', null, 'jsmith', 'password123');
        $this->assertStatus(403);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Plugin;

use App\Menu\MenuBuilder;
use App\Menu\MenuRegistry;
use App\Security\Authorization;
use App\Security\CurrentUser;

/** Plugin menu items: project tab, permission checks, positions, delete/replace of core items (Redmine::MenuManager) */
final class MenuTest extends PluginWebTestCase
{
    /** @return list<string> */
    private static function names(array $items): array
    {
        return array_map(static fn (array $i) => $i['name'], $items);
    }

    private function actAs(string $login): void
    {
        self::getContainer()->get(CurrentUser::class)->set($this->user($login));
        self::getContainer()->get(Authorization::class)->reset();
    }

    public function testProjectTabRequiresModuleAndPermission(): void
    {
        $menus = self::getContainer()->get(MenuBuilder::class);
        $this->actAs('jsmith');
        self::assertNotContains('hello', self::names($menus->projectMenu($this->project())), 'module disabled');

        $this->enableHello();
        self::getContainer()->get(Authorization::class)->reset();
        $items = $menus->projectMenu($this->project(), 'hello');
        $names = self::names($items);
        self::assertSame(array_search('activity', $names, true) + 1, array_search('hello', $names, true), 'after: activity');
        self::assertSame('settings', end($names), 'settings stays last');
        $hello = $items[array_search('hello', $names, true)];
        self::assertSame('/projects/ecommerce/hello', $hello['url']);
        self::assertSame('Greetings', $hello['caption']);
        self::assertTrue($hello['selected']);
        self::assertSame('symine_hello', $hello['plugin']);

        $this->actAs('dlopper'); // Developer: no permission
        self::assertNotContains('hello', self::names($menus->projectMenu($this->project())));
    }

    public function testProjectTabInPage(): void
    {
        $this->enableHello();
        $this->loginAs('jsmith');
        $crawler = $this->get('/projects/ecommerce');
        $this->assertStatus(200);
        $link = $crawler->filter('#main-menu a.hello');
        self::assertCount(1, $link);
        self::assertSame('/projects/ecommerce/hello', $link->attr('href'));
        self::assertSame('Greetings', $link->text());

        $crawler = $this->get('/projects/ecommerce/hello');
        $this->assertStatus(200);
        self::assertCount(1, $crawler->filter('#main-menu a.hello.selected'));
    }

    public function testRegistryPositionsAndConditions(): void
    {
        $registry = self::getContainer()->get(MenuRegistry::class);
        $menus = self::getContainer()->get(MenuBuilder::class);
        $this->actAs('admin');
        $registry
            ->push('top_menu', 'first_item', '/first', ['first' => true, 'caption' => 'First'])
            ->push('top_menu', 'before_projects', '/bp', ['before' => 'projects'])
            ->push('top_menu', 'after_home', '/ah', ['after' => 'home', 'caption' => static fn () => 'Dynamic'])
            ->push('top_menu', 'default_item', 'https://example.org/x', ['html' => ['target' => '_blank']])
            ->push('top_menu', 'very_last', '/vl', ['last' => true])
            ->push('top_menu', 'hidden_item', '/h', ['if' => static fn ($project, $user) => !$user->admin])
            ->push('top_menu', 'unknown_route', 'no_such_route');
        $items = $menus->topMenu();
        self::assertSame(['first_item', 'home', 'after_home', 'my_page', 'before_projects', 'projects', 'default_item', 'administration', 'help', 'very_last'], self::names($items));
        $byName = array_column($items, null, 'name');
        self::assertSame('First', $byName['first_item']['caption']);
        self::assertSame('Dynamic', $byName['after_home']['caption']);
        self::assertSame('Before projects', $byName['before_projects']['caption'], 'humanized name');
        self::assertSame(['target' => '_blank'], $byName['default_item']['html']);
    }

    public function testDeleteAndReplaceCoreItems(): void
    {
        $registry = self::getContainer()->get(MenuRegistry::class);
        $menus = self::getContainer()->get(MenuBuilder::class);
        $this->actAs('admin');
        $registry
            ->delete('top_menu', 'help')
            ->replace('top_menu', 'projects', 'admin_projects', ['caption' => 'label_project_plural'])
            ->push('admin_menu', 'hello_admin', 'admin_plugins', ['icon' => 'plugins', 'caption' => 'Hello admin'])
            ->delete('application_menu', 'activity')
            ->push('account_menu', 'hello_account', '/hello-account', ['before' => 'logout']);
        $top = $menus->topMenu();
        self::assertSame(['home', 'my_page', 'projects', 'administration'], self::names($top));
        self::assertSame('/admin/projects', $top[2]['url'], 'replaced in place');

        $admin = $menus->adminMenu('hello_admin');
        $names = self::names($admin);
        self::assertSame(['plugins', 'info'], \array_slice($names, -2), 'plugins and info stay last');
        $item = $admin[array_search('hello_admin', $names, true)];
        self::assertSame('icon icon-plugins', $item['class']);
        self::assertTrue($item['selected']);

        self::assertNotContains('activity', self::names($menus->applicationMenu()));
        $account = self::names($menus->accountMenu());
        self::assertSame(array_search('logout', $account, true) - 1, array_search('hello_account', $account, true));
    }

    public function testParentChildrenAndPermissionOption(): void
    {
        $this->enableHello();
        $this->settingsNewObjectTab();
        $registry = self::getContainer()->get(MenuRegistry::class);
        $menus = self::getContainer()->get(MenuBuilder::class);
        $registry
            ->push('project_menu', 'new_greeting', 'hello_index', ['parent' => 'new_object', 'param' => 'project_id', 'caption' => 'label_hello_greeting_new'])
            ->push('project_menu', 'secret', '/secret', ['permission' => 'manage_wiki'])
            ->push('project_menu', 'public_tab', '/public', ['permission' => false])
            ->push('project_menu', 'with_children', '/wc', ['children' => static fn ($project) => [['child_a', '/a', ['caption' => 'A']], ['child_b', 'hello_index', ['param' => 'project_id']]]]);
        $this->actAs('dlopper');
        $items = $menus->projectMenu($this->project());
        $names = self::names($items);
        self::assertNotContains('secret', $names, 'Developer has no manage_wiki');
        self::assertContains('public_tab', $names);
        $children = $items[array_search('with_children', $names, true)]['children'];
        self::assertSame(['child_a'], self::names($children), 'child_b needs view_hello_greetings');

        $this->actAs('jsmith');
        $items = $menus->projectMenu($this->project());
        $names = self::names($items);
        self::assertContains('secret', $names);
        $newObject = $items[array_search('new_object', $names, true)];
        self::assertSame('new_greeting', end($newObject['children'])['name']);
        self::assertSame('/projects/ecommerce/hello', end($newObject['children'])['url']);
    }

    public function testInvalidOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(MenuRegistry::class)->push('nope_menu', 'x', '/x');
    }

    public function testInvalidParent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(MenuRegistry::class)->push('top_menu', 'x', '/x', ['parent' => 'x']);
    }

    private function settingsNewObjectTab(): void
    {
        $settings = self::getContainer()->get(\App\Service\Settings::class);
        $settings->set('new_item_menu_tab', '2');
        $this->em->flush();
    }
}

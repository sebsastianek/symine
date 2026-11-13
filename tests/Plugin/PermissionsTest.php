<?php

declare(strict_types=1);

namespace App\Tests\Plugin;

use App\Security\AccessControl;
use App\Security\AccessControlRegistry;
use App\Security\Authorization;
use App\Security\CurrentUser;

/** Plugin permissions & project modules: AccessControl, Authorization, roles editor, project modules */
final class PermissionsTest extends PluginWebTestCase
{
    public function testPermissionsAreRegistered(): void
    {
        self::assertTrue(AccessControl::exists('view_hello_greetings'));
        self::assertSame('hello', AccessControl::moduleOf('view_hello_greetings'));
        self::assertTrue(AccessControl::isRead('view_hello_greetings'));
        self::assertFalse(AccessControl::isRead('add_hello_greetings'));
        self::assertContains('hello', AccessControl::modules());
        self::assertSame(AccessControl::MODULES, \array_slice(AccessControl::modules(), 0, \count(AccessControl::MODULES)));
        self::assertContains('add_hello_greetings', AccessControl::loggedinOnlyPermissions());
        self::assertSame(['view_hello_greetings', 'add_hello_greetings'], AccessControl::permissionsByModule()['hello']);
        self::assertContains('view_hello_greetings', AccessControl::modulesPermissions(['hello']));
        self::assertNotContains('view_hello_greetings', AccessControl::modulesPermissions(['wiki']));
        // anonymous role cannot get loggedin-only permissions
        self::assertContains('view_hello_greetings', AccessControl::setablePermissions(2));
        self::assertNotContains('add_hello_greetings', AccessControl::setablePermissions(2));
        self::assertSame('view_hello_greetings', AccessControl::permissionForRoute('hello_index'));
        self::assertSame('add_hello_greetings', AccessControl::permissionForRoute('hello_create'));
        self::assertNull(AccessControl::permissionForRoute('issues_index'));
        // core permissions untouched
        self::assertSame('issue_tracking', AccessControl::moduleOf('view_issues'));
    }

    public function testRegistryService(): void
    {
        $registry = self::getContainer()->get(AccessControlRegistry::class);
        try {
            $this->registerTestPermissions($registry);
        } finally {
            // static registry: restore the plugin permissions only, for the next tests
            AccessControl::resetRegistered();
            (new \App\Plugin\PluginRegistry(self::$kernel, $registry))->boot();
        }
        self::assertFalse(AccessControl::exists('view_test_things'));
        self::assertTrue(AccessControl::exists('view_hello_greetings'));
    }

    private function registerTestPermissions(AccessControlRegistry $registry): void
    {
        $registry->projectModule('test_module', static function ($map): void {
            $map->permission('view_test_things', ['test_route'], ['read' => true]);
        });
        $registry->permission('global_test_perm', [], ['require' => 'member']);
        self::assertSame('test_module', AccessControl::moduleOf('view_test_things'));
        self::assertContains('test_module', $registry->modules());
        self::assertNull(AccessControl::moduleOf('global_test_perm'));
        self::assertContains('global_test_perm', AccessControl::membersOnlyPermissions());
        try {
            $registry->permission('bad', [], ['require' => 'admin']);
            self::fail('invalid require option accepted');
        } catch (\InvalidArgumentException) {
        }
    }

    public function testAuthorizationNeedsModuleAndRolePermission(): void
    {
        $project = $this->project();
        $jsmith = $this->user('jsmith');
        $auth = self::getContainer()->get(Authorization::class);
        self::assertFalse($auth->allowedTo('view_hello_greetings', $project, $jsmith), 'module disabled');

        $this->enableHello('ecommerce', ['view_hello_greetings']);
        $auth->reset();
        self::assertTrue($auth->allowedTo('view_hello_greetings', $project, $jsmith));
        self::assertFalse($auth->allowedTo('add_hello_greetings', $project, $jsmith), 'not given to the role');
        self::assertFalse($auth->allowedTo('view_hello_greetings', $project, $this->user('dlopper')), 'Developer role');
        self::assertTrue($auth->allowedTo('view_hello_greetings', $project, $this->user('admin')));
        self::assertTrue($auth->allowedTo('view_hello_greetings', $project, self::getContainer()->get(CurrentUser::class)->anonymous()) === false);
    }

    public function testRolesEditorListsPluginPermissions(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/roles/3/edit');
        $this->assertStatus(200);
        self::assertCount(1, $crawler->filter('fieldset#module_hello'));
        self::assertStringContainsString('Greetings', $crawler->filter('fieldset#module_hello legend')->text());
        self::assertCount(1, $crawler->filter('input#role_permissions_view_hello_greetings'));
        self::assertCount(1, $crawler->filter('input#role_permissions_add_hello_greetings'));
        self::assertStringContainsString('View greetings', $crawler->filter('fieldset#module_hello')->text());

        // saving the role keeps the plugin permission
        $role = $this->em->getRepository(\App\Entity\Role::class)->find(3);
        $perms = $role->getPermissions();
        $this->post('/roles/3', ['role' => ['permissions' => [...$perms, 'view_hello_greetings']]], 'PATCH');
        $this->em->clear();
        self::assertContains('view_hello_greetings', $this->em->getRepository(\App\Entity\Role::class)->find(3)->getPermissions());

        $crawler = $this->get('/roles/permissions');
        $this->assertStatus(200);
        self::assertStringContainsString('View greetings', $crawler->text());
    }

    public function testProjectSettingsListsAndEnablesPluginModule(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/projects/ecommerce/settings');
        $this->assertStatus(200);
        $box = $crawler->filter('input#project_enabled_module_names_hello');
        self::assertCount(1, $box);
        self::assertNull($box->attr('checked'));
        $modules = [...$this->project()->getEnabledModuleNames(), 'hello'];
        $this->post('/projects/ecommerce', ['project' => ['enabled_module_names' => $modules]], 'PATCH');
        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->em->clear();
        self::assertTrue($this->project()->isModuleEnabled('hello'));
        // default modules setting lists the plugin module too
        $crawler = $this->get('/settings?tab=projects');
        self::assertCount(1, $crawler->filter('input[value="hello"]'));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Plugin;

use App\Plugin\Asset\PluginAssetsInstaller;
use App\Plugin\Migration\PluginMigrator;
use App\Plugin\PluginRegistry;
use App\Service\Settings;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symine\Hello\HelloPlugin;

/** /admin/plugins, /settings/plugin/:id, plugin migrations and assets */
final class SettingsAndMigrationsTest extends PluginWebTestCase
{
    public function testAdminPluginsPage(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/admin/plugins');
        $this->assertStatus(200);
        $row = $crawler->filter('table.plugins tr#plugin-symine_hello');
        self::assertCount(1, $row);
        self::assertSame('Symine Hello', $row->filter('td.name span.name')->text());
        self::assertSame('Example plugin: greetings project module', $row->filter('td.name span.description')->text());
        self::assertSame('Symine', $row->filter('td.author a')->text());
        self::assertSame('1.0.0', $row->filter('td.version span')->text());
        self::assertSame('/settings/plugin/symine_hello', $row->filter('td.configure a')->attr('href'));
        self::assertCount(1, $crawler->filter('#admin-menu a.plugins.selected'));
    }

    public function testPluginSettingsPage(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/settings/plugin/symine_hello');
        $this->assertStatus(200);
        self::assertStringContainsString('Plugins » Symine Hello', $crawler->filter('h2')->text());
        self::assertCount(1, $crawler->filter('#settings.plugin.plugin-symine_hello form'));
        self::assertSame('Hello', $crawler->filter('input#settings_greeting')->attr('value'));
        self::assertSame('checked', $crawler->filter('input#settings_show_on_issues')->attr('checked'));

        $this->post('/settings/plugin/symine_hello', ['settings' => ['greeting' => 'Bonjour', 'show_on_issues' => '0']]);
        $this->assertRedirectTo('/settings/plugin/symine_hello');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Successful update', $crawler->filter('#flash_notice')->text());
        self::assertSame('Bonjour', $crawler->filter('input#settings_greeting')->attr('value'));
        self::assertNull($crawler->filter('input#settings_show_on_issues')->attr('checked'));

        $settings = self::getContainer()->get(Settings::class);
        $settings->clearCache();
        self::assertSame(['greeting' => 'Bonjour', 'show_on_issues' => '0'], $settings->hash('plugin_symine_hello'));
        $raw = $this->em->getConnection()->fetchOne("SELECT value FROM settings WHERE name = 'plugin_symine_hello'");
        self::assertSame(['greeting' => 'Bonjour', 'show_on_issues' => '0'], json_decode((string) $raw, true));
    }

    public function testPluginSettingsAccess(): void
    {
        $this->get('/settings/plugin/symine_hello');
        self::assertTrue($this->client->getResponse()->isRedirect(), 'anonymous: login required');
        $this->loginAs('jsmith');
        $this->get('/settings/plugin/symine_hello');
        $this->assertStatus(403);
        $this->loginAs('admin');
        $this->get('/settings/plugin/unknown_plugin');
        $this->assertStatus(404);
    }

    public function testMigrations(): void
    {
        $plugin = self::getContainer()->get(PluginRegistry::class)->find(HelloPlugin::ID);
        $migrator = self::getContainer()->get(PluginMigrator::class);
        $conn = $this->em->getConnection();
        self::assertTrue($conn->createSchemaManager()->tablesExist(['hello_greetings']));
        self::assertSame('Symine\Hello\Migrations\Version20261107000000', $migrator->currentVersion($plugin));
        self::assertSame([], $migrator->migrate($plugin), 'up to date');

        self::assertSame(['Symine\Hello\Migrations\Version20261107000000'], $migrator->migrate($plugin, '0'));
        self::assertFalse($conn->createSchemaManager()->tablesExist(['hello_greetings']));
        self::assertNull($migrator->currentVersion($plugin));
        self::assertSame([['version' => 'Symine\Hello\Migrations\Version20261107000000', 'executed' => false, 'description' => 'Create hello_greetings']], $migrator->status($plugin));

        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('symine:plugins:migrate'));
        $tester->execute(['plugin' => 'symine_hello', '--target' => '20261107000000']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('symine_hello: Symine\Hello\Migrations\Version20261107000000', $tester->getDisplay());
        self::assertTrue($conn->createSchemaManager()->tablesExist(['hello_greetings']));

        $tester->execute([]);
        self::assertStringContainsString('symine_hello: up to date', $tester->getDisplay());
        $tester->execute(['plugin' => 'symine_hello', '--status' => true]);
        self::assertStringContainsString('migrated', $tester->getDisplay());
        self::assertSame(1, $tester->execute(['plugin' => 'nope']));

        $list = new CommandTester($app->find('symine:plugins:list'));
        $list->execute([]);
        self::assertStringContainsString('symine_hello', $list->getDisplay());
        self::assertStringContainsString('up to date', $list->getDisplay());
    }

    public function testAssets(): void
    {
        $installer = self::getContainer()->get(PluginAssetsInstaller::class);
        $result = $installer->install();
        self::assertContains($result['symine_hello'], ['symlink', 'copy']);
        $css = $installer->publicDirectory().'/symine_hello/stylesheets/hello.css';
        self::assertFileExists($css);
        self::assertStringContainsString('hello-greetings', (string) file_get_contents($css));
    }

    public function testGreetingsPage(): void
    {
        $project = $this->enableHello();
        $this->addGreeting($project, 'Existing greeting');
        $this->loginAs('jsmith');
        $crawler = $this->get('/projects/ecommerce/hello');
        $this->assertStatus(200);
        self::assertStringContainsString('Existing greeting', $crawler->filter('#hello-greetings')->text());
        $this->post('/projects/ecommerce/hello', ['greeting' => ['message' => 'Posted greeting']]);
        $this->assertRedirectTo('/projects/ecommerce/hello');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Hello! Posted greeting', $crawler->filter('#hello-greetings')->text());
        $this->post('/projects/ecommerce/hello', ['greeting' => ['message' => '  ']]);
        $this->assertStatus(422);

        $this->loginAs('dlopper');
        $this->get('/projects/ecommerce/hello');
        $this->assertStatus(403);
        $this->get('/projects/internal/hello');
        $this->assertStatus(403);
    }
}

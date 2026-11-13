<?php

declare(strict_types=1);

namespace App\Tests\Plugin;

use App\Plugin\PluginDefinition;
use App\Plugin\PluginLoader;
use App\Plugin\PluginNotFoundException;
use App\Plugin\PluginRegistry;
use App\Plugin\PluginRequirementException;
use App\Plugin\SyminePluginInterface;
use App\Service\Settings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symine\Hello\HelloPlugin;

/** Plugin registration, metadata, discovery and requirements (Redmine::Plugin) */
final class PluginRegistryTest extends KernelTestCase
{
    public function testExamplePluginIsDiscoveredAndRegistered(): void
    {
        self::bootKernel();
        self::assertArrayHasKey('HelloPlugin', self::$kernel->getBundles());
        $registry = self::getContainer()->get(PluginRegistry::class);
        self::assertTrue($registry->installed(HelloPlugin::ID));
        $p = $registry->find(HelloPlugin::ID);
        self::assertSame('Symine Hello', $p->name);
        self::assertSame('Symine', $p->author);
        self::assertSame('1.0.0', $p->version);
        self::assertSame('https://github.com/symine/symine', $p->authorUrl);
        self::assertSame('Example plugin: greetings project module', $p->description);
        self::assertSame('0.1', $p->requiresSymine);
        self::assertTrue($p->isConfigurable());
        self::assertSame('plugin_symine_hello', $p->settingName());
        self::assertSame(['greeting' => 'Hello', 'show_on_issues' => '1'], $p->defaultSettings());
        self::assertSame(['hello'], $p->projectModules());
        self::assertSame('Symine\Hello\Migrations', $p->migrationNamespace());
        self::assertTrue($p->hasMigrations());
        self::assertSame('/plugin_assets/symine_hello', $p->assetPrefix());
        self::assertSame(realpath(\dirname(__DIR__, 2).'/plugins/symine-hello'), realpath($p->directory));
    }

    public function testFindUnknownPlugin(): void
    {
        self::bootKernel();
        $this->expectException(PluginNotFoundException::class);
        self::getContainer()->get(PluginRegistry::class)->find('nope');
    }

    public function testLoaderDiscoversComposerPackages(): void
    {
        $found = PluginLoader::discover(\dirname(__DIR__, 2));
        $hello = array_values(array_filter($found, static fn (array $p) => 'symine/hello-plugin' === $p['name']));
        self::assertCount(1, $hello);
        self::assertSame(HelloPlugin::class, $hello[0]['bundle']);
        self::assertSame(['dev', 'test'], $hello[0]['env']);
        self::assertSame([HelloPlugin::class => ['dev' => true, 'test' => true]], array_intersect_key(PluginLoader::bundles(\dirname(__DIR__, 2)), [HelloPlugin::class => 1]));
    }

    public function testPluginSettingsDefaults(): void
    {
        self::bootKernel();
        $settings = self::getContainer()->get(Settings::class);
        self::assertTrue($settings->has('plugin_symine_hello'));
        self::assertSame(['greeting' => 'Hello', 'show_on_issues' => '1'], $settings->hash('plugin_symine_hello'));
        self::assertTrue($settings->definitions()['plugin_symine_hello']['serialized']);
    }

    public function testVersionRequirements(): void
    {
        self::assertSame(0, PluginDefinition::compareVersions('0.1', '0.1.0'));
        self::assertSame(-1, PluginDefinition::compareVersions('0.0.9', '0.1.0'));
        self::assertSame(1, PluginDefinition::compareVersions('7.1', '7.0.0'));
        $def = new PluginDefinition('foo', '/tmp');
        $def->requiresSymine('0.1')->requiresRedmine('7.0')->requiresRedmine(['version' => ['6.1', '7.0']])->requiresRedmine(['version' => ['6.0', '7.1'], 'range' => true]);
        $this->expectException(PluginRequirementException::class);
        $this->expectExceptionMessage('foo plugin requires Redmine 8.0 or higher but current is 7.0.0');
        $def->requiresRedmine('8.0');
    }

    public function testExactVersionRequirement(): void
    {
        $this->expectException(PluginRequirementException::class);
        (new PluginDefinition('foo', '/tmp'))->requiresSymine(['version' => ['9.9']]);
    }

    public function testInvalidPluginId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PluginDefinition('Bad-Id', '/tmp');
    }

    public function testRequiresAnotherPlugin(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(PluginRegistry::class);
        $ok = $registry->add(self::plugin('needs_hello', static fn (PluginDefinition $p) => $p->requiresSyminePlugin('symine_hello', '1.0')));
        self::assertSame('needs_hello', $ok->id);

        self::ensureKernelShutdown();
        self::bootKernel();
        $this->expectException(PluginRequirementException::class);
        $this->expectExceptionMessage('needs_new_hello plugin requires the symine_hello plugin 2.0 or higher but current is 1.0.0');
        self::getContainer()->get(PluginRegistry::class)->add(self::plugin('needs_new_hello', static fn (PluginDefinition $p) => $p->requiresSyminePlugin('symine_hello', '2.0')));
    }

    /** @param \Closure(PluginDefinition): mixed $register */
    public static function plugin(string $id, \Closure $register): SyminePluginInterface
    {
        return new class($id, $register) implements SyminePluginInterface {
            public function __construct(private string $id, private \Closure $register)
            {
            }

            public function getPluginId(): string
            {
                return $this->id;
            }

            public function register(PluginDefinition $plugin): void
            {
                ($this->register)($plugin);
            }

            public function getPath(): string
            {
                return sys_get_temp_dir();
            }
        };
    }
}

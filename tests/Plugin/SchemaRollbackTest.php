<?php

declare(strict_types=1);

namespace App\Tests\Plugin;

use App\Entity\User;
use App\Plugin\Migration\PluginMigrator;
use App\Plugin\PluginRegistry;
use App\Plugin\PluginSchema;
use App\Plugin\PluginSchemaHealer;
use App\Plugin\Search\SearchProviderRegistry;
use App\Security\CurrentUser;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symine\Hello\Activity\GreetingActivityProvider;
use Symine\Hello\HelloPlugin;
use Symine\Hello\HelloSchema;
use Symine\Hello\Hook\HelloViewListener;

/**
 * What PluginSchema remembers about the tables of a plugin never outlives them: a rollback is noticed by the
 * next request, and tables that went away some other way do not break the pages the plugin hooks into.
 */
final class SchemaRollbackTest extends PluginWebTestCase
{
    /** What a following request would be told: same database, same cache pool, nothing else carried over */
    private function nextRequest(): PluginSchema
    {
        return new PluginSchema(self::getContainer()->get(Connection::class), self::getContainer()->get('cache.plugin_schema'));
    }

    /** The answer the next request gets (from the pool when it is there, from the schema otherwise) */
    private function remembered(): bool
    {
        return $this->nextRequest()->hasTables(HelloPlugin::ID, ['hello_greetings']);
    }

    public function testARollbackIsNoticedImmediately(): void
    {
        $schema = self::getContainer()->get(HelloSchema::class);
        self::assertTrue($schema->isInstalled());
        self::assertTrue($this->remembered());

        $plugin = self::getContainer()->get(PluginRegistry::class)->find(HelloPlugin::ID);
        self::assertNotSame([], self::getContainer()->get(PluginMigrator::class)->migrate($plugin, '0'));

        self::assertFalse($schema->isInstalled(), 'in the request that rolled back');
        self::assertFalse($this->remembered(), 'and in the next one, without waiting for the remembered answer to expire');

        self::getContainer()->get(PluginMigrator::class)->migrate($plugin);
        self::assertTrue($this->nextRequest()->hasTables(HelloPlugin::ID, ['hello_greetings']), 'migrating again is noticed just as fast');
    }

    public function testTablesThatWentAwayDoNotBreakThePagesThePluginHooksInto(): void
    {
        $project = $this->enableHello();
        $this->addGreeting($project, 'Greeting to search for');
        $jsmith = $this->em->getRepository(User::class)->findOneBy(['login' => 'jsmith']);
        self::getContainer()->get(CurrentUser::class)->set($jsmith);
        $schema = self::getContainer()->get(HelloSchema::class);
        self::assertTrue($schema->isInstalled());

        // another server rolled the plugin back, or the database was replaced by one without the plugin
        $this->em->getConnection()->executeStatement('DROP TABLE hello_greetings');

        $search = self::getContainer()->get(SearchProviderRegistry::class)->get('hello_greetings');
        self::assertSame([], $search->resultRanksAndIds(['greeting'], $jsmith, null, []), 'the search goes on without the plugin');
        self::assertFalse($schema->isInstalled(), 'not installed for the rest of the request');
        self::assertFalse($this->remembered(), 'and the remembered answer is gone');

        // every entry point of the plugin into core pages behaves the same, whichever meets the error first
        foreach ([
            'search results' => fn () => $search->results([1]),
            'activity' => fn () => self::getContainer()->get(GreetingActivityProvider::class)->findEvents(new \App\Plugin\Activity\ActivityScope($jsmith, $project, true, null, null, null, 10, self::getContainer()->get(\App\Security\Authorization::class))),
            'project overview' => fn () => self::getContainer()->get(HelloViewListener::class)->renderOn('view_projects_show_left', ['project' => $project]),
        ] as $where => $read) {
            $this->nextRequest()->forget(HelloPlugin::ID);
            $schema->reset();
            $this->em->getConnection()->executeStatement('CREATE TABLE IF NOT EXISTS hello_greetings (id INTEGER PRIMARY KEY)');
            self::assertTrue($schema->isInstalled());
            $this->em->getConnection()->executeStatement('DROP TABLE hello_greetings');
            self::assertSame([], $read(), $where);
            self::assertFalse($this->remembered(), $where);
        }
    }

    public function testATableNotFoundErrorThatReachesTheKernelDropsWhatIsRemembered(): void
    {
        self::assertTrue(self::getContainer()->get(HelloSchema::class)->isInstalled());
        self::assertTrue($this->remembered());
        $this->em->getConnection()->executeStatement('DROP TABLE hello_greetings');

        try {
            $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM hello_greetings');
            self::fail('the table is gone');
        } catch (TableNotFoundException $e) {
            // as it reaches the kernel: wrapped by whatever was running (a template, a controller)
            $event = new ExceptionEvent(self::$kernel, Request::create('/kanban/boards/1'), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('An exception has been thrown during the rendering of a template', 0, $e));
            self::getContainer()->get(PluginSchemaHealer::class)->onKernelException($event);
        }

        self::assertFalse($this->remembered(), 'the next request looks at the schema instead of failing again');
    }
}

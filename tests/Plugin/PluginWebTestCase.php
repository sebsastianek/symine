<?php

declare(strict_types=1);

namespace App\Tests\Plugin;

use App\Entity\EnabledModule;
use App\Entity\Project;
use App\Entity\Role;
use App\Plugin\Migration\PluginMigrator;
use App\Plugin\PluginRegistry;
use App\Tests\Admin\AdminWebTestCase;
use Symine\Hello\Entity\Greeting;
use Symine\Hello\HelloPlugin;

/**
 * Functional tests of the plugin API with the example plugin (plugins/symine-hello, registered in the
 * test environment by PluginLoader). The plugin migrations are run on the fresh test database.
 */
abstract class PluginWebTestCase extends AdminWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $registry = self::getContainer()->get(PluginRegistry::class);
        self::getContainer()->get(PluginMigrator::class)->migrate($registry->find(HelloPlugin::ID));
        self::getContainer()->get(\Symine\Hello\HelloSchema::class)->reset();
    }

    protected function project(string $identifier = 'ecommerce'): Project
    {
        $p = $this->em->getRepository(Project::class)->findOneBy(['identifier' => $identifier]);
        self::assertNotNull($p);

        return $p;
    }

    /** Enables the "hello" module on the project and gives the Hello permissions to the Manager role */
    protected function enableHello(string $identifier = 'ecommerce', array $permissions = ['view_hello_greetings', 'add_hello_greetings']): Project
    {
        $project = $this->project($identifier);
        if (!$project->isModuleEnabled('hello')) {
            $m = new EnabledModule();
            $m->project = $project;
            $m->name = 'hello';
            $project->enabledModules->add($m);
            $this->em->persist($m);
        }
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => 'Manager']);
        self::assertNotNull($role);
        $role->setPermissions([...$role->getPermissions(), ...$permissions]);
        $this->em->flush();

        return $project;
    }

    protected function addGreeting(Project $project, string $message, string $login = 'jsmith'): Greeting
    {
        $g = new Greeting();
        $g->project = $project;
        $g->author = $this->user($login);
        $g->message = $message;
        $this->em->persist($g);
        $this->em->flush();

        return $g;
    }

    protected function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}

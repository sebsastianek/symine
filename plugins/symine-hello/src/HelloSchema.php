<?php

declare(strict_types=1);

namespace Symine\Hello;

use App\Plugin\PluginSchema;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * Whether the plugin migrations have been run (hello_greetings exists): the providers and hooks of the
 * plugin stay silent until `bin/console symine:plugins:migrate symine_hello` has been run, so installing
 * the plugin cannot break the activity/search pages.
 *
 * The hooks ask on every request: the answer comes from PluginSchema, which remembers "yes" for this database
 * across requests. Should the table have gone away since (see PluginSchema), whenInstalled() notices on the
 * first query and the plugin stays silent, as if it had never been migrated.
 */
final class HelloSchema
{
    public function __construct(private readonly PluginSchema $schema)
    {
    }

    public function isInstalled(): bool
    {
        return $this->schema->hasTables(HelloPlugin::ID, ['hello_greetings']);
    }

    /**
     * Runs code that reads the tables of the plugin, when they exist; gives $otherwise when they do not.
     * This is how the code of the plugin that runs inside core pages (hooks, activity, search) reads its tables.
     *
     * @template T
     *
     * @param callable(): T $read
     * @param T             $otherwise
     *
     * @return T
     */
    public function whenInstalled(callable $read, mixed $otherwise): mixed
    {
        if (!$this->isInstalled()) {
            return $otherwise;
        }
        try {
            return $read();
        } catch (\Throwable $e) {
            for ($cause = $e; null !== $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof TableNotFoundException) {
                    $this->schema->missing(HelloPlugin::ID);

                    return $otherwise;
                }
            }
            throw $e;
        }
    }

    public function reset(): void
    {
        $this->schema->forget(HelloPlugin::ID);
    }
}

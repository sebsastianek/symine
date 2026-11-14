<?php

declare(strict_types=1);

namespace App\Plugin;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * A "table not found" error that reaches the kernel may mean that the tables of a plugin went away while
 * PluginSchema still remembers them (see there): what it remembers is dropped, so that the next request looks
 * at the schema again instead of failing for as long as the remembered answer lives.
 */
final class PluginSchemaHealer
{
    public function __construct(
        private readonly PluginSchema $schema,
        private readonly PluginRegistry $plugins,
    ) {
    }

    #[AsEventListener]
    public function onKernelException(ExceptionEvent $event): void
    {
        for ($e = $event->getThrowable(); null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof TableNotFoundException) {
                foreach ($this->plugins->all() as $plugin) {
                    $this->schema->forget($plugin->id);
                }

                return;
            }
        }
    }
}

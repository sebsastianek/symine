<?php

declare(strict_types=1);

namespace Symine\Hello;

use Doctrine\DBAL\Connection;

/**
 * Whether the plugin migrations have been run (hello_greetings exists): the providers and hooks of the
 * plugin stay silent until `bin/console symine:plugins:migrate symine_hello` has been run, so installing
 * the plugin cannot break the activity/search pages.
 */
final class HelloSchema
{
    private ?bool $installed = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function isInstalled(): bool
    {
        return $this->installed ??= $this->connection->createSchemaManager()->tablesExist(['hello_greetings']);
    }

    public function reset(): void
    {
        $this->installed = null;
    }
}

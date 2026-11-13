<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Redmine's schema has no foreign key constraints (integrity is enforced by the application).
 * Symine keeps the exact same physical schema so a Redmine database can be imported (or adopted)
 * without constraint violations caused by legacy dangling references.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class NoForeignKeysListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        foreach ($args->getSchema()->getTables() as $table) {
            foreach ($table->getForeignKeys() as $fk) {
                $table->dropForeignKey($fk->getName());
            }
        }
    }
}

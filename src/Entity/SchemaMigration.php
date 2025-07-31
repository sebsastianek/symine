<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\SchemaMigrationRepository;

/**
 * SchemaMigration.
 * Table: schema_migrations
 */
#[ORM\Entity(repositoryClass: SchemaMigrationRepository::class)]
#[ORM\Table(name: 'schema_migrations')]
class SchemaMigration
{
    /**
     * Property version
     */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $version;

    /**
     * Getter for version
     */
    public function getVersion(): string    {
        return $this->version;
    }

    /**
     * Setter for version
     */
    public function setVersion(string $version): static
    {
        $this->version = $version;
        return $this;
    }

}

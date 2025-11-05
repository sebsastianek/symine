<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\ArInternalMetadataRepository;

/**
 * ArInternalMetadata.
 * Table: ar_internal_metadata
 */
#[ORM\Entity(repositoryClass: ArInternalMetadataRepository::class)]
#[ORM\Table(name: 'ar_internal_metadata')]
class ArInternalMetadata
{
    /**
     * Property key
     */
    #[ORM\Id]
    #[ORM\Column(name: '`key`', type: 'string', length: 255, nullable: false)]
    private string $key;

    /**
     * Property value
     */
    #[ORM\Column(name: '`value`', type: 'string', length: 255, nullable: true)]
    private ?string $value = NULL;

    /**
     * Property createdAt
     */
    #[ORM\Column(type: 'datetime', length: 6)]
    private \DateTimeInterface $createdAt;

    /**
     * Property updatedAt
     */
    #[ORM\Column(type: 'datetime', length: 6)]
    private \DateTimeInterface $updatedAt;

    /**
     * Getter for key
     */
    public function getKey(): string    {
        return $this->key;
    }

    /**
     * Setter for key
     */
    public function setKey(string $key): static
    {
        $this->key = $key;
        return $this;
    }

    /**
     * Getter for value
     */
    public function getValue(): ?string    {
        return $this->value;
    }

    /**
     * Setter for value
     */
    public function setValue(?string $value): static
    {
        $this->value = $value;
        return $this;
    }

    /**
     * Getter for createdAt
     */
    public function getCreatedAt(): \DateTimeInterface    {
        return $this->createdAt;
    }

    /**
     * Setter for createdAt
     */
    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * Getter for updatedAt
     */
    public function getUpdatedAt(): \DateTimeInterface    {
        return $this->updatedAt;
    }

    /**
     * Setter for updatedAt
     */
    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

}

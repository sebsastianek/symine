<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\ImportItemRepository;

/**
 * ImportItem.
 * Table: import_items
 */
#[ORM\Entity(repositoryClass: ImportItemRepository::class)]
#[ORM\Table(name: 'import_items')]
class ImportItem
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property import
     */
    #[ORM\ManyToOne(targetEntity: Import::class)]
    #[ORM\JoinColumn(name: 'import_id', referencedColumnName: 'id', nullable: false)]
    private Import $import;

    /**
     * Property position
     */
    #[ORM\Column(type: 'integer')]
    private int $position;

    /**
     * Property objId
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $objId = NULL;

    /**
     * Property message
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $message = NULL;

    /**
     * Property uniqueId
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $uniqueId = NULL;

    /**
     * Getter for id
     */
    public function getId(): int    {
        return $this->id;
    }

    /**
     * Setter for id
     */
    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Getter for import
     */
    public function getImport(): Import
    {
        return $this->import;
    }

    /**
     * Setter for import
     */
    public function setImport(Import $import): static
    {
        $this->import = $import;
        return $this;
    }

    /**
     * Getter for position
     */
    public function getPosition(): int    {
        return $this->position;
    }

    /**
     * Setter for position
     */
    public function setPosition(int $position): static
    {
        $this->position = $position;
        return $this;
    }

    /**
     * Getter for objId
     */
    public function getObjId(): ?int    {
        return $this->objId;
    }

    /**
     * Setter for objId
     */
    public function setObjId(?int $objId): static
    {
        $this->objId = $objId;
        return $this;
    }

    /**
     * Getter for message
     */
    public function getMessage(): ?string    {
        return $this->message;
    }

    /**
     * Setter for message
     */
    public function setMessage(?string $message): static
    {
        $this->message = $message;
        return $this;
    }

    /**
     * Getter for uniqueId
     */
    public function getUniqueId(): ?string    {
        return $this->uniqueId;
    }

    /**
     * Setter for uniqueId
     */
    public function setUniqueId(?string $uniqueId): static
    {
        $this->uniqueId = $uniqueId;
        return $this;
    }

}

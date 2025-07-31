<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\EnumerationRepository;

/**
 * Enumeration.
 * Table: enumerations
 */
#[ORM\Entity(repositoryClass: EnumerationRepository::class)]
#[ORM\Table(name: 'enumerations')]
class Enumeration
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property name
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => ''])]
    private string $name = '';

    /**
     * Property position
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $position = NULL;

    /**
     * Property isDefault
     */
    #[ORM\Column(type: 'boolean', options: ['default' => '0'])]
    private int $isDefault = 0;

    /**
     * Property type
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $type = NULL;

    /**
     * Property active
     */
    #[ORM\Column(type: 'boolean', options: ['default' => '1'])]
    private int $active = 1;

    /**
     * Property projectId
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $projectId = NULL;

    /**
     * Property parentId
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $parentId = NULL;

    /**
     * Property positionName
     */
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $positionName = NULL;

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
     * Getter for name
     */
    public function getName(): string    {
        return $this->name;
    }

    /**
     * Setter for name
     */
    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Getter for position
     */
    public function getPosition(): ?int    {
        return $this->position;
    }

    /**
     * Setter for position
     */
    public function setPosition(?int $position): static
    {
        $this->position = $position;
        return $this;
    }

    /**
     * Getter for isDefault
     */
    public function getIsDefault(): int    {
        return $this->isDefault;
    }

    /**
     * Setter for isDefault
     */
    public function setIsDefault(int $isDefault): static
    {
        $this->isDefault = $isDefault;
        return $this;
    }

    /**
     * Getter for type
     */
    public function getType(): ?string    {
        return $this->type;
    }

    /**
     * Setter for type
     */
    public function setType(?string $type): static
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Getter for active
     */
    public function getActive(): int    {
        return $this->active;
    }

    /**
     * Setter for active
     */
    public function setActive(int $active): static
    {
        $this->active = $active;
        return $this;
    }

    /**
     * Getter for projectId
     */
    public function getProjectId(): ?int    {
        return $this->projectId;
    }

    /**
     * Setter for projectId
     */
    public function setProjectId(?int $projectId): static
    {
        $this->projectId = $projectId;
        return $this;
    }

    /**
     * Getter for parentId
     */
    public function getParentId(): ?int    {
        return $this->parentId;
    }

    /**
     * Setter for parentId
     */
    public function setParentId(?int $parentId): static
    {
        $this->parentId = $parentId;
        return $this;
    }

    /**
     * Getter for positionName
     */
    public function getPositionName(): ?string    {
        return $this->positionName;
    }

    /**
     * Setter for positionName
     */
    public function setPositionName(?string $positionName): static
    {
        $this->positionName = $positionName;
        return $this;
    }

}

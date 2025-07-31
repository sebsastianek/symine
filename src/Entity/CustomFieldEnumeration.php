<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\CustomFieldEnumerationRepository;

/**
 * CustomFieldEnumeration.
 * Table: custom_field_enumerations
 */
#[ORM\Entity(repositoryClass: CustomFieldEnumerationRepository::class)]
#[ORM\Table(name: 'custom_field_enumerations')]
class CustomFieldEnumeration
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property customField
     */
    #[ORM\ManyToOne(targetEntity: CustomField::class)]
    #[ORM\JoinColumn(name: 'custom_field_id', referencedColumnName: 'id', nullable: false)]
    private CustomField $customField;

    /**
     * Property name
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /**
     * Property active
     */
    #[ORM\Column(type: 'boolean', options: ['default' => '1'])]
    private int $active = 1;

    /**
     * Property position
     */
    #[ORM\Column(type: 'integer', options: ['default' => '1'])]
    private int $position = 1;

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
     * Getter for customField
     */
    public function getCustomField(): CustomField
    {
        return $this->customField;
    }

    /**
     * Setter for customField
     */
    public function setCustomField(CustomField $customField): static
    {
        $this->customField = $customField;
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

}

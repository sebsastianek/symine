<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\CustomValueRepository;

/**
 * CustomValue.
 * Table: custom_values
 */
#[ORM\Entity(repositoryClass: CustomValueRepository::class)]
#[ORM\Table(name: 'custom_values')]
class CustomValue
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property customizedType
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => ''])]
    private string $customizedType = '';

    /**
     * Property customizedId
     */
    #[ORM\Column(type: 'integer', options: ['default' => '0'])]
    private int $customizedId = 0;

    /**
     * Property customField
     */
    #[ORM\ManyToOne(targetEntity: CustomField::class)]
    #[ORM\JoinColumn(name: 'custom_field_id', referencedColumnName: 'id', nullable: false)]
    private CustomField $customField;

    /**
     * Property value
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = NULL;

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
     * Getter for customizedType
     */
    public function getCustomizedType(): string    {
        return $this->customizedType;
    }

    /**
     * Setter for customizedType
     */
    public function setCustomizedType(string $customizedType): static
    {
        $this->customizedType = $customizedType;
        return $this;
    }

    /**
     * Getter for customizedId
     */
    public function getCustomizedId(): int    {
        return $this->customizedId;
    }

    /**
     * Setter for customizedId
     */
    public function setCustomizedId(int $customizedId): static
    {
        $this->customizedId = $customizedId;
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

}

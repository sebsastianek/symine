<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\CustomFieldRepository;

/**
 * CustomField.
 * Table: custom_fields
 */
#[ORM\Entity(repositoryClass: CustomFieldRepository::class)]
#[ORM\Table(name: 'custom_fields')]
class CustomField
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property type
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => ''])]
    private string $type = '';

    /**
     * Property name
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => ''])]
    private string $name = '';

    /**
     * Property fieldFormat
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => ''])]
    private string $fieldFormat = '';

    /**
     * Property possibleValues
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $possibleValues = NULL;

    /**
     * Property regexp
     */
    #[ORM\Column(name: 'regex_pattern', type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    private ?string $regexp = '';

    /**
     * Property minLength
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $minLength = NULL;

    /**
     * Property maxLength
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxLength = NULL;

    /**
     * Property isRequired
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isRequired = false;

    /**
     * Property isForAll
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isForAll = false;

    /**
     * Property isFilter
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isFilter = false;

    /**
     * Property position
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $position = NULL;

    /**
     * Property searchable
     */
    #[ORM\Column(type: 'boolean', nullable: true, options: ['default' => false])]
    private ?bool $searchable = false;

    /**
     * Property defaultValue
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $defaultValue = NULL;

    /**
     * Property editable
     */
    #[ORM\Column(type: 'boolean', nullable: true, options: ['default' => true])]
    private ?bool $editable = true;

    /**
     * Property visible
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $visible = true;

    /**
     * Property multiple
     */
    #[ORM\Column(type: 'boolean', nullable: true, options: ['default' => false])]
    private ?bool $multiple = false;

    /**
     * Property formatStore
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $formatStore = NULL;

    /**
     * Property description
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $description = NULL;

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
     * Getter for type
     */
    public function getType(): string    {
        return $this->type;
    }

    /**
     * Setter for type
     */
    public function setType(string $type): static
    {
        $this->type = $type;
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
     * Getter for fieldFormat
     */
    public function getFieldFormat(): string    {
        return $this->fieldFormat;
    }

    /**
     * Setter for fieldFormat
     */
    public function setFieldFormat(string $fieldFormat): static
    {
        $this->fieldFormat = $fieldFormat;
        return $this;
    }

    /**
     * Getter for possibleValues
     */
    public function getPossibleValues(): ?string    {
        return $this->possibleValues;
    }

    /**
     * Setter for possibleValues
     */
    public function setPossibleValues(?string $possibleValues): static
    {
        $this->possibleValues = $possibleValues;
        return $this;
    }

    /**
     * Getter for regexp
     */
    public function getRegexp(): ?string    {
        return $this->regexp;
    }

    /**
     * Setter for regexp
     */
    public function setRegexp(?string $regexp): static
    {
        $this->regexp = $regexp;
        return $this;
    }

    /**
     * Getter for minLength
     */
    public function getMinLength(): ?int    {
        return $this->minLength;
    }

    /**
     * Setter for minLength
     */
    public function setMinLength(?int $minLength): static
    {
        $this->minLength = $minLength;
        return $this;
    }

    /**
     * Getter for maxLength
     */
    public function getMaxLength(): ?int    {
        return $this->maxLength;
    }

    /**
     * Setter for maxLength
     */
    public function setMaxLength(?int $maxLength): static
    {
        $this->maxLength = $maxLength;
        return $this;
    }

    /**
     * Getter for isRequired
     */
    public function getIsRequired(): bool
    {
        return $this->isRequired;
    }

    /**
     * Setter for isRequired
     */
    public function setIsRequired(bool $isRequired): static
    {
        $this->isRequired = $isRequired;
        return $this;
    }

    /**
     * Getter for isForAll
     */
    public function getIsForAll(): bool
    {
        return $this->isForAll;
    }

    /**
     * Setter for isForAll
     */
    public function setIsForAll(bool $isForAll): static
    {
        $this->isForAll = $isForAll;
        return $this;
    }

    /**
     * Getter for isFilter
     */
    public function getIsFilter(): bool
    {
        return $this->isFilter;
    }

    /**
     * Setter for isFilter
     */
    public function setIsFilter(bool $isFilter): static
    {
        $this->isFilter = $isFilter;
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
     * Getter for searchable
     */
    public function getSearchable(): ?bool
    {
        return $this->searchable;
    }

    /**
     * Setter for searchable
     */
    public function setSearchable(?bool $searchable): static
    {
        $this->searchable = $searchable;
        return $this;
    }

    /**
     * Getter for defaultValue
     */
    public function getDefaultValue(): ?string    {
        return $this->defaultValue;
    }

    /**
     * Setter for defaultValue
     */
    public function setDefaultValue(?string $defaultValue): static
    {
        $this->defaultValue = $defaultValue;
        return $this;
    }

    /**
     * Getter for editable
     */
    public function getEditable(): ?bool
    {
        return $this->editable;
    }

    /**
     * Setter for editable
     */
    public function setEditable(?bool $editable): static
    {
        $this->editable = $editable;
        return $this;
    }

    /**
     * Getter for visible
     */
    public function getVisible(): bool
    {
        return $this->visible;
    }

    /**
     * Setter for visible
     */
    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;
        return $this;
    }

    /**
     * Getter for multiple
     */
    public function getMultiple(): ?bool
    {
        return $this->multiple;
    }

    /**
     * Setter for multiple
     */
    public function setMultiple(?bool $multiple): static
    {
        $this->multiple = $multiple;
        return $this;
    }

    /**
     * Getter for formatStore
     */
    public function getFormatStore(): ?string    {
        return $this->formatStore;
    }

    /**
     * Setter for formatStore
     */
    public function setFormatStore(?string $formatStore): static
    {
        $this->formatStore = $formatStore;
        return $this;
    }

    /**
     * Getter for description
     */
    public function getDescription(): ?string    {
        return $this->description;
    }

    /**
     * Setter for description
     */
    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

}

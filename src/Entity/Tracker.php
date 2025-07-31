<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\TrackerRepository;

/**
 * Tracker.
 * Table: trackers
 */
#[ORM\Entity(repositoryClass: TrackerRepository::class)]
#[ORM\Table(name: 'trackers')]
class Tracker
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
     * Property description
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $description = NULL;

    /**
     * Property position
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $position = NULL;

    /**
     * Property isInRoadmap
     */
    #[ORM\Column(type: 'boolean', options: ['default' => '1'])]
    private int $isInRoadmap = 1;

    /**
     * Property fieldsBits
     */
    #[ORM\Column(type: 'integer', nullable: true, options: ['default' => '0'])]
    private ?int $fieldsBits = 0;

    /**
     * Property defaultStatusId
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $defaultStatusId = NULL;

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
     * Getter for isInRoadmap
     */
    public function getIsInRoadmap(): int    {
        return $this->isInRoadmap;
    }

    /**
     * Setter for isInRoadmap
     */
    public function setIsInRoadmap(int $isInRoadmap): static
    {
        $this->isInRoadmap = $isInRoadmap;
        return $this;
    }

    /**
     * Getter for fieldsBits
     */
    public function getFieldsBits(): ?int    {
        return $this->fieldsBits;
    }

    /**
     * Setter for fieldsBits
     */
    public function setFieldsBits(?int $fieldsBits): static
    {
        $this->fieldsBits = $fieldsBits;
        return $this;
    }

    /**
     * Getter for defaultStatusId
     */
    public function getDefaultStatusId(): ?int    {
        return $this->defaultStatusId;
    }

    /**
     * Setter for defaultStatusId
     */
    public function setDefaultStatusId(?int $defaultStatusId): static
    {
        $this->defaultStatusId = $defaultStatusId;
        return $this;
    }

}

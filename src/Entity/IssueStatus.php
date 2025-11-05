<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\IssueStatusRepository;

/**
 * IssueStatus.
 * Table: issue_statuses
 */
#[ORM\Entity(repositoryClass: IssueStatusRepository::class)]
#[ORM\Table(name: 'issue_statuses')]
class IssueStatus
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
     * Property isClosed
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isClosed = false;

    /**
     * Property position
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $position = NULL;

    /**
     * Property defaultDoneRatio
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $defaultDoneRatio = NULL;

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
     * Getter for isClosed
     */
    public function getIsClosed(): bool
    {
        return $this->isClosed;
    }

    /**
     * Setter for isClosed
     */
    public function setIsClosed(bool $isClosed): static
    {
        $this->isClosed = $isClosed;
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
     * Getter for defaultDoneRatio
     */
    public function getDefaultDoneRatio(): ?int    {
        return $this->defaultDoneRatio;
    }

    /**
     * Setter for defaultDoneRatio
     */
    public function setDefaultDoneRatio(?int $defaultDoneRatio): static
    {
        $this->defaultDoneRatio = $defaultDoneRatio;
        return $this;
    }

}

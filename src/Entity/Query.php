<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\QueryRepository;

/**
 * Query.
 * Table: queries
 */
#[ORM\Entity(repositoryClass: QueryRepository::class)]
#[ORM\Table(name: 'queries')]
class Query
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property project
     */
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: true)]
    private ?Project $project = null;

    /**
     * Property name
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $name = '';

    /**
     * Property filters
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $filters = NULL;

    /**
     * Property user
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    /**
     * Property columnNames
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $columnNames = NULL;

    /**
     * Property sortCriteria
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $sortCriteria = NULL;

    /**
     * Property groupBy
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $groupBy = NULL;

    /**
     * Property type
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $type = NULL;

    /**
     * Property visibility
     */
    #[ORM\Column(type: 'integer', nullable: true, options: ['default' => '0'])]
    private ?int $visibility = 0;

    /**
     * Property options
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $options = NULL;

    /**
     * Property description
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
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
     * Getter for project
     */
    public function getProject(): ?Project
    {
        return $this->project;
    }

    /**
     * Setter for project
     */
    public function setProject(?Project $project): static
    {
        $this->project = $project;
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
     * Getter for filters
     */
    public function getFilters(): ?string    {
        return $this->filters;
    }

    /**
     * Setter for filters
     */
    public function setFilters(?string $filters): static
    {
        $this->filters = $filters;
        return $this;
    }

    /**
     * Getter for user
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * Setter for user
     */
    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Getter for columnNames
     */
    public function getColumnNames(): ?string    {
        return $this->columnNames;
    }

    /**
     * Setter for columnNames
     */
    public function setColumnNames(?string $columnNames): static
    {
        $this->columnNames = $columnNames;
        return $this;
    }

    /**
     * Getter for sortCriteria
     */
    public function getSortCriteria(): ?string    {
        return $this->sortCriteria;
    }

    /**
     * Setter for sortCriteria
     */
    public function setSortCriteria(?string $sortCriteria): static
    {
        $this->sortCriteria = $sortCriteria;
        return $this;
    }

    /**
     * Getter for groupBy
     */
    public function getGroupBy(): ?string    {
        return $this->groupBy;
    }

    /**
     * Setter for groupBy
     */
    public function setGroupBy(?string $groupBy): static
    {
        $this->groupBy = $groupBy;
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
     * Getter for visibility
     */
    public function getVisibility(): ?int    {
        return $this->visibility;
    }

    /**
     * Setter for visibility
     */
    public function setVisibility(?int $visibility): static
    {
        $this->visibility = $visibility;
        return $this;
    }

    /**
     * Getter for options
     */
    public function getOptions(): ?string    {
        return $this->options;
    }

    /**
     * Setter for options
     */
    public function setOptions(?string $options): static
    {
        $this->options = $options;
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

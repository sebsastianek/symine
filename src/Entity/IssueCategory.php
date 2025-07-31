<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\IssueCategoryRepository;

/**
 * IssueCategory.
 * Table: issue_categories
 */
#[ORM\Entity(repositoryClass: IssueCategoryRepository::class)]
#[ORM\Table(name: 'issue_categories')]
class IssueCategory
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
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false)]
    private Project $project;

    /**
     * Property name
     */
    #[ORM\Column(type: 'string', length: 60, options: ['default' => ''])]
    private string $name = '';

    /**
     * Property assignedTo
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_to_id', referencedColumnName: 'id', nullable: true)]
    private ?User $assignedTo = null;

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
    public function getProject(): Project
    {
        return $this->project;
    }

    /**
     * Setter for project
     */
    public function setProject(Project $project): static
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
     * Getter for assignedTo
     */
    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    /**
     * Setter for assignedTo
     */
    public function setAssignedTo(?User $assignedTo): static
    {
        $this->assignedTo = $assignedTo;
        return $this;
    }

}

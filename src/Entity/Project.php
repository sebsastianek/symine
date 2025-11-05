<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\ProjectRepository;

/**
 * Project.
 * Table: projects
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'projects')]
class Project
{
    public function __construct()
    {
        $this->children = new ArrayCollection();
    }
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
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $name = '';

    /**
     * Property description
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $description = NULL;

    /**
     * Property homepage
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    private ?string $homepage = '';

    /**
     * Property isPublic
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isPublic = true;

    /**
     * Property parent
     */
    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true)]
    private ?Project $parent = null;

    /**
     * Property children
     */
    #[ORM\OneToMany(targetEntity: Project::class, mappedBy: 'parent')]
    private Collection $children;

    /**
     * Property createdOn
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $createdOn = NULL;

    /**
     * Property updatedOn
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedOn = NULL;

    /**
     * Property identifier
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $identifier = NULL;

    /**
     * Property status
     */
    #[ORM\Column(type: 'integer', options: ['default' => '1'])]
    private int $status = 1;

    /**
     * Property lft
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $lft = NULL;

    /**
     * Property rgt
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $rgt = NULL;

    /**
     * Property inheritMembers
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $inheritMembers = false;

    /**
     * Property defaultVersion
     */
    #[ORM\ManyToOne(targetEntity: Version::class)]
    #[ORM\JoinColumn(name: 'default_version_id', referencedColumnName: 'id', nullable: true)]
    private ?Version $defaultVersion = null;

    /**
     * Property defaultAssignedTo
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'default_assigned_to_id', referencedColumnName: 'id', nullable: true)]
    private ?User $defaultAssignedTo = null;

    /**
     * Property defaultIssueQuery
     */
    #[ORM\ManyToOne(targetEntity: Query::class)]
    #[ORM\JoinColumn(name: 'default_issue_query_id', referencedColumnName: 'id', nullable: true)]
    private ?Query $defaultIssueQuery = null;

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
     * Getter for homepage
     */
    public function getHomepage(): ?string    {
        return $this->homepage;
    }

    /**
     * Setter for homepage
     */
    public function setHomepage(?string $homepage): static
    {
        $this->homepage = $homepage;
        return $this;
    }

    /**
     * Getter for isPublic
     */
    public function getIsPublic(): bool
    {
        return $this->isPublic;
    }

    /**
     * Setter for isPublic
     */
    public function setIsPublic(bool $isPublic): static
    {
        $this->isPublic = $isPublic;
        return $this;
    }

    /**
     * Getter for parent
     */
    public function getParent(): ?Project
    {
        return $this->parent;
    }

    /**
     * Setter for parent
     */
    public function setParent(?Project $parent): static
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * Getter for children
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    /**
     * Add child project
     */
    public function addChild(Project $child): static
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }
        return $this;
    }

    /**
     * Remove child project
     */
    public function removeChild(Project $child): static
    {
        if ($this->children->removeElement($child)) {
            if ($child->getParent() === $this) {
                $child->setParent(null);
            }
        }
        return $this;
    }

    /**
     * Getter for createdOn
     */
    public function getCreatedOn(): ?\DateTimeInterface    {
        return $this->createdOn;
    }

    /**
     * Setter for createdOn
     */
    public function setCreatedOn(?\DateTimeInterface $createdOn): static
    {
        $this->createdOn = $createdOn;
        return $this;
    }

    /**
     * Getter for updatedOn
     */
    public function getUpdatedOn(): ?\DateTimeInterface    {
        return $this->updatedOn;
    }

    /**
     * Setter for updatedOn
     */
    public function setUpdatedOn(?\DateTimeInterface $updatedOn): static
    {
        $this->updatedOn = $updatedOn;
        return $this;
    }

    /**
     * Getter for identifier
     */
    public function getIdentifier(): ?string    {
        return $this->identifier;
    }

    /**
     * Setter for identifier
     */
    public function setIdentifier(?string $identifier): static
    {
        $this->identifier = $identifier;
        return $this;
    }

    /**
     * Getter for status
     */
    public function getStatus(): int    {
        return $this->status;
    }

    /**
     * Setter for status
     */
    public function setStatus(int $status): static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Getter for lft
     */
    public function getLft(): ?int    {
        return $this->lft;
    }

    /**
     * Setter for lft
     */
    public function setLft(?int $lft): static
    {
        $this->lft = $lft;
        return $this;
    }

    /**
     * Getter for rgt
     */
    public function getRgt(): ?int    {
        return $this->rgt;
    }

    /**
     * Setter for rgt
     */
    public function setRgt(?int $rgt): static
    {
        $this->rgt = $rgt;
        return $this;
    }

    /**
     * Getter for inheritMembers
     */
    public function getInheritMembers(): bool
    {
        return $this->inheritMembers;
    }

    /**
     * Setter for inheritMembers
     */
    public function setInheritMembers(bool $inheritMembers): static
    {
        $this->inheritMembers = $inheritMembers;
        return $this;
    }

    /**
     * Getter for defaultVersion
     */
    public function getDefaultVersion(): ?Version
    {
        return $this->defaultVersion;
    }

    /**
     * Setter for defaultVersion
     */
    public function setDefaultVersion(?Version $defaultVersion): static
    {
        $this->defaultVersion = $defaultVersion;
        return $this;
    }

    /**
     * Getter for defaultAssignedTo
     */
    public function getDefaultAssignedTo(): ?User
    {
        return $this->defaultAssignedTo;
    }

    /**
     * Setter for defaultAssignedTo
     */
    public function setDefaultAssignedTo(?User $defaultAssignedTo): static
    {
        $this->defaultAssignedTo = $defaultAssignedTo;
        return $this;
    }

    /**
     * Getter for defaultIssueQuery
     */
    public function getDefaultIssueQuery(): ?Query
    {
        return $this->defaultIssueQuery;
    }

    /**
     * Setter for defaultIssueQuery
     */
    public function setDefaultIssueQuery(?Query $defaultIssueQuery): static
    {
        $this->defaultIssueQuery = $defaultIssueQuery;
        return $this;
    }

}

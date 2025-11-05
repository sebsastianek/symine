<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\IssueRepository;

/**
 * Issue.
 * Table: issues
 */
#[ORM\Entity(repositoryClass: IssueRepository::class)]
#[ORM\Table(name: 'issues')]
class Issue
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property tracker
     */
    #[ORM\ManyToOne(targetEntity: Tracker::class)]
    #[ORM\JoinColumn(name: 'tracker_id', referencedColumnName: 'id', nullable: false)]
    private Tracker $tracker;

    /**
     * Property project
     */
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false)]
    private Project $project;

    /**
     * Property subject
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $subject = '';

    /**
     * Property description
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = NULL;

    /**
     * Property dueDate
     */
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $dueDate = NULL;

    /**
     * Property category
     */
    #[ORM\ManyToOne(targetEntity: IssueCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true)]
    private ?IssueCategory $category = null;

    /**
     * Property status
     */
    #[ORM\ManyToOne(targetEntity: IssueStatus::class)]
    #[ORM\JoinColumn(name: 'status_id', referencedColumnName: 'id', nullable: false)]
    private IssueStatus $status;

    /**
     * Property assignedTo
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_to_id', referencedColumnName: 'id', nullable: true)]
    private ?User $assignedTo = null;

    /**
     * Property priority
     */
    #[ORM\ManyToOne(targetEntity: Enumeration::class)]
    #[ORM\JoinColumn(name: 'priority_id', referencedColumnName: 'id', nullable: false)]
    private Enumeration $priority;

    /**
     * Property fixedVersion
     */
    #[ORM\ManyToOne(targetEntity: Version::class)]
    #[ORM\JoinColumn(name: 'fixed_version_id', referencedColumnName: 'id', nullable: true)]
    private ?Version $fixedVersion = null;

    /**
     * Property author
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false)]
    private User $author;

    /**
     * Property lockVersion
     */
    #[ORM\Column(type: 'integer', options: ['default' => '0'])]
    private int $lockVersion = 0;

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
     * Property startDate
     */
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $startDate = NULL;

    /**
     * Property doneRatio
     */
    #[ORM\Column(type: 'integer', options: ['default' => '0'])]
    private int $doneRatio = 0;

    /**
     * Property estimatedHours
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $estimatedHours = NULL;

    /**
     * Property parent
     */
    #[ORM\ManyToOne(targetEntity: Issue::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true)]
    private ?Issue $parent = null;

    /**
     * Property root
     */
    #[ORM\ManyToOne(targetEntity: Issue::class)]
    #[ORM\JoinColumn(name: 'root_id', referencedColumnName: 'id', nullable: true)]
    private ?Issue $root = null;

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
     * Property isPrivate
     */
    #[ORM\Column(type: 'boolean', options: ['default' => '0'])]
    private int $isPrivate = 0;

    /**
     * Property closedOn
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $closedOn = NULL;

    /**
     * Children issues (inverse side of parent relationship)
     */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: Issue::class)]
    private Collection $children;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }

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
     * Getter for tracker
     */
    public function getTracker(): Tracker
    {
        return $this->tracker;
    }

    /**
     * Setter for tracker
     */
    public function setTracker(Tracker $tracker): static
    {
        $this->tracker = $tracker;
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
     * Getter for subject
     */
    public function getSubject(): string    {
        return $this->subject;
    }

    /**
     * Setter for subject
     */
    public function setSubject(string $subject): static
    {
        $this->subject = $subject;
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
     * Getter for dueDate
     */
    public function getDueDate(): ?\DateTimeInterface    {
        return $this->dueDate;
    }

    /**
     * Setter for dueDate
     */
    public function setDueDate(?\DateTimeInterface $dueDate): static
    {
        $this->dueDate = $dueDate;
        return $this;
    }

    /**
     * Getter for category
     */
    public function getCategory(): ?IssueCategory
    {
        return $this->category;
    }

    /**
     * Setter for category
     */
    public function setCategory(?IssueCategory $category): static
    {
        $this->category = $category;
        return $this;
    }

    /**
     * Getter for status
     */
    public function getStatus(): IssueStatus
    {
        return $this->status;
    }

    /**
     * Setter for status
     */
    public function setStatus(IssueStatus $status): static
    {
        $this->status = $status;
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

    /**
     * Getter for priority
     */
    public function getPriority(): Enumeration
    {
        return $this->priority;
    }

    /**
     * Setter for priority
     */
    public function setPriority(Enumeration $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    /**
     * Getter for fixedVersion
     */
    public function getFixedVersion(): ?Version
    {
        return $this->fixedVersion;
    }

    /**
     * Setter for fixedVersion
     */
    public function setFixedVersion(?Version $fixedVersion): static
    {
        $this->fixedVersion = $fixedVersion;
        return $this;
    }

    /**
     * Getter for author
     */
    public function getAuthor(): User
    {
        return $this->author;
    }

    /**
     * Setter for author
     */
    public function setAuthor(User $author): static
    {
        $this->author = $author;
        return $this;
    }

    /**
     * Getter for lockVersion
     */
    public function getLockVersion(): int    {
        return $this->lockVersion;
    }

    /**
     * Setter for lockVersion
     */
    public function setLockVersion(int $lockVersion): static
    {
        $this->lockVersion = $lockVersion;
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
     * Getter for startDate
     */
    public function getStartDate(): ?\DateTimeInterface    {
        return $this->startDate;
    }

    /**
     * Setter for startDate
     */
    public function setStartDate(?\DateTimeInterface $startDate): static
    {
        $this->startDate = $startDate;
        return $this;
    }

    /**
     * Getter for doneRatio
     */
    public function getDoneRatio(): int    {
        return $this->doneRatio;
    }

    /**
     * Setter for doneRatio
     */
    public function setDoneRatio(int $doneRatio): static
    {
        $this->doneRatio = $doneRatio;
        return $this;
    }

    /**
     * Getter for estimatedHours
     */
    public function getEstimatedHours(): ?float    {
        return $this->estimatedHours;
    }

    /**
     * Setter for estimatedHours
     */
    public function setEstimatedHours(?float $estimatedHours): static
    {
        $this->estimatedHours = $estimatedHours;
        return $this;
    }

    /**
     * Getter for parent
     */
    public function getParent(): ?Issue
    {
        return $this->parent;
    }

    /**
     * Setter for parent
     */
    public function setParent(?Issue $parent): static
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * Getter for root
     */
    public function getRoot(): ?Issue
    {
        return $this->root;
    }

    /**
     * Setter for root
     */
    public function setRoot(?Issue $root): static
    {
        $this->root = $root;
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
     * Getter for isPrivate
     */
    public function getIsPrivate(): int    {
        return $this->isPrivate;
    }

    /**
     * Setter for isPrivate
     */
    public function setIsPrivate(int $isPrivate): static
    {
        $this->isPrivate = $isPrivate;
        return $this;
    }

    /**
     * Getter for closedOn
     */
    public function getClosedOn(): ?\DateTimeInterface    {
        return $this->closedOn;
    }

    /**
     * Setter for closedOn
     */
    public function setClosedOn(?\DateTimeInterface $closedOn): static
    {
        $this->closedOn = $closedOn;
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
     * Add child issue
     */
    public function addChild(Issue $child): static
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }
        return $this;
    }

    /**
     * Remove child issue
     */
    public function removeChild(Issue $child): static
    {
        if ($this->children->removeElement($child)) {
            if ($child->getParent() === $this) {
                $child->setParent(null);
            }
        }
        return $this;
    }

}

<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\WorkflowRepository;

/**
 * Workflow.
 * Table: workflows
 */
#[ORM\Entity(repositoryClass: WorkflowRepository::class)]
#[ORM\Table(name: 'workflows')]
class Workflow
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
     * Property oldStatus
     */
    #[ORM\ManyToOne(targetEntity: IssueStatuse::class)]
    #[ORM\JoinColumn(name: 'old_status_id', referencedColumnName: 'id', nullable: false)]
    private IssueStatuse $oldStatus;

    /**
     * Property newStatus
     */
    #[ORM\ManyToOne(targetEntity: IssueStatuse::class)]
    #[ORM\JoinColumn(name: 'new_status_id', referencedColumnName: 'id', nullable: false)]
    private IssueStatuse $newStatus;

    /**
     * Property role
     */
    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false)]
    private Role $role;

    /**
     * Property assignee
     */
    #[ORM\Column(type: 'boolean', options: ['default' => '0'])]
    private int $assignee = 0;

    /**
     * Property author
     */
    #[ORM\Column(type: 'boolean', options: ['default' => '0'])]
    private int $author = 0;

    /**
     * Property type
     */
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $type = NULL;

    /**
     * Property fieldName
     */
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $fieldName = NULL;

    /**
     * Property rule
     */
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $rule = NULL;

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
     * Getter for oldStatus
     */
    public function getOldStatus(): IssueStatuse
    {
        return $this->oldStatus;
    }

    /**
     * Setter for oldStatus
     */
    public function setOldStatus(IssueStatuse $oldStatus): static
    {
        $this->oldStatus = $oldStatus;
        return $this;
    }

    /**
     * Getter for newStatus
     */
    public function getNewStatus(): IssueStatuse
    {
        return $this->newStatus;
    }

    /**
     * Setter for newStatus
     */
    public function setNewStatus(IssueStatuse $newStatus): static
    {
        $this->newStatus = $newStatus;
        return $this;
    }

    /**
     * Getter for role
     */
    public function getRole(): Role
    {
        return $this->role;
    }

    /**
     * Setter for role
     */
    public function setRole(Role $role): static
    {
        $this->role = $role;
        return $this;
    }

    /**
     * Getter for assignee
     */
    public function getAssignee(): int    {
        return $this->assignee;
    }

    /**
     * Setter for assignee
     */
    public function setAssignee(int $assignee): static
    {
        $this->assignee = $assignee;
        return $this;
    }

    /**
     * Getter for author
     */
    public function getAuthor(): int    {
        return $this->author;
    }

    /**
     * Setter for author
     */
    public function setAuthor(int $author): static
    {
        $this->author = $author;
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
     * Getter for fieldName
     */
    public function getFieldName(): ?string    {
        return $this->fieldName;
    }

    /**
     * Setter for fieldName
     */
    public function setFieldName(?string $fieldName): static
    {
        $this->fieldName = $fieldName;
        return $this;
    }

    /**
     * Getter for rule
     */
    public function getRule(): ?string    {
        return $this->rule;
    }

    /**
     * Setter for rule
     */
    public function setRule(?string $rule): static
    {
        $this->rule = $rule;
        return $this;
    }

}

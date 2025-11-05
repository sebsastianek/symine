<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\JournalRepository;

/**
 * Journal.
 * Table: journals
 */
#[ORM\Entity(repositoryClass: JournalRepository::class)]
#[ORM\Table(name: 'journals')]
class Journal
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property journalized - polymorphic relation to journalized entity
     * NOTE: This is a polymorphic relation - the target entity depends on journalizedType
     * Most commonly points to Issue, but could be other entities based on journalizedType
     * TODO: Types to be adjusted later on, based on original
     */
    private Issue | Project $journalized;

    #[ORM\Column(name: 'journalized_id', type: 'integer', nullable: true)]
    private int $journalizedId;

    /**
     * Property journalizedType
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => ''])]
    private string $journalizedType = '';

    /**
     * Property user - the user who created this journal entry
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    /**
     * Property notes
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = NULL;

    /**
     * Property createdOn
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdOn;

    /**
     * Property updatedOn
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedOn = NULL;

    /**
     * Property updatedBy - the user who last updated this journal entry
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', nullable: true)]
    private ?User $updatedBy = null;

    /**
     * Property privateNotes
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $privateNotes = false;

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
     * Getter for journalized
     */
    public function getJournalized(): Issue
    {
        return $this->journalized;
    }

    /**
     * Setter for journalized
     */
    public function setJournalized(Issue $journalized): static
    {
        $this->journalized = $journalized;
        return $this;
    }

    /**
     * Getter for journalizedType
     */
    public function getJournalizedType(): string    {
        return $this->journalizedType;
    }

    /**
     * Setter for journalizedType
     */
    public function setJournalizedType(string $journalizedType): static
    {
        $this->journalizedType = $journalizedType;
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
     * Getter for notes
     */
    public function getNotes(): ?string    {
        return $this->notes;
    }

    /**
     * Setter for notes
     */
    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    /**
     * Getter for createdOn
     */
    public function getCreatedOn(): \DateTimeInterface    {
        return $this->createdOn;
    }

    /**
     * Setter for createdOn
     */
    public function setCreatedOn(\DateTimeInterface $createdOn): static
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
     * Getter for updatedBy
     */
    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    /**
     * Setter for updatedBy
     */
    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;
        return $this;
    }

    /**
     * Getter for privateNotes
     */
    public function getPrivateNotes(): bool
    {
        return $this->privateNotes;
    }

    /**
     * Setter for privateNotes
     */
    public function setPrivateNotes(bool $privateNotes): static
    {
        $this->privateNotes = $privateNotes;
        return $this;
    }

}

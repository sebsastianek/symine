<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\ReactionRepository;

/**
 * Reaction.
 * Table: reactions
 */
#[ORM\Entity(repositoryClass: ReactionRepository::class)]
#[ORM\Table(name: 'reactions')]
class Reaction
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', nullable: false)]
    private int $id;

    /**
     * Property reactableType
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $reactableType;

    /**
     * Property reactableId
     */
    #[ORM\Column(type: 'bigint')]
    private int $reactableId;

    /**
     * Property user
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    /**
     * Property reactionType
     */
    #[ORM\Column(type: 'string', length: 50)]
    private string $reactionType;

    /**
     * Property createdAt
     */
    #[ORM\Column(type: 'datetime', length: 6)]
    private \DateTimeInterface $createdAt;

    /**
     * Property updatedAt
     */
    #[ORM\Column(type: 'datetime', length: 6)]
    private \DateTimeInterface $updatedAt;

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
     * Getter for reactableType
     */
    public function getReactableType(): string    {
        return $this->reactableType;
    }

    /**
     * Setter for reactableType
     */
    public function setReactableType(string $reactableType): static
    {
        $this->reactableType = $reactableType;
        return $this;
    }

    /**
     * Getter for reactableId
     */
    public function getReactableId(): int    {
        return $this->reactableId;
    }

    /**
     * Setter for reactableId
     */
    public function setReactableId(int $reactableId): static
    {
        $this->reactableId = $reactableId;
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
     * Getter for reactionType
     */
    public function getReactionType(): string
    {
        return $this->reactionType;
    }

    /**
     * Setter for reactionType
     */
    public function setReactionType(string $reactionType): static
    {
        $this->reactionType = $reactionType;
        return $this;
    }

    /**
     * Getter for createdAt
     */
    public function getCreatedAt(): \DateTimeInterface    {
        return $this->createdAt;
    }

    /**
     * Setter for createdAt
     */
    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * Getter for updatedAt
     */
    public function getUpdatedAt(): \DateTimeInterface    {
        return $this->updatedAt;
    }

    /**
     * Setter for updatedAt
     */
    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

}

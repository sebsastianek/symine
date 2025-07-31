<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\ImportRepository;

/**
 * Import.
 * Table: imports
 */
#[ORM\Entity(repositoryClass: ImportRepository::class)]
#[ORM\Table(name: 'imports')]
class Import
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
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $type = NULL;

    /**
     * Property user
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    /**
     * Property filename
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $filename = NULL;

    /**
     * Property settings
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $settings = NULL;

    /**
     * Property totalItems
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $totalItems = NULL;

    /**
     * Property finished
     */
    #[ORM\Column(type: 'boolean', options: ['default' => '0'])]
    private int $finished = 0;

    /**
     * Property createdAt
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    /**
     * Property updatedAt
     */
    #[ORM\Column(type: 'datetime')]
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
     * Getter for filename
     */
    public function getFilename(): ?string    {
        return $this->filename;
    }

    /**
     * Setter for filename
     */
    public function setFilename(?string $filename): static
    {
        $this->filename = $filename;
        return $this;
    }

    /**
     * Getter for settings
     */
    public function getSettings(): ?string    {
        return $this->settings;
    }

    /**
     * Setter for settings
     */
    public function setSettings(?string $settings): static
    {
        $this->settings = $settings;
        return $this;
    }

    /**
     * Getter for totalItems
     */
    public function getTotalItems(): ?int    {
        return $this->totalItems;
    }

    /**
     * Setter for totalItems
     */
    public function setTotalItems(?int $totalItems): static
    {
        $this->totalItems = $totalItems;
        return $this;
    }

    /**
     * Getter for finished
     */
    public function getFinished(): int    {
        return $this->finished;
    }

    /**
     * Setter for finished
     */
    public function setFinished(int $finished): static
    {
        $this->finished = $finished;
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

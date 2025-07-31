<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\WatcherRepository;

/**
 * Watcher.
 * Table: watchers
 */
#[ORM\Entity(repositoryClass: WatcherRepository::class)]
#[ORM\Table(name: 'watchers')]
class Watcher
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property watchableType
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $watchableType = '';

    /**
     * Property watchableId
     */
    #[ORM\Column(type: 'integer', options: ['default' => '0'])]
    private int $watchableId = 0;

    /**
     * Property user
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
    private ?User $user = null;

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
     * Getter for watchableType
     */
    public function getWatchableType(): string    {
        return $this->watchableType;
    }

    /**
     * Setter for watchableType
     */
    public function setWatchableType(string $watchableType): static
    {
        $this->watchableType = $watchableType;
        return $this;
    }

    /**
     * Getter for watchableId
     */
    public function getWatchableId(): int    {
        return $this->watchableId;
    }

    /**
     * Setter for watchableId
     */
    public function setWatchableId(int $watchableId): static
    {
        $this->watchableId = $watchableId;
        return $this;
    }

    /**
     * Getter for user
     */
    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * Setter for user
     */
    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

}

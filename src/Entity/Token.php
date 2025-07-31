<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\TokenRepository;

/**
 * Token.
 * Table: tokens
 */
#[ORM\Entity(repositoryClass: TokenRepository::class)]
#[ORM\Table(name: 'tokens')]
class Token
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property user
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    /**
     * Property action
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => ''])]
    private string $action = '';

    /**
     * Property value
     */
    #[ORM\Column(type: 'string', length: 40, options: ['default' => ''])]
    private string $value = '';

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
     * Getter for action
     */
    public function getAction(): string    {
        return $this->action;
    }

    /**
     * Setter for action
     */
    public function setAction(string $action): static
    {
        $this->action = $action;
        return $this;
    }

    /**
     * Getter for value
     */
    public function getValue(): string    {
        return $this->value;
    }

    /**
     * Setter for value
     */
    public function setValue(string $value): static
    {
        $this->value = $value;
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

}

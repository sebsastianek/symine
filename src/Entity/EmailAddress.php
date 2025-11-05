<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\EmailAddressRepository;

/**
 * EmailAddress.
 * Table: email_addresses
 */
#[ORM\Entity(repositoryClass: EmailAddressRepository::class)]
#[ORM\Table(name: 'email_addresses')]
class EmailAddress
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
     * Property address
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $address;

    /**
     * Property isDefault
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isDefault = false;

    /**
     * Property notify
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $notify = true;

    /**
     * Property createdOn
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdOn;

    /**
     * Property updatedOn
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedOn;

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
     * Getter for address
     */
    public function getAddress(): string    {
        return $this->address;
    }

    /**
     * Setter for address
     */
    public function setAddress(string $address): static
    {
        $this->address = $address;
        return $this;
    }

    /**
     * Getter for isDefault
     */
    public function getIsDefault(): bool
    {
        return $this->isDefault;
    }

    /**
     * Setter for isDefault
     */
    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;
        return $this;
    }

    /**
     * Getter for notify
     */
    public function getNotify(): bool
    {
        return $this->notify;
    }

    /**
     * Setter for notify
     */
    public function setNotify(bool $notify): static
    {
        $this->notify = $notify;
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
    public function getUpdatedOn(): \DateTimeInterface    {
        return $this->updatedOn;
    }

    /**
     * Setter for updatedOn
     */
    public function setUpdatedOn(\DateTimeInterface $updatedOn): static
    {
        $this->updatedOn = $updatedOn;
        return $this;
    }

}

<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * Principal - Base class for User and Group entities
 *
 * Implements Single Table Inheritance (STI) pattern
 * Table: users (shared between User and Group)
 */
#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string', length: 255)]
#[ORM\DiscriminatorMap([
    'User' => User::class,
    'Group' => Group::class,
    'GroupAnonymous' => GroupAnonymous::class,
    'GroupNonMember' => GroupNonMember::class
])]
abstract class Principal
{
    public const STATUS_ACTIVE = 1;
    public const STATUS_REGISTERED = 2;
    public const STATUS_LOCKED = 3;
    public const STATUS_ANONYMOUS = 0;

    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    protected int $id;

    /**
     * Property login
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    protected string $login = '';

    /**
     * Property firstname
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => ''])]
    protected string $firstname = '';

    /**
     * Property lastname
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    protected string $lastname = '';

    /**
     * Property admin
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    protected bool $admin = false;

    /**
     * Property status
     */
    #[ORM\Column(type: 'integer', options: ['default' => '1'])]
    protected int $status = 1;

    /**
     * Property lastLoginOn
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $lastLoginOn = null;

    /**
     * Property language
     */
    #[ORM\Column(type: 'string', length: 5, nullable: true, options: ['default' => ''])]
    protected ?string $language = '';

    /**
     * Property createdOn
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $createdOn = null;

    /**
     * Property updatedOn
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $updatedOn = null;


    /**
     * Property authSourceId
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $authSourceId = null;

    /**
     * Property twofa_scheme (for 2FA)
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $twofaScheme = null;

    /**
     * Property twofa_totp_key (for 2FA)
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $twofaTotpKey = null;

    /**
     * Property twofa_totp_last_used_at (for 2FA)
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $twofaTotpLastUsedAt = null;

    public function __construct()
    {
        $this->createdOn = new \DateTime();
        $this->updatedOn = new \DateTime();
    }

    // Getters and Setters

    public function getId(): int
    {
        return $this->id;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function setLogin(string $login): self
    {
        $this->login = $login;
        return $this;
    }

    public function getFirstname(): string
    {
        return $this->firstname;
    }

    public function setFirstname(string $firstname): self
    {
        $this->firstname = $firstname;
        return $this;
    }

    public function getLastname(): string
    {
        return $this->lastname;
    }

    public function setLastname(string $lastname): self
    {
        $this->lastname = $lastname;
        return $this;
    }

    public function getName(): string
    {
        if (!empty($this->firstname) || !empty($this->lastname)) {
            return trim($this->firstname . ' ' . $this->lastname);
        }
        return $this->login;
    }

    public function getAdmin(): bool
    {
        return $this->admin;
    }

    public function setAdmin(bool $admin): self
    {
        $this->admin = $admin;
        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getLastLoginOn(): ?\DateTimeInterface
    {
        return $this->lastLoginOn;
    }

    public function setLastLoginOn(?\DateTimeInterface $lastLoginOn): self
    {
        $this->lastLoginOn = $lastLoginOn;
        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): self
    {
        $this->language = $language;
        return $this;
    }

    public function getCreatedOn(): ?\DateTimeInterface
    {
        return $this->createdOn;
    }

    public function setCreatedOn(?\DateTimeInterface $createdOn): self
    {
        $this->createdOn = $createdOn;
        return $this;
    }

    public function getUpdatedOn(): ?\DateTimeInterface
    {
        return $this->updatedOn;
    }

    public function setUpdatedOn(?\DateTimeInterface $updatedOn): self
    {
        $this->updatedOn = $updatedOn;
        return $this;
    }


    public function getAuthSourceId(): ?int
    {
        return $this->authSourceId;
    }

    public function setAuthSourceId(?int $authSourceId): self
    {
        $this->authSourceId = $authSourceId;
        return $this;
    }

    public function getTwofaScheme(): ?string
    {
        return $this->twofaScheme;
    }

    public function setTwofaScheme(?string $twofaScheme): self
    {
        $this->twofaScheme = $twofaScheme;
        return $this;
    }

    public function getTwofaTotpKey(): ?string
    {
        return $this->twofaTotpKey;
    }

    public function setTwofaTotpKey(?string $twofaTotpKey): self
    {
        $this->twofaTotpKey = $twofaTotpKey;
        return $this;
    }

    public function getTwofaTotpLastUsedAt(): ?int
    {
        return $this->twofaTotpLastUsedAt;
    }

    public function setTwofaTotpLastUsedAt(?int $twofaTotpLastUsedAt): self
    {
        $this->twofaTotpLastUsedAt = $twofaTotpLastUsedAt;
        return $this;
    }

    /**
     * Check if this principal is a user
     */
    public function isUser(): bool
    {
        return $this instanceof User;
    }

    /**
     * Check if this principal is a group
     */
    public function isGroup(): bool
    {
        return $this instanceof Group;
    }

    /**
     * Check if this principal is a built-in group
     */
    public function isBuiltinGroup(): bool
    {
        return $this instanceof GroupAnonymous || $this instanceof GroupNonMember;
    }

    /**
     * Get display string for this principal
     */
    public function __toString(): string
    {
        return $this->getName();
    }
}

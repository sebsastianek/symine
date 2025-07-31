<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * User.
 * Table: users
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const STATUS_ACTIVE = 1;
    public const STATUS_REGISTERED = 2;
    public const STATUS_LOCKED = 3;
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property login
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $login = '';

    /**
     * Property hashedPassword
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $hashedPassword = '';

    /**
     * Property firstname
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => ''])]
    private string $firstname = '';

    /**
     * Property lastname
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $lastname = '';

    /**
     * Property admin
     */
    #[ORM\Column(type: 'boolean', options: ['default' => '0'])]
    private int $admin = 0;

    /**
     * Property status
     */
    #[ORM\Column(type: 'integer', options: ['default' => '1'])]
    private int $status = 1;

    /**
     * Property lastLoginOn
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastLoginOn = NULL;

    /**
     * Property language
     */
    #[ORM\Column(type: 'string', length: 5, nullable: true, options: ['default' => ''])]
    private ?string $language = '';

    /**
     * Property authSource
     */
    #[ORM\ManyToOne(targetEntity: AuthSource::class)]
    #[ORM\JoinColumn(name: 'auth_source_id', referencedColumnName: 'id', nullable: true)]
    private ?AuthSource $authSource = null;

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
     * Property type
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $type = NULL;

    /**
     * Property mailNotification
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $mailNotification = '';

    /**
     * Property salt
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $salt = NULL;

    /**
     * Property mustChangePasswd
     */
    #[ORM\Column(type: 'boolean', options: ['default' => '0'])]
    private int $mustChangePasswd = 0;

    /**
     * Property passwdChangedOn
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $passwdChangedOn = NULL;

    /**
     * Property twofaScheme
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $twofaScheme = NULL;

    /**
     * Property twofaTotpKey
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $twofaTotpKey = NULL;

    /**
     * Property twofaTotpLastUsedAt
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $twofaTotpLastUsedAt = NULL;

    /**
     * Property twofaRequired
     */
    #[ORM\Column(type: 'boolean', nullable: true, options: ['default' => '0'])]
    private ?int $twofaRequired = 0;

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
     * Getter for login
     */
    public function getLogin(): string    {
        return $this->login;
    }

    /**
     * Setter for login
     */
    public function setLogin(string $login): static
    {
        $this->login = $login;
        return $this;
    }

    /**
     * Getter for hashedPassword
     */
    public function getHashedPassword(): string    {
        return $this->hashedPassword;
    }

    /**
     * Setter for hashedPassword
     */
    public function setHashedPassword(string $hashedPassword): static
    {
        $this->hashedPassword = $hashedPassword;
        return $this;
    }

    /**
     * Getter for firstname
     */
    public function getFirstname(): string    {
        return $this->firstname;
    }

    /**
     * Setter for firstname
     */
    public function setFirstname(string $firstname): static
    {
        $this->firstname = $firstname;
        return $this;
    }

    /**
     * Getter for lastname
     */
    public function getLastname(): string    {
        return $this->lastname;
    }

    /**
     * Setter for lastname
     */
    public function setLastname(string $lastname): static
    {
        $this->lastname = $lastname;
        return $this;
    }

    /**
     * Getter for admin
     */
    public function getAdmin(): int    {
        return $this->admin;
    }

    /**
     * Setter for admin
     */
    public function setAdmin(int $admin): static
    {
        $this->admin = $admin;
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
     * Getter for lastLoginOn
     */
    public function getLastLoginOn(): ?\DateTimeInterface    {
        return $this->lastLoginOn;
    }

    /**
     * Setter for lastLoginOn
     */
    public function setLastLoginOn(?\DateTimeInterface $lastLoginOn): static
    {
        $this->lastLoginOn = $lastLoginOn;
        return $this;
    }

    /**
     * Getter for language
     */
    public function getLanguage(): ?string    {
        return $this->language;
    }

    /**
     * Setter for language
     */
    public function setLanguage(?string $language): static
    {
        $this->language = $language;
        return $this;
    }

    /**
     * Getter for authSource
     */
    public function getAuthSource(): ?AuthSource    {
        return $this->authSource;
    }

    /**
     * Setter for authSource
     */
    public function setAuthSource(?AuthSource $authSource): static
    {
        $this->authSource = $authSource;
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
     * Getter for mailNotification
     */
    public function getMailNotification(): string    {
        return $this->mailNotification;
    }

    /**
     * Setter for mailNotification
     */
    public function setMailNotification(string $mailNotification): static
    {
        $this->mailNotification = $mailNotification;
        return $this;
    }

    /**
     * Getter for salt (database field)
     */
    public function getDatabaseSalt(): ?string    {
        return $this->salt;
    }

    /**
     * Setter for salt
     */
    public function setSalt(?string $salt): static
    {
        $this->salt = $salt;
        return $this;
    }

    /**
     * Getter for mustChangePasswd
     */
    public function getMustChangePasswd(): int    {
        return $this->mustChangePasswd;
    }

    /**
     * Setter for mustChangePasswd
     */
    public function setMustChangePasswd(int $mustChangePasswd): static
    {
        $this->mustChangePasswd = $mustChangePasswd;
        return $this;
    }

    /**
     * Getter for passwdChangedOn
     */
    public function getPasswdChangedOn(): ?\DateTimeInterface    {
        return $this->passwdChangedOn;
    }

    /**
     * Setter for passwdChangedOn
     */
    public function setPasswdChangedOn(?\DateTimeInterface $passwdChangedOn): static
    {
        $this->passwdChangedOn = $passwdChangedOn;
        return $this;
    }

    /**
     * Getter for twofaScheme
     */
    public function getTwofaScheme(): ?string    {
        return $this->twofaScheme;
    }

    /**
     * Setter for twofaScheme
     */
    public function setTwofaScheme(?string $twofaScheme): static
    {
        $this->twofaScheme = $twofaScheme;
        return $this;
    }

    /**
     * Getter for twofaTotpKey
     */
    public function getTwofaTotpKey(): ?string    {
        return $this->twofaTotpKey;
    }

    /**
     * Setter for twofaTotpKey
     */
    public function setTwofaTotpKey(?string $twofaTotpKey): static
    {
        $this->twofaTotpKey = $twofaTotpKey;
        return $this;
    }

    /**
     * Getter for twofaTotpLastUsedAt
     */
    public function getTwofaTotpLastUsedAt(): ?int    {
        return $this->twofaTotpLastUsedAt;
    }

    /**
     * Setter for twofaTotpLastUsedAt
     */
    public function setTwofaTotpLastUsedAt(?int $twofaTotpLastUsedAt): static
    {
        $this->twofaTotpLastUsedAt = $twofaTotpLastUsedAt;
        return $this;
    }

    /**
     * Getter for twofaRequired
     */
    public function getTwofaRequired(): ?int    {
        return $this->twofaRequired;
    }

    /**
     * Setter for twofaRequired
     */
    public function setTwofaRequired(?int $twofaRequired): static
    {
        $this->twofaRequired = $twofaRequired;
        return $this;
    }

    // UserInterface implementation

    public function getUserIdentifier(): string
    {
        return $this->login;
    }

    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];

        if ($this->admin) {
            $roles[] = 'ROLE_ADMIN';
        }

        return $roles;
    }

    public function getPassword(): string
    {
        return $this->hashedPassword;
    }

    public function getSalt(): ?string
    {
        // Redmine uses SHA1 with salt, but for Symfony compatibility
        // we'll handle this in a custom password encoder
        return null;
    }

    public function eraseCredentials(): void
    {
        // No sensitive data to erase
    }

    // Convenience methods for security

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }

    public function getFullName(): string
    {
        return trim($this->firstname . ' ' . $this->lastname);
    }

}

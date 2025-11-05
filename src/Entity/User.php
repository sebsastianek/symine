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
class User extends Principal implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const STATUS_ACTIVE = 1;
    public const STATUS_REGISTERED = 2;
    public const STATUS_LOCKED = 3;

    /**
     * Property hashedPassword
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $hashedPassword = '';

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

    public function setPassword(string $hashedPassword): void {
        $this->hashedPassword = $hashedPassword;
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

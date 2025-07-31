<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Anonymous User class for handling non-authenticated users
 * 
 * This is a special user that represents anonymous/guest access
 * It implements UserInterface but has limited permissions
 */
class AnonymousUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    private const ANONYMOUS_IDENTIFIER = '__anonymous_user__';

    public function getUserIdentifier(): string
    {
        return self::ANONYMOUS_IDENTIFIER;
    }

    public function getRoles(): array
    {
        return ['ROLE_ANONYMOUS'];
    }

    public function getPassword(): ?string
    {
        return null;
    }

    public function getSalt(): ?string
    {
        return null;
    }

    public function eraseCredentials(): void
    {
        // Nothing to erase for anonymous user
    }

    public function getUsername(): string
    {
        return self::ANONYMOUS_IDENTIFIER;
    }

    /**
     * Check if user is logged in (always false for anonymous)
     */
    public function isLogged(): bool
    {
        return false;
    }

    /**
     * Check if user is admin (always false for anonymous)
     */
    public function isAdmin(): bool
    {
        return false;
    }

    /**
     * Get display name for anonymous user
     */
    public function getName(): string
    {
        return 'Anonymous';
    }

    /**
     * Get email (always null for anonymous)
     */
    public function getEmail(): ?string
    {
        return null;
    }

    /**
     * Check if this is anonymous user
     */
    public function isAnonymous(): bool
    {
        return true;
    }

    /**
     * Get user ID (always null for anonymous)
     */
    public function getId(): ?int
    {
        return null;
    }

    /**
     * Get user status (always anonymous status)
     */
    public function getStatus(): int
    {
        return User::STATUS_ANONYMOUS;
    }

    /**
     * Check if user can be member of projects (always false)
     */
    public function isMemberOf(Project $project): bool
    {
        return false;
    }

    /**
     * Get memberships (always empty for anonymous)
     */
    public function getMemberships(): array
    {
        return [];
    }

    /**
     * Anonymous users cannot be destroyed
     */
    public function canBeDestroyed(): bool
    {
        return false;
    }

    /**
     * Get the built-in anonymous role
     */
    public function getBuiltinRole(): ?Role
    {
        // This would typically be fetched from the database
        // For now, return null and handle in services
        return null;
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return $this->getName();
    }
}
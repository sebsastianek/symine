<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * GroupAnonymous - Built-in group for anonymous users
 * 
 * This group represents non-authenticated users and provides
 * default permissions for public access
 */
#[ORM\Entity]
class GroupAnonymous extends Group
{
    public const BUILTIN_ID = 1;

    public function __construct()
    {
        parent::__construct();
        $this->setType('GroupAnonymous');
        $this->setLogin('anonymous');
        $this->setLastname('Anonymous');
        $this->setStatus(self::STATUS_ACTIVE);
    }

    /**
     * This is a built-in group
     */
    public function isBuiltin(): bool
    {
        return true;
    }

    /**
     * Get built-in status
     */
    public function getBuiltin(): int
    {
        return self::BUILTIN_ID;
    }

    /**
     * Anonymous group cannot have users added manually
     */
    public function addUser(User $user): self
    {
        // Cannot add users to anonymous group
        return $this;
    }

    /**
     * Anonymous group cannot have users removed manually
     */
    public function removeUser(User $user): self
    {
        // Cannot remove users from anonymous group
        return $this;
    }

    /**
     * Anonymous group always has 0 users (conceptually)
     */
    public function getUserCount(): int
    {
        return 0;
    }

    /**
     * Anonymous group cannot be deleted
     */
    public function canBeDeleted(): bool
    {
        return false;
    }

    /**
     * Get display name
     */
    public function getName(): string
    {
        return 'Anonymous';
    }

    /**
     * Get description
     */
    public function getDescription(): string
    {
        return 'Built-in group for anonymous (non-authenticated) users';
    }

    /**
     * Check if anonymous group has user (always false)
     */
    public function hasUser(User $user): bool
    {
        return false;
    }

    /**
     * Anonymous group represents all non-authenticated access
     */
    public function representsUser(?User $user): bool
    {
        return $user === null;
    }
}
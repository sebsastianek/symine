<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * GroupNonMember - Built-in group for authenticated non-members
 *
 * This group represents authenticated users who are not explicitly
 * assigned to projects but can access public projects
 */
#[ORM\Entity]
class GroupNonMember extends Group
{
    public const BUILTIN_ID = 2;

    public function __construct()
    {
        parent::__construct();
        $this->setType('GroupNonMember');
        $this->setLogin('non_member');
        $this->setLastname('Non member');
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
     * Non-member group cannot have users added manually
     */
    public function addUser(User $user): self
    {
        // Cannot add users to non-member group
        return $this;
    }

    /**
     * Non-member group cannot have users removed manually
     */
    public function removeUser(User $user): self
    {
        // Cannot remove users from non-member group
        return $this;
    }

    /**
     * Non-member group represents all authenticated users conceptually
     */
    public function getUserCount(): int
    {
        return 0; // Conceptual group - not tied to specific users
    }

    /**
     * Non-member group cannot be deleted
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
        return 'Non member';
    }

    /**
     * Get description
     */
    public function getDescription(): string
    {
        return 'Built-in group for authenticated users not explicitly assigned to projects';
    }

    /**
     * Check if non-member group has user
     */
    public function hasUser(User $user): bool
    {
        return false; // Conceptual group
    }

    /**
     * Non-member group represents authenticated users accessing public projects
     */
    public function representsUser(?User $user, ?Project $project = null): bool
    {
        if (!$user) {
            return false; // Anonymous users don't belong to non-member group
        }

        if (!$project) {
            return false; // Non-member is only relevant in project context
        }

        // Check if user is not explicitly a member of the project
        // This would need to check membership, but for now return true for authenticated users
        return $user->isActive();
    }
}

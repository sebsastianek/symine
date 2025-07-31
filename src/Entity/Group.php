<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use App\Repository\GroupRepository;

/**
 * Group entity
 * 
 * Represents a group of users with shared permissions
 * Extends Principal using Single Table Inheritance
 */
#[ORM\Entity(repositoryClass: GroupRepository::class)]
class Group extends Principal
{
    /**
     * GroupsUser entities for this group
     */
    #[ORM\OneToMany(targetEntity: GroupsUser::class, mappedBy: 'group')]
    private Collection $groupsUsers;

    /**
     * Group memberships in projects
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Member::class)]
    private Collection $members;

    public function __construct()
    {
        parent::__construct();
        $this->groupsUsers = new ArrayCollection();
        $this->members = new ArrayCollection();
    }

    /**
     * Get users in this group
     */
    public function getUsers(): Collection
    {
        $users = new ArrayCollection();
        foreach ($this->groupsUsers as $groupsUser) {
            $users->add($groupsUser->getUser());
        }
        return $users;
    }

    /**
     * Get GroupsUser entities
     */
    public function getGroupsUsers(): Collection
    {
        return $this->groupsUsers;
    }

    /**
     * Add user to group
     */
    public function addUser(User $user): self
    {
        // Check if user is already in group
        foreach ($this->groupsUsers as $groupsUser) {
            if ($groupsUser->getUser()->getId() === $user->getId()) {
                return $this;
            }
        }

        // Create new GroupsUser entity
        $groupsUser = new GroupsUser();
        $groupsUser->setGroup($this);
        $groupsUser->setUser($user);
        $this->groupsUsers->add($groupsUser);

        return $this;
    }

    /**
     * Remove user from group
     */
    public function removeUser(User $user): self
    {
        foreach ($this->groupsUsers as $groupsUser) {
            if ($groupsUser->getUser()->getId() === $user->getId()) {
                $this->groupsUsers->removeElement($groupsUser);
                break;
            }
        }

        return $this;
    }

    /**
     * Check if user is in this group
     */
    public function hasUser(User $user): bool
    {
        foreach ($this->groupsUsers as $groupsUser) {
            if ($groupsUser->getUser()->getId() === $user->getId()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get group memberships
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    /**
     * Add member
     */
    public function addMember(Member $member): self
    {
        if (!$this->members->contains($member)) {
            $this->members[] = $member;
            $member->setUser($this);
        }

        return $this;
    }

    /**
     * Remove member
     */
    public function removeMember(Member $member): self
    {
        if ($this->members->removeElement($member)) {
            // set the owning side to null (unless already changed)
            if ($member->getUser() === $this) {
                $member->setUser(null);
            }
        }

        return $this;
    }

    /**
     * Get number of users in group
     */
    public function getUserCount(): int
    {
        return $this->users->count();
    }

    /**
     * Get projects this group has access to
     */
    public function getProjects(): array
    {
        $projects = [];
        foreach ($this->members as $member) {
            if ($member->getProject() && !in_array($member->getProject(), $projects, true)) {
                $projects[] = $member->getProject();
            }
        }
        return $projects;
    }

    /**
     * Check if group has access to a project
     */
    public function hasAccessToProject(Project $project): bool
    {
        foreach ($this->members as $member) {
            if ($member->getProject() === $project) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get group roles for a specific project
     */
    public function getRolesForProject(Project $project): array
    {
        $roles = [];
        foreach ($this->members as $member) {
            if ($member->getProject() === $project) {
                foreach ($member->getMemberRoles() as $memberRole) {
                    if (!in_array($memberRole->getRole(), $roles, true)) {
                        $roles[] = $memberRole->getRole();
                    }
                }
            }
        }
        return $roles;
    }

    /**
     * Check if this is a built-in group
     */
    public function isBuiltin(): bool
    {
        return false; // Regular groups are not built-in
    }

    /**
     * Get built-in status
     */
    public function getBuiltin(): ?int
    {
        return null; // Regular groups don't have builtin status
    }

    /**
     * Groups cannot be admin
     */
    public function getAdmin(): bool
    {
        return false;
    }

    /**
     * Groups cannot be admin
     */
    public function setAdmin(bool $admin): self
    {
        // Groups cannot be admin - ignore this call
        return $this;
    }

    /**
     * Groups are always active if not explicitly set otherwise
     */
    public function getStatus(): int
    {
        return $this->status ?: self::STATUS_ACTIVE;
    }

    /**
     * Get display name for group
     */
    public function getName(): string
    {
        return $this->getLastname() ?: $this->getLogin();
    }

    /**
     * Get description for this group
     */
    public function getDescription(): string
    {
        return sprintf('Group: %s (%d users)', $this->getName(), $this->getUserCount());
    }

    /**
     * Check if group can be deleted
     */
    public function canBeDeleted(): bool
    {
        // Built-in groups cannot be deleted
        return !$this->isBuiltin();
    }

    /**
     * Get group statistics
     */
    public function getStats(): array
    {
        return [
            'user_count' => $this->getUserCount(),
            'project_count' => count($this->getProjects()),
            'member_count' => $this->members->count(),
            'is_builtin' => $this->isBuiltin(),
            'status' => $this->getStatus()
        ];
    }
}
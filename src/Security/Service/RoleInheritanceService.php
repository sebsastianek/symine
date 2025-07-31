<?php

declare(strict_types=1);

namespace App\Security\Service;

use App\Entity\User;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\Member;
use App\Entity\MemberRole;
use App\Repository\MemberRepository;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling role inheritance from groups to users
 * 
 * Implements Redmine's group-based permission inheritance system
 */
class RoleInheritanceService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MemberRepository $memberRepository,
        private RoleRepository $roleRepository,
        private UserRepository $userRepository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Get all roles for a user in a specific project (including inherited)
     */
    public function getUserRolesForProject(User $user, Project $project): array
    {
        // Admin users bypass role checking
        if ($user->getAdmin()) {
            return [];
        }

        $roles = [];

        // Get direct member roles
        $directRoles = $this->getDirectUserRoles($user, $project);
        $roles = array_merge($roles, $directRoles);

        // Get inherited roles from groups
        $inheritedRoles = $this->getInheritedUserRoles($user, $project);
        $roles = array_merge($roles, $inheritedRoles);

        // Add project inheritance from parent projects
        $parentRoles = $this->getParentProjectRoles($user, $project);
        $roles = array_merge($roles, $parentRoles);

        // Add built-in roles if user has no specific roles
        if (empty($roles) && $this->canAccessProject($user, $project)) {
            $builtinRoles = $this->getBuiltinRoles($user, $project);
            $roles = array_merge($roles, $builtinRoles);
        }

        // Remove duplicates and return
        return array_unique($roles, SORT_REGULAR);
    }

    /**
     * Get roles for a user across all projects (global permissions)
     */
    public function getUserGlobalRoles(User $user): array
    {
        if ($user->getAdmin()) {
            return [];
        }

        // For global permissions, only built-in roles apply
        $anonymousRole = $this->roleRepository->findOneBy(['builtin' => Role::BUILTIN_ANONYMOUS]);
        return $anonymousRole ? [$anonymousRole] : [];
    }

    /**
     * Add user to group and inherit all group roles
     */
    public function addUserToGroup(User $user, $group): void
    {
        try {
            $this->entityManager->beginTransaction();

            // Find all group memberships
            $groupMembers = $this->memberRepository->findBy(['user' => $group]);

            foreach ($groupMembers as $groupMember) {
                $this->inheritGroupMembershipForUser($user, $groupMember);
            }

            $this->entityManager->commit();
            
            $this->logger->info('User added to group with role inheritance', [
                'user_id' => $user->getId(),
                'group_id' => $group->getId(),
                'inherited_memberships' => count($groupMembers)
            ]);

        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to add user to group', [
                'user_id' => $user->getId(),
                'group_id' => $group->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Remove user from group and remove all inherited roles
     */
    public function removeUserFromGroup(User $user, $group): void
    {
        try {
            $this->entityManager->beginTransaction();

            // Find all inherited member roles from this group
            $inheritedMemberRoles = $this->entityManager->createQueryBuilder()
                ->select('mr')
                ->from(MemberRole::class, 'mr')
                ->join('mr.member', 'm')
                ->join('mr.inheritedFrom', 'source_mr')
                ->join('source_mr.member', 'source_m')
                ->where('m.user = :user')
                ->andWhere('source_m.user = :group')
                ->setParameter('user', $user)
                ->setParameter('group', $group)
                ->getQuery()
                ->getResult();

            foreach ($inheritedMemberRoles as $memberRole) {
                $this->entityManager->remove($memberRole);
            }

            // Clean up empty memberships
            $this->cleanupEmptyMemberships($user);

            $this->entityManager->commit();

            $this->logger->info('User removed from group with role cleanup', [
                'user_id' => $user->getId(),
                'group_id' => $group->getId(),
                'removed_roles' => count($inheritedMemberRoles)
            ]);

        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to remove user from group', [
                'user_id' => $user->getId(),
                'group_id' => $group->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update group role inheritance when group gets new project membership
     */
    public function updateGroupRoleInheritance($group, Member $groupMember): void
    {
        try {
            $this->entityManager->beginTransaction();

            // Find all users in this group
            $users = $this->getUsersInGroup($group);

            foreach ($users as $user) {
                $this->inheritGroupMembershipForUser($user, $groupMember);
            }

            $this->entityManager->commit();

            $this->logger->info('Group role inheritance updated', [
                'group_id' => $group->getId(),
                'project_id' => $groupMember->getProject()->getId(),
                'affected_users' => count($users)
            ]);

        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to update group role inheritance', [
                'group_id' => $group->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Check if user can access project through any means
     */
    public function canUserAccessProject(User $user, Project $project): bool
    {
        // Admin users can access all projects
        if ($user->getAdmin()) {
            return true;
        }

        // Check if project is public
        if ($project->getIsPublic()) {
            return true;
        }

        // Check if user has direct membership
        if ($this->hasDirectMembership($user, $project)) {
            return true;
        }

        // Check if user has inherited membership through groups
        if ($this->hasInheritedMembership($user, $project)) {
            return true;
        }

        // Check inheritance from parent projects
        return $this->hasParentProjectAccess($user, $project);
    }

    /**
     * Get direct user roles (not inherited)
     */
    private function getDirectUserRoles(User $user, Project $project): array
    {
        $member = $this->memberRepository->findOneBy([
            'user' => $user,
            'project' => $project
        ]);

        if (!$member) {
            return [];
        }

        $roles = [];
        foreach ($member->getMemberRoles() as $memberRole) {
            // Only direct roles (not inherited)
            if ($memberRole->getInheritedFrom() === null) {
                $roles[] = $memberRole->getRole();
            }
        }

        return $roles;
    }

    /**
     * Get inherited user roles from groups
     */
    private function getInheritedUserRoles(User $user, Project $project): array
    {
        $member = $this->memberRepository->findOneBy([
            'user' => $user,
            'project' => $project
        ]);

        if (!$member) {
            return [];
        }

        $roles = [];
        foreach ($member->getMemberRoles() as $memberRole) {
            // Only inherited roles
            if ($memberRole->getInheritedFrom() !== null) {
                $roles[] = $memberRole->getRole();
            }
        }

        return $roles;
    }

    /**
     * Get roles from parent projects (if inheritance enabled)
     */
    private function getParentProjectRoles(User $user, Project $project): array
    {
        $parent = $project->getParent();
        if (!$parent || !$project->getInheritMembers()) {
            return [];
        }

        return $this->getUserRolesForProject($user, $parent);
    }

    /**
     * Get built-in roles for user (Anonymous/Non-member)
     */
    private function getBuiltinRoles(User $user, Project $project): array
    {
        // Anonymous users get Anonymous role
        if (!$user->getId()) {
            $anonymousRole = $this->roleRepository->findOneBy(['builtin' => Role::BUILTIN_ANONYMOUS]);
            return $anonymousRole ? [$anonymousRole] : [];
        }

        // Authenticated users on public projects get Non-member role
        if ($project->getIsPublic()) {
            $nonMemberRole = $this->roleRepository->findOneBy(['builtin' => Role::BUILTIN_NON_MEMBER]);
            return $nonMemberRole ? [$nonMemberRole] : [];
        }

        return [];
    }

    /**
     * Inherit group membership for a specific user
     */
    private function inheritGroupMembershipForUser(User $user, Member $groupMember): void
    {
        // Find or create user membership for the same project
        $userMember = $this->memberRepository->findOneBy([
            'user' => $user,
            'project' => $groupMember->getProject()
        ]);

        if (!$userMember) {
            $userMember = new Member();
            $userMember->setUser($user);
            $userMember->setProject($groupMember->getProject());
            $userMember->setCreatedOn(new \DateTime());
            $this->entityManager->persist($userMember);
        }

        // Inherit all roles from group membership
        foreach ($groupMember->getMemberRoles() as $groupMemberRole) {
            // Check if this role is already inherited
            $existingInherited = null;
            foreach ($userMember->getMemberRoles() as $userMemberRole) {
                if ($userMemberRole->getInheritedFrom() === $groupMemberRole) {
                    $existingInherited = $userMemberRole;
                    break;
                }
            }

            if (!$existingInherited) {
                $inheritedMemberRole = new MemberRole();
                $inheritedMemberRole->setMember($userMember);
                $inheritedMemberRole->setRole($groupMemberRole->getRole());
                $inheritedMemberRole->setInheritedFrom($groupMemberRole);
                
                $this->entityManager->persist($inheritedMemberRole);
                $userMember->addMemberRole($inheritedMemberRole);
            }
        }
    }

    /**
     * Check if user has direct membership
     */
    private function hasDirectMembership(User $user, Project $project): bool
    {
        return $this->memberRepository->findOneBy([
            'user' => $user,
            'project' => $project
        ]) !== null;
    }

    /**
     * Check if user has inherited membership through groups
     */
    private function hasInheritedMembership(User $user, Project $project): bool
    {
        $member = $this->memberRepository->findOneBy([
            'user' => $user,
            'project' => $project
        ]);

        if (!$member) {
            return false;
        }

        // Check if any member role is inherited
        foreach ($member->getMemberRoles() as $memberRole) {
            if ($memberRole->getInheritedFrom() !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check parent project access
     */
    private function hasParentProjectAccess(User $user, Project $project): bool
    {
        $parent = $project->getParent();
        if (!$parent || !$project->getInheritMembers()) {
            return false;
        }

        return $this->canUserAccessProject($user, $parent);
    }

    /**
     * Check basic project access
     */
    private function canAccessProject(User $user, Project $project): bool
    {
        return $project->getIsPublic() || $this->hasDirectMembership($user, $project);
    }

    /**
     * Get users in a group
     */
    private function getUsersInGroup($group): array
    {
        if ($group instanceof \App\Entity\Group) {
            return $group->getUsers()->toArray();
        }
        return [];
    }

    /**
     * Clean up memberships with no roles
     */
    private function cleanupEmptyMemberships(User $user): void
    {
        $emptyMembers = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(Member::class, 'm')
            ->leftJoin('m.memberRoles', 'mr')
            ->where('m.user = :user')
            ->having('COUNT(mr.id) = 0')
            ->groupBy('m.id')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        foreach ($emptyMembers as $member) {
            $this->entityManager->remove($member);
        }
    }
}
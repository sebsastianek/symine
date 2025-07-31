<?php

declare(strict_types=1);

namespace App\Security\Service;

use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\Member;
use App\Entity\MemberRole;
use App\Repository\MemberRepository;
use App\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;

class PermissionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MemberRepository $memberRepository,
        private RoleRepository $roleRepository
    ) {
    }
    
    /**
     * Get all roles for a user in a specific project
     */
    public function getUserRolesForProject(User $user, ?Project $project = null): array
    {
        if ($user->getAdmin()) {
            // Admin users have all permissions
            return [];
        }
        
        $roles = [];
        
        if ($project === null) {
            // For global permissions, only return built-in roles
            $anonymousRole = $this->roleRepository->findOneBy(['builtin' => Role::BUILTIN_ANONYMOUS]);
            if ($anonymousRole) {
                $roles[] = $anonymousRole;
            }
            return $roles;
        }
        
        // Get direct memberships
        $members = $this->memberRepository->findBy([
            'user' => $user,
            'project' => $project
        ]);
        
        foreach ($members as $member) {
            $memberRoles = $member->getMemberRoles();
            foreach ($memberRoles as $memberRole) {
                $role = $memberRole->getRole();
                if ($role && !in_array($role, $roles, true)) {
                    $roles[] = $role;
                }
            }
        }
        
        // Get inherited memberships from parent projects
        $this->addInheritedRoles($user, $project, $roles);
        
        // Add non-member role if user has no specific roles but project is accessible
        if (empty($roles) && $this->canAccessProject($user, $project)) {
            $nonMemberRole = $this->roleRepository->findOneBy(['builtin' => Role::BUILTIN_NON_MEMBER]);
            if ($nonMemberRole) {
                $roles[] = $nonMemberRole;
            }
        }
        
        return $roles;
    }
    
    /**
     * Check if a role has a specific permission
     */
    public function roleHasPermission(Role $role, string $permission): bool
    {
        $permissions = $this->getRolePermissions($role);
        return in_array($permission, $permissions, true);
    }
    
    /**
     * Get all permissions for a role
     */
    public function getRolePermissions(Role $role): array
    {
        // In Redmine, permissions are stored serialized in the permissions field
        // For now, we'll implement a basic mapping based on role properties
        $permissions = [];
        
        // This would normally deserialize from $role->getPermissions()
        // For demo purposes, we'll implement basic logic
        
        if ($role->getBuiltin() === Role::BUILTIN_ANONYMOUS) {
            // Anonymous users get very limited permissions
            $permissions = ['project_view', 'issue_view', 'news_view'];
        } elseif ($role->getBuiltin() === Role::BUILTIN_NON_MEMBER) {
            // Non-members get basic read permissions
            $permissions = [
                'project_view', 'issue_view', 'news_view', 'document_view',
                'file_view', 'wiki_view', 'repository_view', 'board_view'
            ];
        } else {
            // For custom roles, we would deserialize the permissions field
            // This is a simplified implementation
            $permissions = $this->getCustomRolePermissions($role);
        }
        
        return $permissions;
    }
    
    /**
     * Check if user can access a project (either member or public project)
     */
    public function canAccessProject(User $user, Project $project): bool
    {
        // Admin users can access all projects
        if ($user->getAdmin()) {
            return true;
        }
        
        // Check if project is public
        if ($project->getIsPublic()) {
            return true;
        }
        
        // Check if user is a member
        $member = $this->memberRepository->findOneBy([
            'user' => $user,
            'project' => $project
        ]);
        
        if ($member) {
            return true;
        }
        
        // Check inherited membership from parent projects
        return $this->hasInheritedAccess($user, $project);
    }
    
    /**
     * Check if user has permission for a specific project
     */
    public function userHasPermission(User $user, string $permission, ?Project $project = null): bool
    {
        // Admin users always have permission
        if ($user->getAdmin()) {
            return true;
        }
        
        $roles = $this->getUserRolesForProject($user, $project);
        
        foreach ($roles as $role) {
            if ($this->roleHasPermission($role, $permission)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if user can view a specific issue based on visibility settings
     */
    public function canViewIssue(User $user, $issue, Project $project): bool
    {
        // Admin users can view all issues
        if ($user->getAdmin()) {
            return true;
        }
        
        // Check basic issue view permission
        if (!$this->userHasPermission($user, 'issue_view', $project)) {
            return false;
        }
        
        // Check if issue is private and user has permission to view private issues
        if ($issue->getIsPrivate() && !$this->userHasPermission($user, 'issue_manage_private', $project)) {
            // User can only view their own private issues or assigned issues
            return $issue->getAuthor() === $user || $issue->getAssignedTo() === $user;
        }
        
        // Apply visibility rules based on user's role settings
        $roles = $this->getUserRolesForProject($user, $project);
        $hasOwnIssuesOnly = false;
        
        foreach ($roles as $role) {
            $visibility = $this->getRoleIssueVisibility($role);
            if ($visibility === 'all') {
                return true;
            } elseif ($visibility === 'default') {
                // Can view non-private issues
                continue;
            } elseif ($visibility === 'own') {
                $hasOwnIssuesOnly = true;
            }
        }
        
        // If user has "own issues only" visibility
        if ($hasOwnIssuesOnly) {
            return $issue->getAuthor() === $user || $issue->getAssignedTo() === $user;
        }
        
        return true;
    }
    
    /**
     * Add inherited roles from parent projects
     */
    private function addInheritedRoles(User $user, Project $project, array &$roles): void
    {
        $parent = $project->getParent();
        if (!$parent || !$project->getInheritMembers()) {
            return;
        }
        
        $parentRoles = $this->getUserRolesForProject($user, $parent);
        foreach ($parentRoles as $role) {
            if (!in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }
    }
    
    /**
     * Check if user has inherited access from parent projects
     */
    private function hasInheritedAccess(User $user, Project $project): bool
    {
        $parent = $project->getParent();
        if (!$parent || !$project->getInheritMembers()) {
            return false;
        }
        
        return $this->canAccessProject($user, $parent);
    }
    
    /**
     * Get custom role permissions (placeholder for role permission deserialization)
     */
    private function getCustomRolePermissions(Role $role): array
    {
        // In a real implementation, this would deserialize the permissions from the database
        // For now, return a default set based on role properties
        
        $permissions = [
            'project_view', 'issue_view', 'issue_create', 'issue_edit', 'issue_comment',
            'time_entry_view', 'time_entry_log', 'news_view', 'document_view',
            'file_view', 'wiki_view', 'wiki_edit', 'repository_view', 'board_view'
        ];
        
        if ($role->getAssignable()) {
            $permissions[] = 'project_manage_members';
            $permissions[] = 'issue_manage_watchers';
        }
        
        return $permissions;
    }
    
    /**
     * Get issue visibility setting for a role
     */
    private function getRoleIssueVisibility(Role $role): string
    {
        // This would normally be stored in the role's settings
        // For now, return default behavior
        if ($role->getBuiltin() === Role::BUILTIN_ANONYMOUS) {
            return 'default'; // Non-private issues only
        }
        
        return 'all'; // Can see all issues by default
    }
}
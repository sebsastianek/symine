<?php

declare(strict_types=1);

namespace App\Security\Service;

use App\Entity\Role;
use App\Entity\User;
use App\Entity\Project;
use App\Security\Permission;
use App\Repository\MemberRepository;
use App\Repository\RoleRepository;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
use Psr\Log\LoggerInterface;

/**
 * Service for parsing and handling Redmine YAML permission format
 */
class PermissionParserService
{
    public function __construct(
        private LoggerInterface $logger,
        private MemberRepository $memberRepository,
        private RoleRepository $roleRepository,
        private ?RoleInheritanceService $roleInheritanceService = null
    ) {
    }

    /**
     * Parse YAML permissions string into array of permission strings
     */
    public function parsePermissions(?string $yamlPermissions): array
    {
        if (empty($yamlPermissions)) {
            return [];
        }

        try {
            $parsed = Yaml::parse($yamlPermissions);
            
            if (!is_array($parsed)) {
                return [];
            }

            // Convert Ruby symbols to PHP strings and validate against known permissions
            $permissions = [];
            foreach ($parsed as $permission) {
                $permissionName = $this->normalizePermissionName($permission);
                if ($this->isValidPermission($permissionName)) {
                    $permissions[] = $permissionName;
                }
            }

            return $permissions;
        } catch (ParseException $e) {
            $this->logger->error('Failed to parse YAML permissions', [
                'yaml' => $yamlPermissions,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Convert permissions array to YAML string for storage
     */
    public function serializePermissions(array $permissions): string
    {
        if (empty($permissions)) {
            return "---\n[]";
        }

        // Convert to Ruby symbol format for Redmine compatibility
        $symbolPermissions = array_map(function($permission) {
            return ':' . $permission;
        }, $permissions);

        return Yaml::dump($symbolPermissions, 2, 2);
    }

    /**
     * Get permissions for a role with YAML deserialization
     */
    public function getRolePermissions(Role $role): array
    {
        // Handle built-in roles first
        if ($role->getBuiltin() === Role::BUILTIN_ANONYMOUS) {
            return $this->getAnonymousPermissions();
        }
        
        if ($role->getBuiltin() === Role::BUILTIN_NON_MEMBER) {
            return $this->getNonMemberPermissions();
        }

        // For custom roles, parse the YAML permissions
        return $this->parsePermissions($role->getPermissions());
    }

    /**
     * Check if user has specific permission through their roles
     */
    public function hasPermission(User $user, string $permission, ?Project $project = null): bool
    {
        // Admin users bypass all permission checks
        if ($user->getAdmin()) {
            return true;
        }

        // Get user roles for the project context (with inheritance)
        $roles = $this->getUserRolesForContext($user, $project);
        
        foreach ($roles as $role) {
            $rolePermissions = $this->getRolePermissions($role);
            if (in_array($permission, $rolePermissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get permissions for tracker-specific operations
     */
    public function getTrackerSpecificPermissions(Role $role, int $trackerId): array
    {
        $allPermissions = $this->getRolePermissions($role);
        
        // Filter permissions that might be tracker-specific
        // In Redmine, some permissions can be limited to specific trackers
        return array_filter($allPermissions, function($permission) {
            return $this->isTrackerApplicablePermission($permission);
        });
    }

    /**
     * Filter permissions based on enabled modules for a project
     */
    public function filterPermissionsByModules(array $permissions, Project $project): array
    {
        $enabledModules = $this->getEnabledModulesForProject($project);
        
        return array_filter($permissions, function($permission) use ($enabledModules) {
            $module = $this->getPermissionModule($permission);
            return !$module || in_array($module, $enabledModules, true);
        });
    }

    /**
     * Normalize permission name from Ruby symbol or string format
     */
    private function normalizePermissionName($permission): string
    {
        if (is_string($permission)) {
            // Remove Ruby symbol prefix if present
            return ltrim($permission, ':');
        }
        
        return (string) $permission;
    }

    /**
     * Validate if permission name exists in our Permission constants
     */
    private function isValidPermission(string $permission): bool
    {
        // Use reflection to get all Permission class constants
        $reflection = new \ReflectionClass(Permission::class);
        $constants = $reflection->getConstants();
        
        return in_array($permission, $constants, true);
    }

    /**
     * Get default permissions for anonymous users
     */
    private function getAnonymousPermissions(): array
    {
        return [
            Permission::PROJECT_VIEW,
            Permission::ISSUE_VIEW,
            Permission::NEWS_VIEW,
            Permission::DOCUMENT_VIEW,
            Permission::FILE_VIEW,
            Permission::WIKI_VIEW,
            Permission::REPOSITORY_VIEW,
            Permission::BOARD_VIEW,
        ];
    }

    /**
     * Get default permissions for non-member users
     */
    private function getNonMemberPermissions(): array
    {
        return [
            Permission::PROJECT_VIEW,
            Permission::ISSUE_VIEW,
            Permission::ISSUE_CREATE,
            Permission::ISSUE_COMMENT,
            Permission::NEWS_VIEW,
            Permission::DOCUMENT_VIEW,
            Permission::FILE_VIEW,
            Permission::WIKI_VIEW,
            Permission::WIKI_EDIT_PAGES,
            Permission::REPOSITORY_VIEW,
            Permission::BOARD_VIEW,
            Permission::MESSAGE_CREATE,
            Permission::TIME_ENTRY_VIEW,
        ];
    }

    /**
     * Get user roles for a specific context (project or global)
     */
    private function getUserRolesForContext(User $user, ?Project $project): array
    {
        // Use RoleInheritanceService if available for better group support
        if ($this->roleInheritanceService) {
            if ($project) {
                return $this->roleInheritanceService->getUserRolesForProject($user, $project);
            } else {
                return $this->roleInheritanceService->getUserGlobalRoles($user);
            }
        }

        // Fallback to basic implementation without group inheritance
        return $this->getBasicUserRoles($user, $project);
    }

    /**
     * Basic role resolution without group inheritance (fallback)
     */
    private function getBasicUserRoles(User $user, ?Project $project): array
    {
        $roles = [];
        
        if ($project === null) {
            // Global context - return built-in roles for anonymous/non-member
            $anonymousRole = $this->roleRepository->findOneBy(['builtin' => Role::BUILTIN_ANONYMOUS]);
            if ($anonymousRole) {
                $roles[] = $anonymousRole;
            }
            return $roles;
        }

        // Get direct memberships for the project
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
        
        // Add inherited roles from parent projects
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
     * Add inherited roles from parent projects
     */
    private function addInheritedRoles(User $user, Project $project, array &$roles): void
    {
        $parent = $project->getParent();
        if (!$parent || !$project->getInheritMembers()) {
            return;
        }
        
        $parentRoles = $this->getUserRolesForContext($user, $parent);
        foreach ($parentRoles as $role) {
            if (!in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }
    }
    
    /**
     * Check if user can access a project
     */
    private function canAccessProject(User $user, Project $project): bool
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
        $parent = $project->getParent();
        if ($parent && $project->getInheritMembers()) {
            return $this->canAccessProject($user, $parent);
        }
        
        return false;
    }

    /**
     * Get module name for a permission
     */
    private function getPermissionModule(string $permission): ?string
    {
        // Map permissions to their modules
        $moduleMap = [
            // Issue tracking
            'issue_view' => 'issue_tracking',
            'issue_create' => 'issue_tracking',
            'issue_edit' => 'issue_tracking',
            'issue_delete' => 'issue_tracking',
            'issue_comment' => 'issue_tracking',
            'issue_manage_private' => 'issue_tracking',
            'issue_manage_watchers' => 'issue_tracking',
            'issue_manage_relations' => 'issue_tracking',
            'issue_manage_subtasks' => 'issue_tracking',
            
            // Time tracking
            'time_entry_view' => 'time_tracking',
            'time_entry_log' => 'time_tracking',
            'time_entry_edit' => 'time_tracking',
            'time_entry_delete' => 'time_tracking',
            
            // News
            'news_view' => 'news',
            'news_manage' => 'news',
            'news_comment' => 'news',
            
            // Documents
            'document_view' => 'documents',
            'document_manage' => 'documents',
            
            // Wiki
            'wiki_view' => 'wiki',
            'wiki_edit_pages' => 'wiki',
            'wiki_delete_pages' => 'wiki',
            'wiki_manage_pages' => 'wiki',
            'wiki_manage_attachments' => 'wiki',
            
            // Repository
            'repository_view' => 'repository',
            'repository_browse' => 'repository',
            'repository_commit_access' => 'repository',
            
            // Boards/Forums
            'board_view' => 'boards',
            'message_create' => 'boards',
            'message_edit' => 'boards',
            'message_delete' => 'boards',
            
            // Files
            'file_view' => 'files',
            'file_manage' => 'files',
        ];

        return $moduleMap[$permission] ?? null;
    }

    /**
     * Check if permission applies to tracker-specific operations
     */
    private function isTrackerApplicablePermission(string $permission): bool
    {
        $trackerPermissions = [
            Permission::ISSUE_VIEW,
            Permission::ISSUE_CREATE,
            Permission::ISSUE_EDIT,
            Permission::ISSUE_DELETE,
            Permission::ISSUE_COMMENT,
            Permission::ISSUE_MANAGE_PRIVATE,
        ];

        return in_array($permission, $trackerPermissions, true);
    }

    /**
     * Get enabled modules for a project
     */
    private function getEnabledModulesForProject(Project $project): array
    {
        // This will be implemented when EnabledModule entity is created
        // For now, return all modules as enabled
        return [
            'issue_tracking',
            'time_tracking', 
            'news',
            'documents',
            'files',
            'wiki',
            'repository',
            'boards',
            'calendar',
            'gantt'
        ];
    }
}
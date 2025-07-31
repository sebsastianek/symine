<?php

declare(strict_types=1);

namespace App\Security\Service;

use App\Entity\AnonymousUser;
use App\Entity\Role;
use App\Entity\Project;
use App\Repository\RoleRepository;
use App\Repository\ProjectRepository;

/**
 * Service for handling anonymous user operations and permissions
 */
class AnonymousUserService
{
    public function __construct(
        private RoleRepository $roleRepository,
        private ProjectRepository $projectRepository,
        private PermissionParserService $permissionParser
    ) {
    }

    /**
     * Create a new anonymous user instance
     */
    public function createAnonymousUser(): AnonymousUser
    {
        return new AnonymousUser();
    }

    /**
     * Get the built-in anonymous role from database
     */
    public function getAnonymousRole(): ?Role
    {
        return $this->roleRepository->findOneBy(['builtin' => Role::BUILTIN_ANONYMOUS]);
    }

    /**
     * Check if anonymous user has specific permission
     */
    public function hasAnonymousPermission(string $permission, ?Project $project = null): bool
    {
        $anonymousRole = $this->getAnonymousRole();
        if (!$anonymousRole) {
            return false;
        }

        // Get permissions for anonymous role
        $permissions = $this->permissionParser->getRolePermissions($anonymousRole);
        
        if (!in_array($permission, $permissions, true)) {
            return false;
        }

        // If no project context, allow global permissions
        if ($project === null) {
            return $this->isGlobalPermission($permission);
        }

        // For project-specific permissions, check if project allows anonymous access
        return $this->canAnonymousAccessProject($project);
    }

    /**
     * Check if anonymous user can access a project
     */
    public function canAnonymousAccessProject(Project $project): bool
    {
        // Anonymous users can only access public projects
        if (!$project->getIsPublic()) {
            return false;
        }

        // Check if project has anonymous module access enabled
        return $this->hasAnonymousModuleAccess($project);
    }

    /**
     * Get all projects accessible by anonymous users
     */
    public function getAccessibleProjects(): array
    {
        return $this->projectRepository->findBy(['isPublic' => true]);
    }

    /**
     * Check if anonymous users can view issues in a project
     */
    public function canViewIssuesInProject(Project $project): bool
    {
        if (!$this->canAnonymousAccessProject($project)) {
            return false;
        }

        return $this->hasAnonymousPermission('issue_view', $project);
    }

    /**
     * Check if anonymous users can view news in a project
     */
    public function canViewNewsInProject(Project $project): bool
    {
        if (!$this->canAnonymousAccessProject($project)) {
            return false;
        }

        return $this->hasAnonymousPermission('news_view', $project);
    }

    /**
     * Check if anonymous users can view documents in a project
     */
    public function canViewDocumentsInProject(Project $project): bool
    {
        if (!$this->canAnonymousAccessProject($project)) {
            return false;
        }

        return $this->hasAnonymousPermission('document_view', $project);
    }

    /**
     * Check if anonymous users can view wiki in a project
     */
    public function canViewWikiInProject(Project $project): bool
    {
        if (!$this->canAnonymousAccessProject($project)) {
            return false;
        }

        return $this->hasAnonymousPermission('wiki_view', $project);
    }

    /**
     * Check if anonymous users can view repository in a project
     */
    public function canViewRepositoryInProject(Project $project): bool
    {
        if (!$this->canAnonymousAccessProject($project)) {
            return false;
        }

        return $this->hasAnonymousPermission('repository_view', $project);
    }

    /**
     * Check if anonymous users can view boards in a project
     */
    public function canViewBoardsInProject(Project $project): bool
    {
        if (!$this->canAnonymousAccessProject($project)) {
            return false;
        }

        return $this->hasAnonymousPermission('board_view', $project);
    }

    /**
     * Get anonymous user permissions for a project
     */
    public function getProjectPermissions(Project $project): array
    {
        if (!$this->canAnonymousAccessProject($project)) {
            return [];
        }

        $anonymousRole = $this->getAnonymousRole();
        if (!$anonymousRole) {
            return [];
        }

        $permissions = $this->permissionParser->getRolePermissions($anonymousRole);
        
        // Filter by enabled modules
        return $this->permissionParser->filterPermissionsByModules($permissions, $project);
    }

    /**
     * Check if permission is a global permission (not project-specific)
     */
    private function isGlobalPermission(string $permission): bool
    {
        $globalPermissions = [
            'project_view',
            'user_view',
            'role_view',
        ];

        return in_array($permission, $globalPermissions, true);
    }

    /**
     * Check if project allows anonymous module access
     */
    private function hasAnonymousModuleAccess(Project $project): bool
    {
        // In Redmine, projects can disable anonymous access to specific modules
        // For now, we'll assume all public projects allow anonymous access
        // This will be enhanced when EnabledModule entity is implemented
        return true;
    }

    /**
     * Validate anonymous user permissions setup
     */
    public function validateAnonymousSetup(): array
    {
        $issues = [];

        // Check if anonymous role exists
        $anonymousRole = $this->getAnonymousRole();
        if (!$anonymousRole) {
            $issues[] = 'Anonymous role not found in database';
        } else {
            // Check if anonymous role has any permissions
            $permissions = $this->permissionParser->getRolePermissions($anonymousRole);
            if (empty($permissions)) {
                $issues[] = 'Anonymous role has no permissions assigned';
            }
        }

        // Check if there are any public projects
        $publicProjects = $this->getAccessibleProjects();
        if (empty($publicProjects)) {
            $issues[] = 'No public projects available for anonymous access';
        }

        return $issues;
    }

    /**
     * Get statistics about anonymous access
     */
    public function getAnonymousAccessStats(): array
    {
        $stats = [
            'public_projects_count' => count($this->getAccessibleProjects()),
            'anonymous_role_exists' => $this->getAnonymousRole() !== null,
            'anonymous_permissions_count' => 0,
        ];

        $anonymousRole = $this->getAnonymousRole();
        if ($anonymousRole) {
            $permissions = $this->permissionParser->getRolePermissions($anonymousRole);
            $stats['anonymous_permissions_count'] = count($permissions);
        }

        return $stats;
    }
}
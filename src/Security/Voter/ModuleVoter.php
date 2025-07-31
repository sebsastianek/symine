<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Project;
use App\Security\Service\ModuleService;
use App\Security\Service\PermissionService;
use App\Security\Service\AnonymousUserService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Voter for controlling access to project modules
 */
class ModuleVoter extends BaseRedmineVoter
{
    public const ACCESS_MODULE = 'access_module';

    public function __construct(
        protected PermissionService $permissionService,
        protected AnonymousUserService $anonymousUserService,
        private ModuleService $moduleService
    ) {
        parent::__construct($permissionService);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ACCESS_MODULE &&
               is_array($subject) &&
               isset($subject['project']) &&
               isset($subject['module']) &&
               $subject['project'] instanceof Project &&
               is_string($subject['module']);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $project = $subject['project'];
        $moduleName = $subject['module'];

        // Admin users can access all modules
        if ($this->isAdmin($token)) {
            return true;
        }

        // First check if module is enabled for the project
        if (!$this->moduleService->isModuleEnabled($project, $moduleName)) {
            return false;
        }

        // For anonymous users, check project access and anonymous permissions
        if ($this->isAnonymous($token)) {
            if (!$this->canAnonymousAccessProject($project)) {
                return false;
            }

            // Check if anonymous users have the required permissions for this module
            return $this->hasAnonymousModulePermissions($project, $moduleName);
        }

        $user = $this->getUser($token);
        if (!$user) {
            return false;
        }

        // Check if user can access the project
        if (!$this->canUserAccessProject($token, $project)) {
            return false;
        }

        // Check if user has required permissions for this module
        return $this->hasUserModulePermissions($token, $project, $moduleName);
    }

    /**
     * Check if anonymous users have required permissions for a module
     */
    private function hasAnonymousModulePermissions(Project $project, string $moduleName): bool
    {
        $requiredPermissions = $this->moduleService->getModuleRequiredPermissions($moduleName);

        if (empty($requiredPermissions)) {
            return true; // No specific permissions required
        }

        // Check if anonymous users have at least one required permission
        foreach ($requiredPermissions as $permission) {
            if ($this->hasAnonymousPermission($permission, $project)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if authenticated user has required permissions for a module
     */
    private function hasUserModulePermissions(TokenInterface $token, Project $project, string $moduleName): bool
    {
        $requiredPermissions = $this->moduleService->getModuleRequiredPermissions($moduleName);

        if (empty($requiredPermissions)) {
            return true; // No specific permissions required
        }

        // Check if user has at least one required permission
        foreach ($requiredPermissions as $permission) {
            if ($this->hasUserPermission($token, $permission, $project)) {
                return true;
            }
        }

        return false;
    }
}

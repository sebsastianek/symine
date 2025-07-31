<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\Service\PermissionService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class SecurityExtension extends AbstractExtension
{
    public function __construct(
        private Security $security,
        private PermissionService $permissionService
    ) {
    }
    
    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_granted_for_project', [$this, 'isGrantedForProject']),
            new TwigFunction('can_view_issue', [$this, 'canViewIssue']),
            new TwigFunction('user_has_permission', [$this, 'userHasPermission']),
            new TwigFunction('current_user', [$this, 'getCurrentUser']),
        ];
    }
    
    /**
     * Check if current user has permission for a specific project
     */
    public function isGrantedForProject(string $permission, $project = null): bool
    {
        $user = $this->security->getUser();
        
        if (!$user) {
            return false;
        }
        
        return $this->permissionService->userHasPermission($user, $permission, $project);
    }
    
    /**
     * Check if current user can view a specific issue
     */
    public function canViewIssue($issue): bool
    {
        $user = $this->security->getUser();
        
        if (!$user) {
            return false;
        }
        
        return $this->permissionService->canViewIssue($user, $issue, $issue->getProject());
    }
    
    /**
     * Generic permission check
     */
    public function userHasPermission(string $permission, $project = null): bool
    {
        return $this->isGrantedForProject($permission, $project);
    }
    
    /**
     * Get current user
     */
    public function getCurrentUser()
    {
        return $this->security->getUser();
    }
}
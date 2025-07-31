<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Security\Service\PermissionService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

abstract class BaseRedmineVoter extends Voter
{
    public function __construct(
        protected PermissionService $permissionService
    ) {
    }
    
    /**
     * Get the current user from the token
     */
    protected function getUser(TokenInterface $token): ?User
    {
        $user = $token->getUser();
        
        if (!$user instanceof User) {
            return null;
        }
        
        return $user;
    }
    
    /**
     * Check if user is authenticated
     */
    protected function isAuthenticated(TokenInterface $token): bool
    {
        return $this->getUser($token) !== null;
    }
    
    /**
     * Check if user is admin (admins bypass all permission checks)
     */
    protected function isAdmin(TokenInterface $token): bool
    {
        $user = $this->getUser($token);
        return $user && $user->getAdmin();
    }
    
    /**
     * Check if user has specific permission for a project
     */
    protected function hasPermission(User $user, string $permission, $project = null): bool
    {
        return $this->permissionService->userHasPermission($user, $permission, $project);
    }
    
    /**
     * Check if user can access a project
     */
    protected function canAccessProject(User $user, $project): bool
    {
        return $this->permissionService->canAccessProject($user, $project);
    }
}
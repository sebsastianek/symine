<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\TimeEntry;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class TimeEntryVoter extends BaseRedmineVoter
{
    public const VIEW = Permission::TIME_ENTRY_VIEW;
    public const LOG = Permission::TIME_ENTRY_LOG;
    public const EDIT = Permission::TIME_ENTRY_EDIT;
    public const DELETE = Permission::TIME_ENTRY_DELETE;
    public const MANAGE_ALL = Permission::TIME_ENTRY_MANAGE_ALL;
    public const IMPORT = Permission::TIME_ENTRY_IMPORT;
    
    protected function supports(string $attribute, mixed $subject): bool
    {
        $supportedAttributes = [
            self::VIEW, self::LOG, self::EDIT, self::DELETE, self::MANAGE_ALL, self::IMPORT
        ];
        
        return in_array($attribute, $supportedAttributes) && 
               ($subject instanceof TimeEntry || $subject === null);
    }
    
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $this->getUser($token);
        
        if (!$user) {
            return false;
        }
        
        // Admin users have full access
        if ($this->isAdmin($token)) {
            return true;
        }
        
        // For time entry creation, subject might be null
        if ($attribute === self::LOG && $subject === null) {
            return true; // Will be checked with specific project context in controller
        }
        
        if (!$subject instanceof TimeEntry) {
            return false;
        }
        
        $timeEntry = $subject;
        $project = $timeEntry->getProject();
        
        // Check if user can access the project
        if (!$this->canAccessProject($user, $project)) {
            return false;
        }
        
        // Check basic permission
        if (!$this->hasPermission($user, $attribute, $project)) {
            return false;
        }
        
        // Additional visibility checks based on time entry visibility settings
        if ($attribute === self::VIEW) {
            return $this->canViewTimeEntry($user, $timeEntry);
        }
        
        // For edit/delete, users can modify their own entries or all if they have manage_all permission
        if (in_array($attribute, [self::EDIT, self::DELETE])) {
            return $timeEntry->getUser() === $user || 
                   $this->hasPermission($user, self::MANAGE_ALL, $project);
        }
        
        return true;
    }
    
    /**
     * Check if user can view a specific time entry based on visibility settings
     */
    private function canViewTimeEntry($user, TimeEntry $timeEntry): bool
    {
        $project = $timeEntry->getProject();
        
        // If user has manage_all permission, they can see all time entries
        if ($this->hasPermission($user, self::MANAGE_ALL, $project)) {
            return true;
        }
        
        // Get user's time entry visibility setting from their roles
        $roles = $this->permissionService->getUserRolesForProject($user, $project);
        
        foreach ($roles as $role) {
            $visibility = $this->getRoleTimeEntryVisibility($role);
            if ($visibility === 'all') {
                return true;
            } elseif ($visibility === 'own') {
                return $timeEntry->getUser() === $user;
            }
        }
        
        // Default: users can see their own time entries
        return $timeEntry->getUser() === $user;
    }
    
    /**
     * Get time entry visibility setting for a role
     */
    private function getRoleTimeEntryVisibility($role): string
    {
        // This would normally be stored in the role's settings
        // For now, return default behavior
        return 'own'; // Users can see only their own time entries by default
    }
}
<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Issue;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class IssueVoter extends BaseRedmineVoter
{
    public const VIEW = Permission::ISSUE_VIEW;
    public const CREATE = Permission::ISSUE_CREATE;
    public const EDIT = Permission::ISSUE_EDIT;
    public const DELETE = Permission::ISSUE_DELETE;
    public const MANAGE_PRIVATE = Permission::ISSUE_MANAGE_PRIVATE;
    public const MANAGE_SUBTASKS = Permission::ISSUE_MANAGE_SUBTASKS;
    public const MANAGE_RELATIONS = Permission::ISSUE_MANAGE_RELATIONS;
    public const MANAGE_WATCHERS = Permission::ISSUE_MANAGE_WATCHERS;
    public const COMMENT = Permission::ISSUE_COMMENT;
    public const EDIT_NOTES = Permission::ISSUE_EDIT_NOTES;
    public const VIEW_PRIVATE_NOTES = Permission::ISSUE_VIEW_PRIVATE_NOTES;
    
    protected function supports(string $attribute, mixed $subject): bool
    {
        $supportedAttributes = [
            self::VIEW, self::CREATE, self::EDIT, self::DELETE,
            self::MANAGE_PRIVATE, self::MANAGE_SUBTASKS, self::MANAGE_RELATIONS,
            self::MANAGE_WATCHERS, self::COMMENT, self::EDIT_NOTES, self::VIEW_PRIVATE_NOTES
        ];
        
        return in_array($attribute, $supportedAttributes) && 
               ($subject instanceof Issue || $subject === null);
    }
    
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $this->getUser($token);
        
        // For issue creation, subject might be null (creating new issue)
        if ($attribute === self::CREATE && $subject === null) {
            if (!$user) {
                return false;
            }
            
            if ($this->isAdmin($token)) {
                return true;
            }
            
            // Will be checked with specific project context in controller
            return true;
        }
        
        if (!$subject instanceof Issue) {
            return false;
        }
        
        $issue = $subject;
        $project = $issue->getProject();
        
        if (!$user) {
            // Anonymous users can only view public project issues
            return $attribute === self::VIEW && 
                   $project->getIsPublic() && 
                   !$issue->getIsPrivate() &&
                   $this->hasPermission($user ?? new \App\Entity\User(), self::VIEW, $project);
        }
        
        // Admin users have full access
        if ($this->isAdmin($token)) {
            return true;
        }
        
        // Check if user can access the project
        if (!$this->canAccessProject($user, $project)) {
            return false;
        }
        
        // Special handling for viewing issues
        if ($attribute === self::VIEW) {
            return $this->permissionService->canViewIssue($user, $issue, $project);
        }
        
        // For private issues, check special permissions
        if ($issue->getIsPrivate()) {
            // Only author, assigned user, or users with manage_private permission can access
            if ($issue->getAuthor() === $user || 
                $issue->getAssignedTo() === $user || 
                $this->hasPermission($user, self::MANAGE_PRIVATE, $project)) {
                // Continue with normal permission check
            } else {
                return false;
            }
        }
        
        // Check basic permission
        if (!$this->hasPermission($user, $attribute, $project)) {
            return false;
        }
        
        // Additional checks for edit/delete operations
        if (in_array($attribute, [self::EDIT, self::DELETE, self::EDIT_NOTES])) {
            return $this->canModifyIssue($user, $issue, $attribute);
        }
        
        return true;
    }
    
    /**
     * Check if user can modify an issue (additional business logic)
     */
    private function canModifyIssue($user, Issue $issue, string $operation): bool
    {
        // Users can always edit their own issues (if they have edit permission)
        if ($issue->getAuthor() === $user) {
            return true;
        }
        
        // Users can edit assigned issues (if they have edit permission)
        if ($issue->getAssignedTo() === $user) {
            return true;
        }
        
        // Check if issue status allows modifications
        $status = $issue->getStatus();
        if ($status && $status->getIsClosed()) {
            // Closed issues might have restrictions
            // This would depend on workflow configuration
            return $this->hasPermission($user, Permission::ISSUE_EDIT, $issue->getProject());
        }
        
        return true;
    }
}
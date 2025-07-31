<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\WikiPage;
use App\Entity\Project;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class WikiVoter extends BaseRedmineVoter
{
    public const VIEW = Permission::WIKI_VIEW;
    public const EDIT = Permission::WIKI_EDIT;
    public const MANAGE = Permission::WIKI_MANAGE;
    public const RENAME = Permission::WIKI_RENAME;
    public const DELETE = Permission::WIKI_DELETE;
    public const PROTECT = Permission::WIKI_PROTECT;
    
    protected function supports(string $attribute, mixed $subject): bool
    {
        $supportedAttributes = [
            self::VIEW, self::EDIT, self::MANAGE, self::RENAME, self::DELETE, self::PROTECT
        ];
        
        return in_array($attribute, $supportedAttributes) && 
               ($subject instanceof WikiPage || $subject instanceof Project);
    }
    
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $this->getUser($token);
        
        // Determine the project based on subject type
        if ($subject instanceof WikiPage) {
            $project = $subject->getWiki()->getProject();
        } elseif ($subject instanceof Project) {
            $project = $subject;
        } else {
            return false;
        }
        
        if (!$user) {
            // Anonymous users can only view wiki in public projects
            return $attribute === self::VIEW && 
                   $project->getIsPublic() &&
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
        
        // Check if wiki module is enabled for the project
        if (!$this->isWikiEnabled($project)) {
            return false;
        }
        
        // Check basic permission
        if (!$this->hasPermission($user, $attribute, $project)) {
            return false;
        }
        
        // Additional checks for protected pages
        if ($subject instanceof WikiPage && $subject->getProtected()) {
            // Only users with protect permission can edit protected pages
            if (in_array($attribute, [self::EDIT, self::RENAME, self::DELETE])) {
                return $this->hasPermission($user, self::PROTECT, $project);
            }
        }
        
        return true;
    }
    
    /**
     * Check if wiki module is enabled for the project
     */
    private function isWikiEnabled(Project $project): bool
    {
        // Check if wiki module is in enabled_modules for this project
        // This would typically check the EnabledModule entity
        // For now, assume wiki is always enabled
        return true;
    }
}
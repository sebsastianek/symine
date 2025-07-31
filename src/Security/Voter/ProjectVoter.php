<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Project;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class ProjectVoter extends BaseRedmineVoter
{
    public const VIEW = Permission::PROJECT_VIEW;
    public const EDIT = Permission::PROJECT_EDIT;
    public const DELETE = Permission::PROJECT_DELETE;
    public const MANAGE_MEMBERS = Permission::PROJECT_MANAGE_MEMBERS;
    public const MANAGE_VERSIONS = Permission::PROJECT_MANAGE_VERSIONS;
    public const CLOSE = Permission::PROJECT_CLOSE;
    public const REOPEN = Permission::PROJECT_REOPEN;
    public const ARCHIVE = Permission::PROJECT_ARCHIVE;
    public const UNARCHIVE = Permission::PROJECT_UNARCHIVE;
    public const MANAGE_WIKI = Permission::PROJECT_MANAGE_WIKI;
    public const MANAGE_DOCUMENTS = Permission::PROJECT_MANAGE_DOCUMENTS;
    public const MANAGE_FILES = Permission::PROJECT_MANAGE_FILES;
    public const MANAGE_REPOSITORY = Permission::PROJECT_MANAGE_REPOSITORY;
    public const MANAGE_BOARDS = Permission::PROJECT_MANAGE_BOARDS;
    public const MANAGE_CATEGORIES = Permission::PROJECT_MANAGE_CATEGORIES;
    public const MANAGE_WORKFLOWS = Permission::PROJECT_MANAGE_WORKFLOWS;

    protected function supports(string $attribute, mixed $subject): bool
    {
        $supportedAttributes = [
            self::VIEW, self::EDIT, self::DELETE, self::MANAGE_MEMBERS,
            self::MANAGE_VERSIONS, self::CLOSE, self::REOPEN, self::ARCHIVE,
            self::UNARCHIVE, self::MANAGE_WIKI, self::MANAGE_DOCUMENTS,
            self::MANAGE_FILES, self::MANAGE_REPOSITORY, self::MANAGE_BOARDS,
            self::MANAGE_CATEGORIES, self::MANAGE_WORKFLOWS
        ];

        return in_array($attribute, $supportedAttributes) && $subject instanceof Project;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $this->getUser($token);

        if (!$user) {
            return $attribute === self::VIEW && $subject->getIsPublic();
        }

        if ($this->isAdmin($token)) {
            return true;
        }

        // For viewing, check if user can access the project
        if ($attribute === self::VIEW) {
            return $this->canAccessProject($user, $subject);
        }

        // For all other operations, check specific permissions
        return $this->hasPermission($user, $attribute, $subject);
    }
}

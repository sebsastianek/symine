<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Voter for global/admin permissions that don't belong to specific projects
 */
class GlobalVoter extends BaseRedmineVoter
{
    public const PROJECT_CREATE = Permission::GLOBAL_PROJECT_CREATE;
    public const USER_MANAGE = Permission::GLOBAL_USER_MANAGE;
    public const GROUP_MANAGE = Permission::GLOBAL_GROUP_MANAGE;
    public const ROLE_MANAGE = Permission::GLOBAL_ROLE_MANAGE;
    public const TRACKER_MANAGE = Permission::GLOBAL_TRACKER_MANAGE;
    public const ISSUE_STATUS_MANAGE = Permission::GLOBAL_ISSUE_STATUS_MANAGE;
    public const WORKFLOW_MANAGE = Permission::GLOBAL_WORKFLOW_MANAGE;
    public const ENUMERATION_MANAGE = Permission::GLOBAL_ENUMERATION_MANAGE;
    public const SETTINGS_MANAGE = Permission::GLOBAL_SETTINGS_MANAGE;
    public const PLUGIN_MANAGE = Permission::GLOBAL_PLUGIN_MANAGE;
    public const SYSTEM_INFO_VIEW = Permission::GLOBAL_SYSTEM_INFO_VIEW;

    protected function supports(string $attribute, mixed $subject): bool
    {
        $supportedAttributes = [
            self::PROJECT_CREATE, self::USER_MANAGE, self::GROUP_MANAGE,
            self::ROLE_MANAGE, self::TRACKER_MANAGE, self::ISSUE_STATUS_MANAGE,
            self::WORKFLOW_MANAGE, self::ENUMERATION_MANAGE, self::SETTINGS_MANAGE,
            self::PLUGIN_MANAGE, self::SYSTEM_INFO_VIEW
        ];

        return in_array($attribute, $supportedAttributes) && $subject === null;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $this->getUser($token);

        if (!$user) {
            return false;
        }

        // For global permissions, check without project context
        return $this->hasPermission($user, $attribute, null);
    }
}

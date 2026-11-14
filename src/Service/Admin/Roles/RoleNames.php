<?php

declare(strict_types=1);

namespace App\Service\Admin\Roles;

use App\Entity\Role;
use App\I18n\Localizer;

/** Names roles the way Redmine's Role#name does: the built-in roles carry a translated name. */
final class RoleNames
{
    public function __construct(private readonly Localizer $i18n)
    {
    }

    public function of(Role $role): string
    {
        return match ($role->builtin) {
            Role::BUILTIN_NON_MEMBER => $this->i18n->l('label_role_non_member'),
            Role::BUILTIN_ANONYMOUS => $this->i18n->l('label_role_anonymous'),
            default => (string) $role->name,
        };
    }
}

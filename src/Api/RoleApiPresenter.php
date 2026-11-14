<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Role;
use App\Service\Admin\Roles\RoleNames;

/** Builds the REST API structures of app/views/roles/index.api.rsb and show.api.rsb. */
final class RoleApiPresenter
{
    public function __construct(private readonly RoleNames $names)
    {
    }

    /**
     * @param list<Role> $roles
     *
     * @return list<array{id: ?int, name: ?string}>
     */
    public function roles(array $roles): array
    {
        return array_map(static fn (Role $r) => ['id' => $r->id, 'name' => $r->name], $roles);
    }

    /** @return array<string, mixed> */
    public function role(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $this->names->of($role),
            'assignable' => (bool) $role->assignable,
            'issues_visibility' => $role->issuesVisibility,
            'time_entries_visibility' => $role->timeEntriesVisibility,
            'users_visibility' => $role->usersVisibility,
            'permissions' => $role->getPermissions(),
        ];
    }
}

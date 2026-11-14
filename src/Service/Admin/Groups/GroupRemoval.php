<?php

declare(strict_types=1);

namespace App\Service\Admin\Groups;

use App\Entity\Group;
use App\Service\Admin\UserManager;

/** Deletes groups from the administration (groups#destroy). */
final class GroupRemoval
{
    public function __construct(private readonly UserManager $users)
    {
    }

    /** Deletes the group; false when it is a builtin group: builtin groups cannot be deleted */
    public function remove(Group $group): bool
    {
        if ($group->isBuiltin()) {
            return false;
        }
        $this->users->destroyGroup($group);

        return true;
    }
}

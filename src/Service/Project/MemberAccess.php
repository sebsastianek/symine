<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Security\AccessGuard;
use App\Security\Authorization;

/** Who may read and who may change the members of a project (the authorize filter of MembersController). */
final class MemberAccess
{
    public function __construct(
        private readonly Authorization $auth,
        private readonly AccessGuard $guard,
    ) {
    }

    /** Reading the members: view_members is enough, managing them gives the same right */
    public function authorizeRead(Project $project): void
    {
        if ($this->auth->allowedTo('view_members', $project)) {
            return;
        }
        $this->guard->authorize('manage_members', $project);
    }

    /** Adding, changing or removing members */
    public function authorizeManage(Project $project): void
    {
        $this->guard->authorize('manage_members', $project);
    }

    public function canManage(Project $project): bool
    {
        return $this->auth->allowedTo('manage_members', $project);
    }
}

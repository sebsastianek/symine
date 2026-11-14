<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Security\AccessGuard;
use App\Security\Authorization;

/** The access rules of the project actions that are not a single permission of a single project. */
final class ProjectAccess
{
    public function __construct(
        private readonly Authorization $auth,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * authorize_global for projects#new / #create: the actions belong to both the add_project and the
     * add_subprojects permissions (the parent is then validated by Project#allowed_parents).
     */
    public function authorizeNew(): void
    {
        if (!$this->auth->allowedToGlobally('add_project') && !$this->auth->allowedToGlobally('add_subprojects')) {
            $this->guard->deny();
        }
    }
}

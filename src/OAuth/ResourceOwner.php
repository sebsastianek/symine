<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\User;
use App\Security\AccessGuard;
use App\Security\CurrentUser;
use App\Service\Settings;

/** Redmine's resource_owner_authenticator of Doorkeeper: who may grant or revoke the access of an application. */
final class ResourceOwner
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
    ) {
    }

    /** require_login, then the REST API must be enabled */
    public function authenticate(): User
    {
        $this->guard->requireLogin();
        if (!$this->settings->restApiEnabled()) {
            $this->guard->deny();
        }

        return $this->currentUser->get();
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Scm\Sys;

use App\Security\CurrentUser;
use App\Service\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * Guards the repository management web service (SysController: before_action :check_enabled): it must be
 * enabled (Setting sys_api_enabled) and the request must carry its key (Setting sys_api_key).
 */
final class SysApiAccess
{
    /** Body of the 403 answered to a refused request (text/plain) */
    public const string DENIED = 'Access denied. Repository management WS is disabled or key is invalid.';

    public function __construct(
        private readonly Settings $settings,
        private readonly CurrentUser $currentUser,
    ) {
    }

    /** Whether the request may use the web service, which always acts as the anonymous user */
    public function granted(Request $request): bool
    {
        $this->currentUser->set(null);

        return $this->settings->bool('sys_api_enabled') && hash_equals($this->settings->string('sys_api_key'), (string) SysApiRequest::param($request, 'key'));
    }
}

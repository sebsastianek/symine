<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Principal;
use App\Entity\User;
use App\Service\Settings;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Selects the requests handled by the stateless "api" firewall - same rules as Redmine's
 * ApplicationController#find_current_user:
 *
 * - API requests (json/xml) never use the session: they are anonymous unless they carry API credentials
 *   accepted by the action (accept_api_auth, see KeyAuthScope);
 * - other requests belong to the user of the browser session, or of the autologin cookie, when there is one;
 * - otherwise a GET request answered as Atom by an action that accepts the feeds key (accept_atom_auth) is
 *   authenticated by that key;
 * - otherwise a GET request in another format carrying API credentials (key, header, HTTP Basic) is
 *   authenticated by them when the action accepts API authentication (e.g. attachment downloads, CSV/PDF
 *   exports).
 *
 * Everything else, including every non-GET request that is not an API request, is a browser request:
 * session based and subject to the CSRF check.
 */
final class ApiRequestMatcher implements RequestMatcherInterface
{
    /** Request attribute telling the authenticator which key the request may be authenticated with: "atom" or "api" */
    public const string MODE_ATTRIBUTE = '_key_auth';

    private const string SESSION_TOKEN_KEY = '_security_main';

    public function __construct(
        private readonly ?Settings $settings = null,
        private readonly ?Connection $db = null,
        private readonly ?AutologinHandler $autologin = null,
    ) {
    }

    public function matches(Request $request): bool
    {
        $request->attributes->remove(self::MODE_ATTRIBUTE);
        // repository management web service (SysController): authenticated by the sys_api_key
        if (str_starts_with($request->getPathInfo(), '/sys/')) {
            return false;
        }
        if (KeyAuthScope::isApiRequest($request)) {
            return true;
        }
        if (!$request->isMethod('GET')) {
            return false;
        }
        $candidate = KeyAuthScope::hasFeedsKey($request) || (self::hasApiCredentials($request) && KeyAuthScope::acceptsApiAuth($request));
        if (!$candidate) {
            return false;
        }
        // find_current_user, in its order: the user of the session, else the autologin cookie, else the feeds
        // key; API credentials are only looked at when none of them gave a user.
        $sessionUser = $this->sessionUser($request);
        if (true === $sessionUser) {
            return false;
        }
        $mode = null;
        if (null === $sessionUser) {
            if (null !== $this->autologin && $this->autologin->accepts($request->cookies->get(AutologinHandler::COOKIE_NAME))) {
                return false;
            }
            if (KeyAuthScope::hasFeedsKey($request)) {
                $mode = 'atom';
            }
        }
        if (null === $mode && self::hasApiCredentials($request) && KeyAuthScope::acceptsApiAuth($request)
            && (null === $this->settings || $this->settings->restApiEnabled())) {
            $mode = 'api';
        }
        if (null === $mode) {
            return false;
        }
        $request->attributes->set(self::MODE_ATTRIBUTE, $mode);

        return true;
    }

    /**
     * session[:user_id] of the browser session, without counting as a use of the session:
     * null = no user in the session, true = an active user (User.active.find), false = a user who is not
     * active any more (find_current_user then skips the autologin cookie and the feeds key).
     */
    private function sessionUser(Request $request): ?bool
    {
        if (null === $this->db || !$request->hasSession() || !$request->hasPreviousSession()) {
            return null;
        }
        $session = $request->getSession();
        $usage = null;
        $usageBefore = 0;
        if ($session instanceof Session) {
            // like the security ContextListener: reading the session here must not make the request stateful
            $usage = &$session->getUsageIndex();
            $usageBefore = $usage;
            $usage = \PHP_INT_MIN;
        }
        $wasStarted = $session->isStarted();
        try {
            $raw = $session->get(self::SESSION_TOKEN_KEY);
            if (!$wasStarted && $session->isStarted()) {
                // close it again (it is restarted on demand): nothing was changed
                $session->save();
            }
        } catch (\Throwable) {
            $raw = null;
        } finally {
            if ($session instanceof Session) {
                $usage = $usageBefore;
            }
        }
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }
        try {
            $token = @unserialize($raw);
        } catch (\Throwable) {
            return null;
        }
        $user = $token instanceof TokenInterface ? $token->getUser() : null;
        if (!$user instanceof User || null === $user->id) {
            return null;
        }

        return Principal::STATUS_ACTIVE === (int) $this->db->fetchOne('SELECT status FROM users WHERE id = ?', [$user->id]);
    }

    /** API key (parameter or header) or HTTP Basic credentials; OAuth2 Bearer tokens are for API requests only */
    private static function hasApiCredentials(Request $request): bool
    {
        return null !== KeyAuthScope::apiKey($request) || KeyAuthScope::hasBasicCredentials($request);
    }

    public static function format(Request $request): ?string
    {
        return KeyAuthScope::format($request);
    }

    public static function isApiRequest(Request $request): bool
    {
        return KeyAuthScope::isApiRequest($request);
    }
}

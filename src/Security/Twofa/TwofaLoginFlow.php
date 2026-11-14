<?php

declare(strict_types=1);

namespace App\Security\Twofa;

use App\Entity\User;
use App\I18n\Localizer;
use App\Service\Settings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/**
 * The second login step (AccountController#twofa_confirm, #twofa_resend and #twofa): the user who gave the right
 * password waits in the session (TwofaLoginSubscriber) until the code is verified, then is logged in.
 */
final class TwofaLoginFlow
{
    public function __construct(
        private readonly TwofaManager $twofa,
        private readonly TwofaLoginSubscriber $loginSteps,
        private readonly Localizer $i18n,
        private readonly Settings $settings,
        private readonly Security $security,
    ) {
    }

    /** AccountController#twofa_setup: the pending user (2 minutes validity), or null after cleanup */
    public function pendingUser(SessionInterface $session): ?User
    {
        $user = $this->twofa->findSessionUser((string) $session->get(TwofaLoginSubscriber::SESSION_TOKEN, ''));
        if (null === $user) {
            $this->loginSteps->destroySession($session);

            return null;
        }
        if (null !== $user->language && '' !== $user->language) {
            $this->i18n->setLocale($user->language);
        }

        return $user;
    }

    /** AccountController#renew_twofa_session: each twofa session token is used for one request only */
    public function renew(SessionInterface $session, User $user): void
    {
        $tries = (int) $session->get(TwofaLoginSubscriber::SESSION_TRIES, 0) + 1;
        $backUrl = $session->get(TwofaLoginSubscriber::SESSION_BACK_URL);
        $autologin = $session->get(TwofaLoginSubscriber::SESSION_AUTOLOGIN);
        $this->loginSteps->destroySession($session);
        $this->loginSteps->setupSession($session, $user, $tries, $backUrl, \is_string($autologin) ? $autologin : null);
    }

    /**
     * Whether the 3 code entries allowed per successful password entry are used up (resends count too);
     * the pending login is forgotten when they are.
     */
    public function triesExhausted(SessionInterface $session): bool
    {
        if ((int) $session->get(TwofaLoginSubscriber::SESSION_TRIES) > 3) {
            $this->loginSteps->destroySession($session);

            return true;
        }

        return false;
    }

    /**
     * Logs the user in once the code is verified (handle_active_user / successful_authentication).
     *
     * @return string|null the page the login form was asked to go back to, null for the default one
     */
    public function complete(Request $request, User $user): ?string
    {
        $session = $request->getSession();
        $backUrl = $session->get(TwofaLoginSubscriber::SESSION_BACK_URL);
        $autologin = null !== $session->get(TwofaLoginSubscriber::SESSION_AUTOLOGIN) && $this->settings->int('autologin') > 0;
        $this->loginSteps->destroySession($session);
        $badges = [new TwofaVerifiedBadge()];
        if ($autologin) {
            $badges[] = (new RememberMeBadge())->enable();
        }
        $this->security->login($user, 'form_login', 'main', $badges);
        if ($this->twofa->mustActivate($user)) {
            $session->set(TwofaLoginSubscriber::SESSION_MUST_ACTIVATE, '1');
        }

        return self::safeBackUrl($request, \is_string($backUrl) ? $backUrl : null);
    }

    /** redirect_back_or_default: a local or same host URL that is not one of the login pages */
    private static function safeBackUrl(Request $request, ?string $back): ?string
    {
        if (null === $back || '' === $back) {
            return null;
        }
        $parts = parse_url($back);
        if (false === $parts) {
            return null;
        }
        $local = !isset($parts['host']) && str_starts_with($back, '/') && !str_starts_with($back, '//');
        $sameHost = isset($parts['host']) && strtolower($parts['host']) === strtolower($request->getHost()) && \in_array($parts['scheme'] ?? '', ['http', 'https'], true);
        $path = $parts['path'] ?? '/';
        if (($local || $sameHost) && !preg_match('#/(login|account/register|account/lost_password)\b#', $path)) {
            return $back;
        }

        return null;
    }
}

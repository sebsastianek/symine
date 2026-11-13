<?php

declare(strict_types=1);

namespace App\Security\Twofa;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Second authentication step of Redmine's AccountController#password_authentication: a user with an
 * active 2FA scheme who logged in with the login form (password or LDAP) is NOT authenticated yet.
 * The security token is dropped, a "twofa_session" token is stored in the session and the browser is
 * redirected to /account/twofa/confirm; the user is logged in by TwofaController once the code is
 * verified (with a TwofaVerifiedBadge). Remember-me (autologin) is postponed until then.
 *
 * Also sets session[:must_activate_twofa] on every interactive login (ApplicationController#start_user_session).
 *
 * Runs between CheckRememberMeConditionsListener (-32) and RememberMeListener (-64).
 */
final class TwofaLoginSubscriber implements EventSubscriberInterface
{
    public const string SESSION_TOKEN = 'twofa_session_token';
    public const string SESSION_TRIES = 'twofa_tries_counter';
    public const string SESSION_BACK_URL = 'twofa_back_url';
    public const string SESSION_AUTOLOGIN = 'twofa_autologin';
    public const string SESSION_MUST_ACTIVATE = 'must_activate_twofa';

    public function __construct(
        private readonly TwofaManager $twofa,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => ['onLoginSuccess', -48]];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ('main' !== $event->getFirewallName()) {
            return;
        }
        $user = $event->getUser();
        $request = $event->getRequest();
        if (!$user instanceof User || !$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        $passport = $event->getPassport();

        $passwordLogin = 'signin' === $request->attributes->get('_route')
            && $request->isMethod('POST')
            && !$passport->hasBadge(TwofaVerifiedBadge::class);
        if ($passwordLogin && TwofaManager::isActive($user)) {
            $autologin = false;
            if ($passport->hasBadge(RememberMeBadge::class)) {
                $badge = $passport->getBadge(RememberMeBadge::class);
                \assert($badge instanceof RememberMeBadge);
                $autologin = $badge->isEnabled() || null !== $request->request->get('autologin');
                $badge->disable();
            }
            $this->setupSession($session, $user, 1, $request->request->get('back_url'), $autologin ? '1' : null);
            $this->tokenStorage->setToken(null);
            $session->remove(self::SESSION_MUST_ACTIVATE);
            $event->setResponse(new RedirectResponse($this->urls->generate('twofa_confirm')));

            return;
        }

        if ($this->twofa->mustActivate($user)) {
            $session->set(self::SESSION_MUST_ACTIVATE, '1');
        }
    }

    /** AccountController#setup_twofa_session */
    public function setupSession(\Symfony\Component\HttpFoundation\Session\SessionInterface $session, User $user, int $tries, mixed $backUrl, ?string $autologin): void
    {
        $token = $this->twofa->createSessionToken($user);
        $session->set(self::SESSION_TOKEN, $token->value);
        $session->set(self::SESSION_TRIES, $tries);
        $session->set(self::SESSION_BACK_URL, \is_string($backUrl) && '' !== $backUrl ? $backUrl : null);
        $session->set(self::SESSION_AUTOLOGIN, $autologin);
    }

    /** AccountController#destroy_twofa_session */
    public function destroySession(\Symfony\Component\HttpFoundation\Session\SessionInterface $session): void
    {
        $this->twofa->deleteSessionToken((string) $session->get(self::SESSION_TOKEN, ''));
        foreach ([self::SESSION_TOKEN, self::SESSION_TRIES, self::SESSION_BACK_URL, self::SESSION_AUTOLOGIN] as $k) {
            $session->remove($k);
        }
    }
}

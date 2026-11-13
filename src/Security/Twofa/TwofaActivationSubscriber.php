<?php

declare(strict_types=1);

namespace App\Security\Twofa;

use App\I18n\Localizer;
use App\Security\ApiRequestMatcher;
use App\Security\CurrentUser;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * ApplicationController#check_twofa_activation: when 2FA is required for a user who logged in
 * without an active scheme (session[:must_activate_twofa]), every page redirects to the pairing
 * of the only available scheme (TOTP), except the pairing pages themselves, logout, lost password
 * and the password change page.
 */
final class TwofaActivationSubscriber implements EventSubscriberInterface
{
    private const array SKIPPED_ROUTES = [
        'twofa_select_scheme', 'twofa_activate_init', 'twofa_activate_init_default', 'twofa_activate_confirm', 'twofa_activate',
        'signout', 'lost_password', 'my_password',
        'signin', 'twofa_login', 'twofa_confirm', 'twofa_resend',
    ];

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly TwofaManager $twofa,
        private readonly UrlGeneratorInterface $urls,
        private readonly Localizer $i18n,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // after RequestContextSubscriber (6: login required, password change)
        return [KernelEvents::REQUEST => ['onRequest', 5]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession() || ApiRequestMatcher::isApiRequest($request)) {
            return;
        }
        $route = (string) $request->attributes->get('_route');
        if ('' === $route || str_starts_with($route, '_') || \in_array($route, self::SKIPPED_ROUTES, true)) {
            return;
        }
        $session = $request->getSession();
        if (!$session->get(TwofaLoginSubscriber::SESSION_MUST_ACTIVATE)) {
            return;
        }
        $user = $this->currentUser->get();
        if (!$this->twofa->mustActivate($user)) {
            $session->remove(TwofaLoginSubscriber::SESSION_MUST_ACTIVATE);

            return;
        }
        $session->getFlashBag()->add('warning', $this->i18n->l('twofa_warning_require'));
        $schemes = $this->twofa->availableSchemes();
        if (1 === \count($schemes)) {
            // init_twofa_pairing_and_send_code_for
            $this->twofa->initPairing($user);
            $event->setResponse(new RedirectResponse($this->urls->generate('twofa_activate_confirm', ['scheme' => $schemes[0]])));

            return;
        }
        $event->setResponse(new RedirectResponse($this->urls->generate('twofa_select_scheme')));
    }
}

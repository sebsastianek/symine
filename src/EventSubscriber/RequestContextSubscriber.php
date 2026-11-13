<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\I18n\Localizer;
use App\Security\ApiRequestMatcher;
use App\Security\CurrentUser;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Per request setup (ApplicationController before_actions): locale, login_required,
 * forced password change, session expiration, last login update, X-Redmine-Switch-User.
 */
final class RequestContextSubscriber implements EventSubscriberInterface
{
    private const array PUBLIC_ROUTES = ['signin', 'signout', 'register', 'lost_password', 'account_activate', 'account_activation_email', 'mail_handler', 'my_password', 'twofa_login', 'twofa_confirm', 'twofa_resend', 'oauth_token', 'oauth_revoke', 'oauth_token_info', 'sys_projects', 'sys_create_project_repository', 'sys_fetch_changesets', 'robots', 'rails_health_check', '_wdt', '_profiler'];

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // after the firewall (8)
        return [KernelEvents::REQUEST => ['onRequest', 6]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        $user = $this->currentUser->get();

        // X-Redmine-Switch-User (admins only, API)
        if ($user->admin && ($login = $request->headers->get('X-Redmine-Switch-User')) && ApiRequestMatcher::isApiRequest($request)) {
            $su = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['login' => $login]);
            if (!$su instanceof \App\Entity\User || !$su->isActive()) {
                $event->setResponse(new JsonResponse(null, 412));

                return;
            }
            $this->currentUser->set($su);
            $user = $su;
        }

        $request->setLocale($this->i18n->locale());

        if (\in_array($route, self::PUBLIC_ROUTES, true) || str_starts_with($route, '_')) {
            return;
        }
        if (!$user->isLogged() && $this->settings->loginRequired()) {
            $event->setResponse(ApiRequestMatcher::isApiRequest($request)
                ? \App\Security\LoginEntryPoint::apiUnauthorized($request, $this->settings->restApiEnabled())
                : new RedirectResponse($this->urls->generate('signin', ['back_url' => $request->getUri()])));

            return;
        }
        if ($user->isLogged() && $user->mustChangePasswd && !ApiRequestMatcher::isApiRequest($request)) {
            $event->setResponse(new RedirectResponse($this->urls->generate('my_password')));

            return;
        }
        if ($user->isLogged() && $user->id && (null === $user->lastLoginOn || $user->lastLoginOn < new \DateTimeImmutable('-1 minute'))) {
            $this->em->getConnection()->executeStatement('UPDATE users SET last_login_on = ? WHERE id = ?', [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $user->id]);
        }
    }
}

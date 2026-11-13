<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\ApiRequestMatcher;
use App\Twig\AppExtension;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Rails-like protect_from_forgery: every non-GET browser request must carry the global CSRF
 * token (form field "_token" or "X-CSRF-Token" header, as sent by rails-ujs).
 * Stateless API requests authenticated by key/basic auth are exempt.
 */
final class CsrfSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CsrfTokenManagerInterface $csrf)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 7]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->isMethodSafe()) {
            return;
        }
        if ((new ApiRequestMatcher())->matches($request)) {
            return;
        }
        // Login form and logout use their own security tokens
        if (\in_array($request->attributes->get('_route'), ['signin', 'signout', 'mail_handler', 'oauth_token', 'oauth_revoke', 'sys_projects', 'sys_create_project_repository', 'sys_fetch_changesets'], true)) {
            return;
        }
        // API requests (json/xml) without credentials are rejected later by authorization
        if (ApiRequestMatcher::isApiRequest($request) && !$request->hasPreviousSession()) {
            return;
        }
        $token = $request->request->get('_token') ?? $request->headers->get('X-CSRF-Token');
        if (!\is_string($token) || !$this->csrf->isTokenValid(new CsrfToken(AppExtension::CSRF_ID, $token))) {
            $event->setResponse(new Response('Invalid form authenticity token.', 422));
        }
    }
}

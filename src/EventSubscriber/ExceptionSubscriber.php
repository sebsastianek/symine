<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\I18n\Localizer;
use App\Security\ApiRequestMatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/** Renders 403/404/409/422 like Redmine (render_403, render_404, API status codes). */
final class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Environment $twig, private readonly Localizer $i18n, private readonly ?\App\Security\CurrentUser $currentUser = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // run before the security exception listener's default handling but after it for AccessDenied of anonymous
        return [KernelEvents::EXCEPTION => ['onException', -10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        // AccountController#logout: GET /logout displays the logout form (the firewall only logs out on a
        // request carrying the CSRF token), anonymous users are redirected to the home page
        if ($e->getPrevious() instanceof \Symfony\Component\Security\Core\Exception\LogoutException && $event->getRequest()->isMethod('GET')) {
            $logged = $this->currentUser?->isLogged() ?? false;
            $event->setResponse($logged
                ? new Response($this->twig->render('account/logout.html.twig', ['body_css' => 'controller-account action-logout']))
                : new \Symfony\Component\HttpFoundation\RedirectResponse($event->getRequest()->getBasePath().'/'));
            $event->allowCustomResponseCode();

            return;
        }
        if (!$e instanceof HttpExceptionInterface) {
            if ($e instanceof \Doctrine\ORM\OptimisticLockException) {
                $status = 409;
            } else {
                return;
            }
        } else {
            $status = $e->getStatusCode();
        }
        if (!\in_array($status, [403, 404, 409, 412, 422], true)) {
            return;
        }
        $request = $event->getRequest();
        if (ApiRequestMatcher::isApiRequest($request)) {
            // render_error: format.any { head @status } (empty body)
            $event->setResponse(new Response('', $status));

            return;
        }
        if ('json' === $request->getPreferredFormat()) {
            $event->setResponse(new JsonResponse(null, $status));

            return;
        }
        $message = match ($status) {
            403 => $e->getMessage() ?: $this->i18n->l('notice_not_authorized'),
            404 => $this->i18n->l('notice_file_not_found'),
            409 => $this->i18n->l('notice_issue_update_conflict'),
            default => $e->getMessage(),
        };
        if (403 === $status && '' !== $e->getMessage() && str_starts_with($e->getMessage(), 'Access Denied')) {
            $message = $this->i18n->l('notice_not_authorized');
        }
        $html = $this->twig->render('common/error.html.twig', ['status' => $status, 'message' => $message]);
        $event->setResponse(new Response($html, $status));
    }
}

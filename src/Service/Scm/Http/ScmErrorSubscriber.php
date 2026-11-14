<?php

declare(strict_types=1);

namespace App\Service\Scm\Http;

use App\Controller\RepositoriesController;
use App\I18n\Localizer;
use App\Security\ApiRequestMatcher;
use App\Service\Scm\ScmCommandFailed;
use App\Service\Scm\ScmNotFound;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * The error pages of the repository browser (RepositoriesController: rescue_from
 * Redmine::Scm::Adapters::CommandFailed => show_error_command_failed, and show_error_not_found): an entry or
 * a revision that does not exist is a 404 saying so, an SCM command that cannot be run a 500 giving its error.
 */
final class ScmErrorSubscriber implements EventSubscriberInterface
{
    /** The actions browsing a repository; those of the project settings never run its SCM */
    private const array ACTIONS = ['stats', 'graph', 'fetchChangesets', 'revisions', 'revision', 'addRelatedIssue', 'removeRelatedIssue', 'diff', 'action', 'showAction'];

    public function __construct(private readonly Environment $twig, private readonly Localizer $i18n)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // before the listeners logging the exception: these are answers of the browser, not failures of the application
        return [KernelEvents::EXCEPTION => ['onException', 16]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $e = $event->getThrowable();
        if (!$e instanceof ScmNotFound && !$e instanceof ScmCommandFailed) {
            return;
        }
        $controller = $request->attributes->get('_controller');
        if (!\is_string($controller) || !\in_array($controller, array_map(static fn (string $action) => RepositoriesController::class.'::'.$action, self::ACTIONS), true)) {
            return;
        }
        if ($e instanceof ScmCommandFailed) {
            $status = 500;
            $message = $this->i18n->l('error_scm_command_failed', ['value' => $e->getMessage()]);
        } elseif (!$e->page && ApiRequestMatcher::isApiRequest($request)) {
            // render_error :message => l(:error_scm_not_found), :status => 404 (API requests: empty 404)
            return;
        } else {
            $status = 404;
            $message = $this->i18n->l('error_scm_not_found');
        }
        $event->setResponse(new Response($this->twig->render('common/error.html.twig', ['status' => $status, 'message' => $message]), $status));
    }
}

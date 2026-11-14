<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Service\Tracking\Http\JavascriptResponse;
use App\Service\Tracking\Watchers\MentionSuggestions;
use App\Service\Tracking\Watchers\WatchableFinder;
use App\Service\Tracking\Watchers\WatcherAccess;
use App\Service\Tracking\Watchers\WatcherScript;
use App\Service\Tracking\Watchers\WatchersForm;
use App\Service\WatcherService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's WatchersController: watch/unwatch any watchable, manage watchers of an object. */
final class WatchersController extends AbstractController
{
    public function __construct(
        private readonly WatcherService $watchers,
        private readonly WatchableFinder $finder,
        private readonly WatcherAccess $access,
        private readonly WatchersForm $form,
        private readonly WatcherScript $script,
        private readonly MentionSuggestions $mentions,
        private readonly ApiRenderer $api,
    ) {
    }

    #[Route('/watchers/watch', name: 'watch', methods: ['POST'], defaults: ['watching' => true])]
    #[Route('/watchers/watch', name: 'unwatch', methods: ['DELETE'], defaults: ['watching' => false])]
    public function watch(Request $request, bool $watching): Response
    {
        $this->requireLogin();
        $watchables = $this->finder->find($request);
        $this->watchers->setWatching($watchables, $this->user(), $watching);
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'javascript')) {
            return new JavascriptResponse($this->script->setWatcher($watchables, $this->user(), $this->renderView('watchers/_watchers.html.twig', ['watched' => $watchables[0]])));
        }
        $referer = (string) $request->headers->get('referer');

        return '' !== $referer ? $this->redirect($referer) : new Response($watching ? 'Watcher added.' : 'Watcher removed.');
    }

    #[Route('/watchers/new', name: 'watchers_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $context = $this->finder->context($request);
        $this->access->authorize($context, 'add');
        if (!$request->isXmlHttpRequest() && !str_contains((string) $request->headers->get('Accept'), 'javascript')) {
            throw $this->createNotFoundException();
        }

        return new JavascriptResponse($this->script->openDialog($this->renderView('watchers/_new.html.twig', $this->form->view($context, $request))));
    }

    #[Route('/watchers.{_format}', name: 'watchers_create', requirements: ['_format' => 'json|xml|js|html'], defaults: ['_format' => 'html'], methods: ['POST'])]
    #[Route('/issues/{object_id}/watchers.{_format}', name: 'issue_watchers_create', requirements: ['object_id' => '\d+', '_format' => 'json|xml|html'], defaults: ['_format' => 'html', 'object_type' => 'issue'], methods: ['POST'])]
    public function create(Request $request): Response
    {
        $isApi = $this->isApi($request);
        $context = $this->finder->context($request);
        $this->access->authorize($context, 'add');
        $this->access->requireWatchables($context, 'add');
        $this->form->submit($context, $request, $isApi);
        if ($isApi) {
            return $this->api->noContent();
        }
        if ($request->isXmlHttpRequest() || 'js' === $request->attributes->get('_format')) {
            $js = $this->script->refreshDialog($this->renderView('watchers/_new.html.twig', $this->form->view($context, $request)));
            if ($context->isSingle()) {
                $js .= $this->script->setWatcher($context->watchables, $this->user(), $this->renderView('watchers/_watchers.html.twig', ['watched' => $context->first()]));
            }

            return new JavascriptResponse($js);
        }
        $referer = (string) $request->headers->get('referer');

        return '' !== $referer ? $this->redirect($referer) : new Response('Watcher added.');
    }

    /** Adds watchers checkboxes to the new issue form */
    #[Route('/watchers/append', name: 'watchers_append', methods: ['POST'])]
    public function append(Request $request): Response
    {
        $context = $this->finder->context($request);
        $this->access->authorize($context, 'add');
        $users = $this->form->chosen($request);

        return [] === $users ? new Response('') : new JavascriptResponse($this->script->append($users));
    }

    #[Route('/watchers.{_format}', name: 'watchers_destroy', requirements: ['_format' => 'json|xml|js|html'], defaults: ['_format' => 'html'], methods: ['DELETE'])]
    #[Route('/issues/{object_id}/watchers/{user_id}.{_format}', name: 'issue_watchers_destroy', requirements: ['object_id' => '\d+', 'user_id' => '\d+', '_format' => 'json|xml|html'], defaults: ['_format' => 'html', 'object_type' => 'issue'], methods: ['DELETE'])]
    public function destroy(Request $request): Response
    {
        $context = $this->finder->context($request);
        $this->access->authorize($context, 'delete');
        $this->access->requireWatchables($context, 'delete');
        $this->form->remove($context, $request);
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }
        if ($request->isXmlHttpRequest()) {
            return new JavascriptResponse($context->isSingle() ? $this->script->setWatcher($context->watchables, $this->user(), $this->renderView('watchers/_watchers.html.twig', ['watched' => $context->first()])) : '');
        }
        $referer = (string) $request->headers->get('referer');

        return '' !== $referer ? $this->redirect($referer) : new Response('Watcher removed.');
    }

    #[Route('/watchers/autocomplete_for_user', name: 'watchers_autocomplete_for_user', methods: ['GET'])]
    public function autocompleteForUser(Request $request): Response
    {
        $context = $this->finder->context($request);
        $this->access->authorize($context, 'add');

        return $this->render('watchers/_users_for_watcher.html.twig', $this->form->view($context, $request));
    }

    #[Route('/watchers/autocomplete_for_mention', name: 'watchers_autocomplete_for_mention', methods: ['GET'])]
    public function autocompleteForMention(Request $request): Response
    {
        $context = $this->finder->context($request);
        $this->access->authorize($context, 'add');

        return new JsonResponse($this->mentions->suggest($context, trim((string) $request->query->get('q', ''))));
    }
}

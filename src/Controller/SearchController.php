<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\SearchApiPresenter;
use App\Service\Search\SearchForm;
use App\Service\Search\SearchResultsView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Port of Redmine's SearchController. The two actions are search#index without and within a project
 * (find_optional_project): the same search, of everything or of the project.
 */
final class SearchController extends AbstractController
{
    public function __construct(
        private readonly SearchForm $form,
        private readonly SearchResultsView $results,
        private readonly SearchApiPresenter $presenter,
        private readonly ApiRenderer $api,
    ) {
    }

    #[Route('/search.{_format}', name: 'search', defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'], methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $id = $request->query->get('id');
        $project = null !== $id && '' !== $id ? $this->findProject((string) $id) : null;
        $this->authorize('search_project', $project, null === $project);
        $api = $this->isApi($request);
        if (!$api && null !== ($issue = $this->form->quickJump($request))) {
            return $this->redirect($request->getBaseUrl().'/issues/'.$issue->id);
        }
        $search = $this->form->submit($request, $project, $api);
        if ($api) {
            return $this->api->render($request, $this->presenter->results($search, $request));
        }

        return $this->render('search/index.html.twig', $this->results->view($search, $request, $project) + [
            'page_title' => $this->l('label_search'),
            'body_css' => 'controller-search action-index',
        ]);
    }

    #[Route('/projects/{id}/search.{_format}', name: 'project_search', defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'], methods: ['GET'])]
    public function project(Request $request, string $id): Response
    {
        $project = $this->findProject($id);
        $this->authorize('search_project', $project);
        $api = $this->isApi($request);
        if (!$api && null !== ($issue = $this->form->quickJump($request))) {
            return $this->redirect($request->getBaseUrl().'/issues/'.$issue->id);
        }
        $search = $this->form->submit($request, $project, $api);
        if ($api) {
            return $this->api->render($request, $this->presenter->results($search, $request));
        }

        return $this->render('search/index.html.twig', $this->results->view($search, $request, $project) + [
            'page_title' => $this->l('label_search'),
            'body_css' => 'controller-search action-index',
        ]);
    }
}

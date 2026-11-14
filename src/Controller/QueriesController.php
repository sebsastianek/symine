<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\QueryApiPresenter;
use App\Service\Tracking\Queries\QueryFilterValues;
use App\Service\Tracking\Queries\QueryItemsRoute;
use App\Service\Tracking\Queries\SavedQueries;
use App\Service\Tracking\Queries\SavedQueryForm;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of QueriesController: saved queries CRUD, queries API index and filter values endpoint. */
final class QueriesController extends AbstractController
{
    public function __construct(
        private readonly SavedQueries $queries,
        private readonly SavedQueryForm $form,
        private readonly QueryFilterValues $filterValues,
        private readonly QueryItemsRoute $items,
        private readonly QueryApiPresenter $presenter,
        private readonly ApiRenderer $api,
    ) {
    }

    #[Route('/queries.{_format}', name: 'queries_index', requirements: ['_format' => 'json|xml|html'], defaults: ['_format' => 'html'], methods: ['GET'], priority: 10)]
    public function index(Request $request, string $_format = 'html'): Response
    {
        if ('html' === $_format) {
            return new Response('', 406);
        }

        return $this->api->render($request, $this->presenter->index($this->queries->page($request)));
    }

    #[Route('/queries/filter', name: 'queries_filter', methods: ['GET'], priority: 10)]
    public function filter(Request $request): JsonResponse
    {
        return new JsonResponse($this->filterValues->of($request));
    }

    #[Route('/queries/new', name: 'query_new', methods: ['GET'], priority: 10)]
    #[Route('/projects/{project_id}/queries/new', name: 'project_query_new', methods: ['GET'], priority: 10)]
    public function new(Request $request, ?string $project_id = null): Response
    {
        $project = null !== $project_id ? $this->findProject($project_id) : null;
        $query = $this->form->blank($request, $project);
        $this->form->prefill($query, $request);

        return $this->render('queries/new.html.twig', $this->form->view($query, $project, $request) + [
            'body_css' => 'controller-queries action-new',
            'page_title' => $this->l('label_query_new'),
        ]);
    }

    #[Route('/queries', name: 'query_create', methods: ['POST'], priority: 10)]
    #[Route('/projects/{project_id}/queries', name: 'project_query_create', methods: ['POST'], priority: 10)]
    public function create(Request $request, ?string $project_id = null): Response
    {
        $project = null !== $project_id ? $this->findProject($project_id) : null;
        $query = $this->form->blank($request, $project);
        if ($this->form->submit($query, $request, $project)) {
            $this->flashNotice('notice_successful_create');

            return $this->redirectToRoute(...$this->items->of($query, $project, $request, ['query_id' => $query->record->id]));
        }

        return $this->render('queries/new.html.twig', $this->form->view($query, $project, $request) + [
            'body_css' => 'controller-queries action-new',
            'page_title' => $this->l('label_query_new'),
        ]);
    }

    #[Route('/queries/{id}/edit', name: 'query_edit', requirements: ['id' => '\d+'], methods: ['GET'], priority: 10)]
    public function edit(Request $request, int $id): Response
    {
        $query = $this->queries->find($id);

        return $this->render('queries/edit.html.twig', $this->form->view($query, $query->project, $request) + [
            'body_css' => 'controller-queries action-edit',
            'page_title' => $this->l('label_query'),
        ]);
    }

    #[Route('/queries/{id}', name: 'query_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'], priority: 10)]
    public function update(Request $request, int $id): Response
    {
        $query = $this->queries->find($id);
        // the project the query belongs to now: the form may turn it into a query for all projects
        $project = $query->project;
        if ($this->form->submit($query, $request, $project)) {
            $this->flashNotice('notice_successful_update');

            return $this->redirectToRoute(...$this->items->of($query, $query->record->project, $request, ['query_id' => $query->record->id]));
        }

        return $this->render('queries/edit.html.twig', $this->form->view($query, $project, $request) + [
            'body_css' => 'controller-queries action-edit',
            'page_title' => $this->l('label_query'),
        ]);
    }

    #[Route('/queries/{id}', name: 'query_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'], priority: 10)]
    public function destroy(Request $request, int $id): Response
    {
        $query = $this->queries->find($id);
        $project = $query->project;
        $this->queries->delete($query);

        return $this->redirectToRoute(...$this->items->of($query, $project, $request, ['set_filter' => 1]));
    }
}

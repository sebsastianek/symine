<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\VersionApiPresenter;
use App\Service\Project\RoadmapView;
use App\Service\Project\VersionFinder;
use App\Service\Project\VersionForm;
use App\Service\Project\VersionJs;
use App\Service\Project\VersionTextExport;
use App\Service\Project\VersionView;
use App\Service\VersionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Port of Redmine's VersionsController (roadmap, version pages and REST API). */
final class VersionsController extends AbstractController
{
    public function __construct(
        private readonly VersionService $versions,
        private readonly VersionFinder $finder,
        private readonly VersionForm $form,
        private readonly RoadmapView $roadmap,
        private readonly VersionView $view,
        private readonly VersionTextExport $textExport,
        private readonly VersionJs $js,
        private readonly VersionApiPresenter $presenter,
        private readonly ApiRenderer $api,
    ) {
    }

    // ------------------------------------------------------------------------------------------
    // index / roadmap
    // ------------------------------------------------------------------------------------------

    #[Route('/projects/{project_id}/roadmap', name: 'project_roadmap', methods: ['GET'])]
    #[Route('/projects/{project_id}/versions', name: 'project_versions', methods: ['GET'])]
    public function index(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('view_issues', $project);

        return $this->render('versions/index.html.twig', $this->roadmap->view($project, $request) + [
            'main_menu_selected' => 'roadmap',
            'page_title' => $this->l('label_roadmap'),
            'body_css' => 'controller-versions action-index',
        ]);
    }

    #[Route('/projects/{project_id}/versions.{_format}', name: 'project_versions_api', requirements: ['_format' => 'json|xml'], methods: ['GET'])]
    public function indexApi(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('view_issues', $project);

        return $this->api->render($request, $this->presenter->index($project));
    }

    // ------------------------------------------------------------------------------------------
    // show
    // ------------------------------------------------------------------------------------------

    #[Route('/versions/{id}', name: 'version_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[Route('/versions/{id}.{_format}', name: 'version_show_format', requirements: ['id' => '\d+', '_format' => 'json|xml|txt'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $version = $this->finder->find($id);
        $this->authorize('view_issues', $version->project);
        $format = $request->attributes->get('_format');
        if ('txt' === $format) {
            return $this->textExport->response($version);
        }
        if (\in_array($format, ['json', 'xml'], true)) {
            return $this->api->render($request, ['version' => $this->presenter->version($version, true)]);
        }

        return $this->render('versions/show.html.twig', $this->view->view($version, (string) $request->query->get('status_by', '')) + [
            'main_menu_selected' => 'roadmap',
            'page_title' => $version->name,
            'body_css' => 'controller-versions action-show',
        ]);
    }

    #[Route('/versions/{id}/status_by', name: 'version_status_by', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function statusBy(Request $request, int $id): Response
    {
        $version = $this->finder->find($id);
        $this->authorize('view_issues', $version->project);
        $criteria = (string) ($request->request->get('status_by') ?? $request->query->get('status_by', ''));
        if (VersionJs::wants($request)) {
            $html = $this->renderView('versions/_issue_counts.html.twig', ['version' => $version, 'criteria' => $criteria]);

            return new Response($this->js->statusBy($html), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->render('versions/show.html.twig', $this->view->view($version, $criteria) + [
            'main_menu_selected' => 'roadmap',
            'page_title' => $version->name,
            'body_css' => 'controller-versions action-show',
        ]);
    }

    // ------------------------------------------------------------------------------------------
    // new / create
    // ------------------------------------------------------------------------------------------

    #[Route('/projects/{project_id}/versions/new', name: 'version_new', methods: ['GET'])]
    public function new(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_versions', $project);
        $version = $this->versions->newVersion($project);
        $extra = $this->form->prefill($version, $request->query->all('version'));

        if (VersionJs::wants($request)) {
            $html = $this->renderView('versions/_new_modal.html.twig', $this->form->modalView($version, $extra));

            return new Response($this->js->modal($html), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->render('versions/new.html.twig', $this->form->view($version, $request, [], $extra) + [
            'main_menu_selected' => 'roadmap',
            'page_title' => $this->l('label_version_new'),
            'body_css' => 'controller-versions action-new',
        ]);
    }

    #[Route('/projects/{project_id}/versions', name: 'version_create', methods: ['POST'])]
    #[Route('/projects/{project_id}/versions.{_format}', name: 'version_create_api', requirements: ['_format' => 'json|xml'], methods: ['POST'])]
    public function create(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_versions', $project);
        $isApi = $this->isApi($request);
        $version = $this->versions->newVersion($project);
        $attrs = $isApi ? (array) (ApiRenderer::payload($request)['version'] ?? []) : $request->request->all('version');
        [$errors, $extra] = $this->form->submit($version, $attrs);

        if ($isApi) {
            if ([] !== $errors) {
                return $this->api->errors($request, $errors);
            }
            $response = $this->api->render($request, ['version' => $this->presenter->version($version, true)], 201);
            $response->headers->set('Location', $this->generateUrl('version_show', ['id' => $version->id], UrlGeneratorInterface::ABSOLUTE_URL));

            return $response;
        }
        $js = VersionJs::wants($request);
        if ([] !== $errors) {
            if ($js) {
                $html = $this->renderView('versions/_new_modal.html.twig', $this->form->modalView($version, $extra, $errors, true));

                return new Response($this->js->modal($html), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
            }

            return $this->render('versions/new.html.twig', $this->form->view($version, $request, $errors, $extra) + [
                'main_menu_selected' => 'roadmap',
                'page_title' => $this->l('label_version_new'),
                'body_css' => 'controller-versions action-new',
            ], new Response(null, 422));
        }
        if ($js) {
            return new Response($this->js->created($project, $version), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }
        $this->flashNotice('notice_successful_create');

        return $this->redirectBackOr($request, $this->generateUrl('project_settings', ['id' => $project->identifier, 'tab' => 'versions']));
    }

    // ------------------------------------------------------------------------------------------
    // edit / update
    // ------------------------------------------------------------------------------------------

    #[Route('/versions/{id}/edit', name: 'version_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        $version = $this->finder->find($id);
        $this->authorize('manage_versions', $version->project);

        return $this->render('versions/edit.html.twig', $this->form->view($version, $request) + [
            'main_menu_selected' => 'roadmap',
            'page_title' => $this->l('label_version'),
            'body_css' => 'controller-versions action-edit',
        ]);
    }

    #[Route('/versions/{id}', name: 'version_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    #[Route('/versions/{id}.{_format}', name: 'version_update_api', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): Response
    {
        $version = $this->finder->find($id);
        $project = $version->project;
        $this->authorize('manage_versions', $project);
        $isApi = $this->isApi($request);
        $attrs = $isApi ? ApiRenderer::payload($request)['version'] ?? null : ($request->request->has('version') ? $request->request->all('version') : null);
        if (!\is_array($attrs)) {
            return $isApi ? $this->api->noContent() : $this->redirectToRoute('project_settings', ['id' => $project->identifier, 'tab' => 'versions']);
        }
        [$errors, $extra] = $this->form->submit($version, $attrs);
        if ($isApi) {
            return [] === $errors ? $this->api->noContent() : $this->api->errors($request, $errors);
        }
        if ([] !== $errors) {
            $this->form->restore($version, $attrs);

            return $this->render('versions/edit.html.twig', $this->form->view($version, $request, $errors, $extra) + [
                'main_menu_selected' => 'roadmap',
                'page_title' => $this->l('label_version'),
                'body_css' => 'controller-versions action-edit',
            ], new Response(null, 422));
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectBackOr($request, $this->generateUrl('project_settings', ['id' => $project->identifier, 'tab' => 'versions']));
    }

    #[Route('/projects/{project_id}/versions/close_completed', name: 'versions_close_completed', methods: ['PUT', 'POST'])]
    public function closeCompleted(string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_versions', $project);
        $this->versions->closeCompleted($project);

        return $this->redirectToRoute('project_settings', ['id' => $project->identifier, 'tab' => 'versions']);
    }

    #[Route('/versions/{id}', name: 'version_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[Route('/versions/{id}.{_format}', name: 'version_destroy_api', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['DELETE'])]
    public function destroy(Request $request, int $id): Response
    {
        $version = $this->finder->find($id);
        $project = $version->project;
        $this->authorize('manage_versions', $project);
        $isApi = $this->isApi($request);
        if ($this->versions->isDeletable($version)) {
            $this->versions->destroy($version);

            return $isApi ? $this->api->noContent() : $this->redirectBackOr($request, $this->generateUrl('project_settings', ['id' => $project->identifier, 'tab' => 'versions']));
        }
        if ($isApi) {
            return new Response('', 422);
        }
        $this->flashError($this->l('notice_unable_delete_version'));

        return $this->redirectToRoute('project_settings', ['id' => $project->identifier, 'tab' => 'versions']);
    }
}

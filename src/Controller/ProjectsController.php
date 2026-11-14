<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\ProjectApiPresenter;
use App\Service\AtomFeed;
use App\Service\CsvExport;
use App\Service\Project\ProjectAccess;
use App\Service\Project\ProjectCsvExport;
use App\Service\Project\ProjectDeletion;
use App\Service\Project\ProjectFinder;
use App\Service\Project\ProjectForm;
use App\Service\Project\ProjectJumpBox;
use App\Service\Project\ProjectListView;
use App\Service\Project\ProjectNavigation;
use App\Service\Project\ProjectOverview;
use App\Service\Project\ProjectSettingsView;
use App\Service\Project\RequestFormat;
use App\Service\ProjectManager;
use App\Service\ProjectQueryService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Port of Redmine's ProjectsController. */
final class ProjectsController extends AbstractController
{
    public function __construct(
        private readonly ProjectManager $manager,
        private readonly ProjectQueryService $queries,
        private readonly ProjectAccess $access,
        private readonly ProjectFinder $finder,
        private readonly ProjectForm $form,
        private readonly ProjectListView $list,
        private readonly ProjectCsvExport $csvExport,
        private readonly ProjectOverview $overview,
        private readonly ProjectSettingsView $settingsView,
        private readonly ProjectDeletion $deletion,
        private readonly ProjectNavigation $navigation,
        private readonly ProjectJumpBox $jumpBox,
        private readonly ProjectApiPresenter $presenter,
        private readonly ApiRenderer $api,
        private readonly AtomFeed $atom,
        private readonly CsvExport $csv,
    ) {
    }

    // --- index --------------------------------------------------------------------------------

    #[Route('/projects', name: 'projects_index', methods: ['GET'])]
    #[Route('/projects.{_format}', name: 'projects_index_format', requirements: ['_format' => 'json|xml|atom|csv'], methods: ['GET'])]
    public function index(Request $request, string $_format = 'html'): Response
    {
        if (null !== ($jump = $this->navigation->menuItemUrl((string) $request->query->get('jump', '')))) {
            return $this->redirect($jump);
        }
        if ('csv' === $_format) {
            $query = $this->csvExport->query($request);
            if (!$query->isValid()) {
                return $this->redirectToRoute('projects_index', $query->asParams());
            }

            return $this->csv->response($request, $this->csvExport->rows($query), 'projects.csv');
        }
        $api = \in_array($_format, ['json', 'xml'], true);
        $state = $this->queries->fromRequest($request, $api);
        if ($api) {
            $count = $this->queries->count($state);
            $pager = $this->paginate($request, $count);
            $projects = $this->queries->results($state, $pager['offset'], $pager['limit']);

            return $this->api->render($request, $this->presenter->index($projects, ProjectApiPresenter::includes($request), $count, $pager));
        }
        if ('atom' === $_format) {
            return $this->atom->render($request, $this->list->feedTitle(), $this->list->feedItems($state));
        }
        $count = $this->list->pagedCount($state);

        return $this->render('projects/index.html.twig', $this->list->view($state, null === $count ? null : $this->paginate($request, $count)) + [
            'main_menu_selected' => 'projects',
            'page_title' => $this->l('label_project_plural'),
            'body_css' => 'controller-projects action-index',
        ]);
    }

    #[Route('/projects/autocomplete.js', name: 'projects_autocomplete', methods: ['GET'], priority: 10)]
    #[Route('/projects/autocomplete', name: 'projects_autocomplete_noformat', methods: ['GET'], priority: 10)]
    public function autocomplete(Request $request): Response
    {
        // respond_to { format.js }: without the .js suffix the format comes from the Accept header
        if ('projects_autocomplete_noformat' === $request->attributes->get('_route') && !RequestFormat::acceptsJs($request)) {
            return new Response('', 406, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        $script = $this->jumpBox->autocompleteScript(trim((string) $request->query->get('q', '')), (string) $request->query->get('jump', ''));

        return new Response($script, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }

    // --- new / create -------------------------------------------------------------------------

    #[Route('/projects/new', name: 'project_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->access->authorizeNew();

        return $this->render('projects/new.html.twig', $this->form->view($this->form->build($request)) + [
            'errors' => [],
            'main_menu_selected' => 'projects',
            'page_title' => $this->l('label_project_new'),
            'body_css' => 'controller-projects action-new',
        ]);
    }

    #[Route('/projects', name: 'project_create', methods: ['POST'])]
    #[Route('/projects.{_format}', name: 'project_create_format', requirements: ['_format' => 'json|xml'], methods: ['POST'])]
    public function create(Request $request): Response
    {
        $api = $this->isApi($request);
        $this->access->authorizeNew();
        [$project, $errors] = $this->form->create($request, $api);
        if ([] !== $errors) {
            if ($api) {
                return $this->api->errors($request, $errors);
            }

            return $this->render('projects/new.html.twig', $this->form->view($project) + [
                'errors' => $errors,
                'main_menu_selected' => 'projects',
                'page_title' => $this->l('label_project_new'),
                'body_css' => 'controller-projects action-new',
            ]);
        }
        if ($api) {
            $response = $this->api->render($request, ['project' => $this->presenter->project($project, [], true)], 201);
            $response->headers->set('Location', $this->generateUrl('project_show', ['id' => $project->id], UrlGeneratorInterface::ABSOLUTE_URL));

            return $response;
        }
        $this->flashNotice('notice_successful_create');
        if ($request->request->has('continue')) {
            return $this->redirectToRoute('project_new', array_filter(['parent_id' => $project->parent?->id]));
        }

        return $this->redirectToRoute('project_settings', ['id' => $project->identifier]);
    }

    // --- copy ---------------------------------------------------------------------------------

    #[Route('/projects/{id}/copy', name: 'project_copy', methods: ['GET', 'POST'])]
    public function copy(Request $request, string $id): Response
    {
        $this->requireAdmin();
        $source = $this->finder->any($id);
        if ($request->isMethod('GET')) {
            $project = $this->manager->copyFrom($source);
            $errors = [];
        } else {
            [$project, $errors] = $this->form->copy($source, $request);
            if ([] === $errors) {
                $this->flashNotice('notice_successful_create');

                return $this->redirectToRoute('project_settings', ['id' => $project->identifier]);
            }
        }

        return $this->render('projects/copy.html.twig', $this->form->copyView($source) + $this->form->view($project) + [
            'errors' => $errors,
            'main_menu_selected' => 'projects',
            'page_title' => $this->l('label_project_new'),
            'body_css' => 'controller-projects action-copy',
        ]);
    }

    // --- show ---------------------------------------------------------------------------------

    #[Route('/projects/{id}', name: 'project_show', requirements: ['id' => '[^/.]+'], methods: ['GET'])]
    #[Route('/projects/{id}.{_format}', name: 'project_show_format', requirements: ['id' => '[^/.]+', '_format' => 'json|xml'], methods: ['GET'])]
    public function show(Request $request, string $id, string $_format = 'html'): Response
    {
        $project = $this->findProject($id);
        $this->authorize('view_project', $project);
        if (\in_array($_format, ['json', 'xml'], true)) {
            return $this->api->render($request, ['project' => $this->presenter->project($project, ProjectApiPresenter::includes($request), true)]);
        }
        if (null !== ($jump = $this->navigation->projectMenuItemUrl($project, (string) $request->query->get('jump', '')))) {
            return $this->redirect($jump);
        }
        $this->manager->projectUsed($this->user(), $project);

        return $this->render('projects/show.html.twig', $this->overview->view($project) + [
            'main_menu_selected' => 'overview',
            'page_title' => $this->l('label_overview'),
            'body_css' => 'controller-projects action-show',
        ]);
    }

    // --- settings / update --------------------------------------------------------------------

    #[Route('/projects/{id}/settings/{tab}', name: 'project_settings', requirements: ['id' => '[^/.]+'], defaults: ['tab' => null], methods: ['GET'])]
    public function settings(Request $request, string $id, ?string $tab = null): Response
    {
        $project = $this->findProject($id);
        if (!$this->projects->canSeeSettings($project)) {
            $this->deny();
        }

        return $this->render('projects/settings.html.twig', $this->settingsView->view($project, $request, $tab) + [
            'main_menu_selected' => 'settings',
            'page_title' => $this->l('label_settings'),
            'body_css' => 'controller-projects action-settings',
        ]);
    }

    #[Route('/projects/{id}', name: 'project_update', requirements: ['id' => '[^/.]+'], methods: ['PUT', 'PATCH'])]
    #[Route('/projects/{id}.{_format}', name: 'project_update_format', requirements: ['id' => '[^/.]+', '_format' => 'json|xml'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, string $id): Response
    {
        $api = $this->isApi($request);
        $project = $this->findProject($id);
        $this->authorize('edit_project', $project);
        $errors = $this->form->update($project, $request, $api);
        if ([] !== $errors) {
            if ($api) {
                return $this->api->errors($request, $errors);
            }

            return $this->render('projects/settings.html.twig', $this->settingsView->view($project, $request, (string) $request->request->get('tab', 'info'), $errors) + [
                'main_menu_selected' => 'settings',
                'page_title' => $this->l('label_settings'),
                'body_css' => 'controller-projects action-settings',
            ]);
        }
        if ($api) {
            return $this->api->noContent();
        }
        $this->flashNotice();
        $tab = (string) $request->request->get('tab', '');

        return $this->redirectToRoute('project_settings', array_filter(['id' => $project->identifier, 'tab' => '' !== $tab ? $tab : null]));
    }

    // --- archive / close ----------------------------------------------------------------------

    #[Route('/projects/{id}/archive', name: 'project_archive', requirements: ['id' => '[^/.]+'], methods: ['POST', 'PUT'])]
    #[Route('/projects/{id}/archive.{_format}', name: 'project_archive_format', requirements: ['id' => '[^/.]+', '_format' => 'json|xml'], methods: ['POST', 'PUT'])]
    public function archive(Request $request, string $id): Response
    {
        $this->requireAdmin();
        $ok = $this->manager->archive($this->finder->any($id));
        if ($this->isApi($request)) {
            return $ok ? $this->api->noContent() : $this->api->errors($request, [$this->l('error_can_not_archive_project')]);
        }
        if (!$ok) {
            $this->flashError($this->l('error_can_not_archive_project'));
        }

        return $this->redirect($this->navigation->backToAdminUrl($request));
    }

    #[Route('/projects/{id}/unarchive', name: 'project_unarchive', requirements: ['id' => '[^/.]+'], methods: ['POST', 'PUT'])]
    #[Route('/projects/{id}/unarchive.{_format}', name: 'project_unarchive_format', requirements: ['id' => '[^/.]+', '_format' => 'json|xml'], methods: ['POST', 'PUT'])]
    public function unarchive(Request $request, string $id): Response
    {
        $this->requireAdmin();
        $project = $this->finder->any($id);
        if (!$project->isActive()) {
            $this->manager->unarchive($project);
        }
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }

        return $this->redirect($this->navigation->backToAdminUrl($request));
    }

    #[Route('/projects/{id}/close', name: 'project_close', requirements: ['id' => '[^/.]+'], methods: ['POST', 'PUT'])]
    #[Route('/projects/{id}/close.{_format}', name: 'project_close_format', requirements: ['id' => '[^/.]+', '_format' => 'json|xml'], methods: ['POST', 'PUT'])]
    public function close(Request $request, string $id): Response
    {
        $project = $this->findProject($id);
        $this->authorize('close_project', $project);
        $this->manager->close($project);
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }

        return $this->redirectToRoute('project_show', ['id' => $project->identifier]);
    }

    #[Route('/projects/{id}/reopen', name: 'project_reopen', requirements: ['id' => '[^/.]+'], methods: ['POST', 'PUT'])]
    #[Route('/projects/{id}/reopen.{_format}', name: 'project_reopen_format', requirements: ['id' => '[^/.]+', '_format' => 'json|xml'], methods: ['POST', 'PUT'])]
    public function reopen(Request $request, string $id): Response
    {
        $project = $this->findProject($id);
        $this->authorize('close_project', $project);
        $this->manager->reopen($project);
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }

        return $this->redirectToRoute('project_show', ['id' => $project->identifier]);
    }

    #[Route('/projects/{id}/bookmark', name: 'project_bookmark', requirements: ['id' => '[^/.]+'], methods: ['POST', 'DELETE'])]
    public function bookmark(Request $request, string $id): Response
    {
        $project = $this->findProject($id);
        $this->authorize('view_project', $project);
        $this->requireLogin();
        $this->manager->bookmark($this->user(), $project, $request->isMethod('POST'));
        if ($request->isXmlHttpRequest()) {
            $link = $this->renderView('projects/_bookmark_link.html.twig', ['project' => $project, 'bookmarked' => $request->isMethod('POST')]);

            return new Response($this->jumpBox->bookmarkScript($project, $link), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->redirectToRoute('project_show', ['id' => $project->identifier]);
    }

    /**
     * ProjectsController#edit: an empty action without template (authorize :edit_project). Rails renders
     * 406 Not Acceptable for interactive browser (HTML) requests and 204 No Content otherwise.
     */
    #[Route('/projects/{id}/edit', name: 'project_edit', requirements: ['id' => '[^/.]+'], methods: ['GET'])]
    public function edit(Request $request, string $id): Response
    {
        $project = $this->findProject($id);
        $this->authorize('edit_project', $project);
        if (!$request->isXmlHttpRequest() && 'html' === RequestFormat::negotiated($request)) {
            return new Response('', 406, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        return new Response('', 204);
    }

    // --- destroy ------------------------------------------------------------------------------

    /** ProjectsController#bulk_destroy (admin only): confirmation page, then deletion of the selected projects */
    #[Route('/projects/bulk_destroy', name: 'projects_bulk_destroy', methods: ['DELETE'], priority: 10)]
    public function bulkDestroy(Request $request): Response
    {
        $this->requireAdmin();
        $projects = $this->deletion->selection($request);
        if ($this->deletion->isConfirmed($request)) {
            $this->deletion->destroyAll($projects, $request->getClientIp());
            $this->flashNotice('notice_successful_delete');

            return $this->redirectToRoute('admin_projects');
        }

        return $this->render('projects/bulk_destroy.html.twig', $this->deletion->bulkView($projects) + [
            'page_title' => $this->l('label_confirmation'),
            'body_css' => 'controller-projects action-bulk_destroy',
        ]);
    }

    #[Route('/projects/{id}', name: 'project_destroy', requirements: ['id' => '[^/.]+'], methods: ['DELETE'])]
    #[Route('/projects/{id}.{_format}', name: 'project_destroy_format', requirements: ['id' => '[^/.]+', '_format' => 'json|xml'], methods: ['DELETE'])]
    public function destroy(Request $request, string $id): Response
    {
        $project = $this->findProject($id);
        if (!$this->manager->isDeletable($project)) {
            $this->deny();
        }
        $api = $this->isApi($request);
        if ($api || $request->request->get('confirm') === $project->identifier) {
            $this->deletion->destroy($project, $request->getClientIp());
            if ($api) {
                return $this->api->noContent();
            }
            $this->flashNotice('notice_successful_delete');

            return $this->redirect($this->navigation->projectsListUrl());
        }

        return $this->render('projects/destroy.html.twig', $this->deletion->view($project) + [
            'page_title' => $this->l('label_confirmation'),
            'body_css' => 'controller-projects action-destroy',
            'cancel_url' => $this->navigation->projectsListUrl(),
        ]);
    }
}

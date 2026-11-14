<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\TimeEntryApiPresenter;
use App\Entity\TimeEntry;
use App\Query\StatementInvalid;
use App\Service\AtomFeed;
use App\Service\TimeEntryService;
use App\Service\Timelog\TimeEntryBulkForm;
use App\Service\Timelog\TimeEntryContextMenu;
use App\Service\Timelog\TimeEntryFinder;
use App\Service\Timelog\TimeEntryForm;
use App\Service\Timelog\TimeEntryListView;
use App\Service\Timelog\TimelogBackUrl;
use App\Service\Timelog\TimelogCsv;
use App\Service\Timelog\TimelogFeed;
use App\Service\Timelog\TimelogJs;
use App\Service\Timelog\TimelogQueries;
use App\Service\Timelog\TimelogScope;
use App\Service\Timelog\TimeReportView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's TimelogController and ContextMenus::TimeEntriesController. */
final class TimelogController extends AbstractController
{
    public function __construct(
        private readonly TimeEntryService $timeEntries,
        private readonly TimelogScope $scope,
        private readonly TimeEntryFinder $finder,
        private readonly TimelogQueries $queries,
        private readonly TimeEntryListView $list,
        private readonly TimeReportView $reports,
        private readonly TimelogCsv $csv,
        private readonly TimelogFeed $feed,
        private readonly TimeEntryForm $form,
        private readonly TimeEntryBulkForm $bulkForm,
        private readonly TimeEntryContextMenu $contextMenu,
        private readonly TimeEntryApiPresenter $presenter,
        private readonly ApiRenderer $api,
        private readonly AtomFeed $atom,
    ) {
    }

    // --- List ---------------------------------------------------------------------

    #[Route('/time_entries', name: 'time_entries_index', methods: ['GET'])]
    #[Route('/time_entries.{_format}', name: 'time_entries_index_format', requirements: ['_format' => 'json|xml|csv|atom'], methods: ['GET'])]
    #[Route('/projects/{project_id}/time_entries', name: 'project_time_entries', requirements: ['project_id' => '[^/.]+'], methods: ['GET'])]
    #[Route('/projects/{project_id}/time_entries.{_format}', name: 'project_time_entries_format', requirements: ['project_id' => '[^/.]+', '_format' => 'json|xml|csv|atom'], methods: ['GET'])]
    public function index(Request $request, ?string $project_id = null, string $_format = 'html'): Response
    {
        $project = $this->scope->project($request, $project_id, 'view_time_entries');
        $query = $this->queries->retrieve($request, $project);
        if ('csv' === $_format && !$this->queries->exportAllowed($query, $project)) {
            $this->deny();
        }
        try {
            $count = $this->queries->count($query);
        } catch (StatementInvalid) {
            $this->flashError($this->l('error_query_statement_invalid'));

            return $this->redirectToRoute('home');
        }

        if (\in_array($_format, ['json', 'xml'], true)) {
            return $this->api->render($request, $this->presenter->index($request, $query, $count));
        }
        if ('atom' === $_format) {
            return $this->atom->render($request, $this->l('label_spent_time'), $this->feed->items($query), $project);
        }
        if ('csv' === $_format) {
            return $this->csv->entries($request, $query);
        }

        return $this->render(
            $request->isXmlHttpRequest() ? 'timelog/_list_xhr.html.twig' : 'timelog/index.html.twig',
            $this->list->view($query, $project, $this->paginate($request, $count)),
        );
    }

    // --- Report -------------------------------------------------------------------

    #[Route('/time_entries/report', name: 'time_entries_report', methods: ['GET'])]
    #[Route('/time_entries/report.{_format}', name: 'time_entries_report_format', requirements: ['_format' => 'csv'], methods: ['GET'])]
    #[Route('/projects/{project_id}/time_entries/report', name: 'project_time_entries_report', requirements: ['project_id' => '[^/.]+'], methods: ['GET'])]
    #[Route('/projects/{project_id}/time_entries/report.{_format}', name: 'project_time_entries_report_format', requirements: ['project_id' => '[^/.]+', '_format' => 'csv'], methods: ['GET'])]
    public function report(Request $request, ?string $project_id = null, string $_format = 'html'): Response
    {
        $project = $this->scope->project($request, $project_id, 'view_time_entries');
        $query = $this->queries->retrieve($request, $project);
        if ('csv' === $_format && !$this->queries->exportAllowed($query, $project)) {
            $this->deny();
        }
        $report = $this->reports->report($request, $query, $project);
        if ('csv' === $_format) {
            return $this->csv->report($request, $report);
        }

        return $this->render(
            $request->isXmlHttpRequest() ? 'timelog/_report_xhr.html.twig' : 'timelog/report.html.twig',
            $this->reports->view($report, $query, $project),
        );
    }

    // --- Show (API) ---------------------------------------------------------------

    #[Route('/time_entries/{id}', name: 'time_entry_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[Route('/time_entries/{id}.{_format}', name: 'time_entry_show_format', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['GET'])]
    public function show(Request $request, int $id, string $_format = 'html'): Response
    {
        $entry = $this->finder->find($id);
        $this->authorize('view_time_entries', $entry->project);
        if ('html' === $_format) {
            return new Response('', 406);
        }

        return $this->api->render($request, $this->presenter->show($entry));
    }

    // --- New / create -------------------------------------------------------------

    #[Route('/time_entries/new', name: 'time_entry_new_global', methods: ['GET', 'POST'])]
    #[Route('/projects/{project_id}/time_entries/new', name: 'time_entry_new', requirements: ['project_id' => '[^/.]+'], methods: ['GET', 'POST'])]
    #[Route('/issues/{issue_id}/time_entries/new', name: 'issue_time_entry_new', requirements: ['issue_id' => '\d+'], methods: ['GET', 'POST'])]
    #[Route('/time_entries/new.js', name: 'time_entry_new_js', methods: ['GET', 'POST'], defaults: ['_js' => true])]
    public function new(Request $request, ?string $project_id = null, ?int $issue_id = null): Response
    {
        $draft = $this->form->blank($request, $project_id, $issue_id);
        if ($request->attributes->get('_js') || 'js' === $request->query->get('format')) {
            return new Response($this->form->refreshScript($draft->entry, $draft->project), 200, ['Content-Type' => TimelogJs::CONTENT_TYPE]);
        }

        return $this->render('timelog/new.html.twig', $this->form->view($request, $draft->entry, $draft->project, $draft->issue));
    }

    #[Route('/time_entries', name: 'time_entries_create', methods: ['POST'])]
    #[Route('/time_entries.{_format}', name: 'time_entries_create_format', requirements: ['_format' => 'json|xml'], methods: ['POST'])]
    #[Route('/projects/{project_id}/time_entries', name: 'project_time_entries_create', requirements: ['project_id' => '[^/.]+'], methods: ['POST'])]
    #[Route('/projects/{project_id}/time_entries.{_format}', name: 'project_time_entries_create_format', requirements: ['project_id' => '[^/.]+', '_format' => 'json|xml'], methods: ['POST'])]
    #[Route('/issues/{issue_id}/time_entries', name: 'issue_time_entries_create', requirements: ['issue_id' => '\d+'], methods: ['POST'])]
    #[Route('/issues/{issue_id}/time_entries.{_format}', name: 'issue_time_entries_create_format', requirements: ['issue_id' => '\d+', '_format' => 'json|xml'], methods: ['POST'])]
    public function create(Request $request, ?string $project_id = null, ?int $issue_id = null, string $_format = 'html'): Response
    {
        $isApi = \in_array($_format, ['json', 'xml'], true);
        $draft = $this->form->create($request, $project_id, $issue_id, $isApi, fn (TimeEntry $e) => $this->callHook('controller_timelog_edit_before_save', ['params' => $request->request->all(), 'time_entry' => $e, 'project' => $e->project ?? null]));
        $entry = $draft->entry;
        if ([] !== $draft->errors) {
            return $isApi
                ? $this->api->errors($request, $draft->errors)
                : $this->render('timelog/new.html.twig', $this->form->view($request, $entry, $draft->project, $draft->issue, $draft->errors), new Response('', 422));
        }
        if ($isApi) {
            $response = $this->api->render($request, $this->presenter->show($entry), 201);
            $response->headers->set('Location', $this->generateUrl('time_entry_show_format', ['id' => $entry->id, '_format' => $_format], 0));

            return $response;
        }
        $this->flashNotice('notice_successful_create');
        if ($request->request->has('continue')) {
            return $this->redirectToRoute(...$this->form->continueRoute($request, $draft));
        }

        return $this->redirectBackOr($request, $this->generateUrl('project_time_entries', ['project_id' => $entry->project->identifier]));
    }

    // --- Edit / update ------------------------------------------------------------

    #[Route('/time_entries/{id}/edit', name: 'time_entry_edit', requirements: ['id' => '\d+'], methods: ['GET', 'PATCH', 'POST'])]
    #[Route('/time_entries/{id}/edit.js', name: 'time_entry_edit_js', requirements: ['id' => '\d+'], methods: ['GET', 'PATCH', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $entry = $this->finder->editable($id);
        $this->form->edit($entry, $request);
        if (!$request->isMethod('GET') || 'js' === $request->query->get('format')) {
            return new Response($this->form->refreshScript($entry, $entry->project), 200, ['Content-Type' => TimelogJs::CONTENT_TYPE]);
        }

        return $this->render('timelog/edit.html.twig', $this->form->view($request, $entry, $entry->project));
    }

    #[Route('/time_entries/{id}', name: 'time_entry_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    #[Route('/time_entries/{id}.{_format}', name: 'time_entry_update_format', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id, string $_format = 'html'): Response
    {
        $isApi = \in_array($_format, ['json', 'xml'], true);
        $entry = $this->finder->editable($id);
        $errors = $this->form->update($entry, $request, $isApi, fn (TimeEntry $e) => $this->callHook('controller_timelog_edit_before_save', ['params' => $request->request->all(), 'time_entry' => $e, 'project' => $e->project ?? null]));
        if ([] !== $errors) {
            return $isApi
                ? $this->api->errors($request, $errors)
                : $this->render('timelog/edit.html.twig', $this->form->view($request, $entry, $entry->project, null, $errors), new Response('', 422));
        }
        if ($isApi) {
            return $this->api->noContent();
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectBackOr($request, $this->generateUrl('project_time_entries', ['project_id' => $entry->project->identifier]));
    }

    // --- Destroy ------------------------------------------------------------------

    #[Route('/time_entries/{id}', name: 'time_entry_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[Route('/time_entries/{id}.{_format}', name: 'time_entry_destroy_format', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['DELETE'])]
    #[Route('/time_entries/destroy', name: 'time_entries_destroy', methods: ['DELETE', 'POST'])]
    public function destroy(Request $request, ?int $id = null, string $_format = 'html'): Response
    {
        $entries = $this->finder->editableEntries($request, $id);
        foreach ($entries as $e) {
            $this->timeEntries->destroy($e);
        }
        if (\in_array($_format, ['json', 'xml'], true)) {
            return $this->api->noContent();
        }
        $this->flashNotice('notice_successful_delete');
        if (null !== ($referer = TimelogBackUrl::afterDestroy($request))) {
            return $this->redirect($referer);
        }

        return $this->redirectBackOr($request, $this->generateUrl('project_time_entries', ['project_id' => $entries[0]->project->identifier]));
    }

    // --- Bulk edit ------------------------------------------------------------------

    #[Route('/time_entries/bulk_edit', name: 'time_entries_bulk_edit', methods: ['GET', 'POST'])]
    public function bulkEdit(Request $request): Response
    {
        $vars = $this->bulkForm->view($request, $this->finder->editableEntries($request));
        if ($request->isXmlHttpRequest()) {
            return new Response(TimelogJs::replaceContent($this->renderView('timelog/_bulk_edit_content.html.twig', $vars)), 200, ['Content-Type' => TimelogJs::CONTENT_TYPE]);
        }

        return $this->render('timelog/bulk_edit.html.twig', $vars);
    }

    #[Route('/time_entries/bulk_update', name: 'time_entries_bulk_update', methods: ['POST'])]
    public function bulkUpdate(Request $request): Response
    {
        $entries = $this->finder->editableEntries($request);
        $result = $this->bulkForm->submit($entries, $request, fn (TimeEntry $e) => $this->callHook('controller_time_entries_bulk_edit_before_save', ['params' => $request->request->all(), 'time_entry' => $e, 'project' => $e->project ?? null]));
        if ([] === $result->unsaved) {
            if ([] !== $result->saved) {
                $this->flashNotice('notice_successful_update');
            }

            return $this->redirectBackOr($request, $this->generateUrl('project_time_entries', ['project_id' => $entries[0]->project->identifier]));
        }
        $vars = $this->bulkForm->view($request, $result->unsaved, $entries, $result->errors);
        if ($request->isXmlHttpRequest()) {
            return new Response(TimelogJs::replaceContent($this->renderView('timelog/_bulk_edit_content.html.twig', $vars)), 200, ['Content-Type' => TimelogJs::CONTENT_TYPE]);
        }

        return $this->render('timelog/bulk_edit.html.twig', $vars);
    }

    // --- Context menu ---------------------------------------------------------------

    #[Route('/time_entries/context_menu', name: 'time_entries_context_menu', methods: ['GET', 'POST'])]
    public function contextMenu(Request $request): Response
    {
        return $this->render('context_menus/time_entries.html.twig', $this->contextMenu->view($this->finder->visibleEntries($request), $request));
    }
}

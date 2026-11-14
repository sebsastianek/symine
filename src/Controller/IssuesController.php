<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\IssueApiPresenter;
use App\Pdf\PdfExport;
use App\Service\Issue\EditIssueForm;
use App\Service\Issue\IssueBulkEditForm;
use App\Service\Issue\IssueDeletion;
use App\Service\Issue\IssueFinder;
use App\Service\Issue\IssueJs;
use App\Service\Issue\IssueParams;
use App\Service\Issue\IssueShowView;
use App\Service\Issue\NewIssueForm;
use App\Service\IssueNavigation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Port of Redmine's IssuesController (except index): show, new/create, edit/update, bulk edit, copy, destroy. */
final class IssuesController extends AbstractController
{
    public function __construct(
        private readonly IssueFinder $finder,
        private readonly IssueShowView $showView,
        private readonly NewIssueForm $newForm,
        private readonly EditIssueForm $editForm,
        private readonly IssueBulkEditForm $bulkForm,
        private readonly IssueDeletion $deletion,
        private readonly IssueApiPresenter $presenter,
        private readonly ApiRenderer $api,
        private readonly IssueNavigation $navigation,
    ) {
    }

    // ------------------------------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------------------------------

    #[Route('/issues/{id}.{_format}', name: 'issue_show', requirements: ['id' => '\d+', '_format' => 'html|json|xml|atom|pdf'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function show(Request $request, int $id, string $_format = 'html', ?PdfExport $pdfExport = null): Response
    {
        $issue = $this->finder->find($id);
        $this->authorize('view_issues', $issue->project);
        if ('pdf' === $_format && null !== $pdfExport) {
            return $pdfExport->issue($issue);
        }
        if ($this->isApi($request)) {
            return $this->api->render($request, ['issue' => $this->presenter->issue($issue, IssueParams::includes($request))], 200, IssueApiPresenter::ISSUE_XML_ATTRIBUTES);
        }
        $journals = $this->showView->journals($issue);
        if ('atom' === $_format) {
            return $this->render('issues/show.atom.twig', ['issue' => $issue, 'journals' => $journals], new Response('', 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']));
        }

        return $this->render('issues/show.html.twig', $this->showView->vars($request, $issue, $journals) + $this->editForm->view($request, $issue) + $this->navigation->retrieve($request, $issue));
    }

    #[Route('/issues/{id}/tab/{name}', name: 'issue_tab', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function tab(Request $request, int $id, string $name): Response
    {
        $issue = $this->finder->find($id);
        $this->authorize('view_issues', $issue->project);
        if (!$request->isXmlHttpRequest()) {
            throw new HttpException(422);
        }

        return match ($name) {
            'time_entries' => $this->render('issues/tabs/_time_entries.html.twig', ['time_entries' => $this->showView->timeEntries($issue), 'issue' => $issue]),
            'changesets' => $this->render('issues/tabs/_changesets.html.twig', ['changesets' => $this->presenter->changesets($issue), 'issue' => $issue, 'project' => $issue->project]),
            default => new Response(''),
        };
    }

    // ------------------------------------------------------------------------------------------
    // New / create / copy
    // ------------------------------------------------------------------------------------------

    #[Route('/projects/{project_id}/issues/new', name: 'issue_new', methods: ['GET', 'POST'])]
    #[Route('/issues/new', name: 'issues_new', methods: ['GET', 'POST'])]
    #[Route('/projects/{project_id}/issues/{copy_from}/copy', name: 'issue_copy', requirements: ['copy_from' => '\d+'], methods: ['GET'])]
    public function new(Request $request, ?string $project_id = null, ?int $copy_from = null): Response
    {
        if (null !== $copy_from) {
            $request->query->set('copy_from', (string) $copy_from);
        }
        $project = null !== $project_id ? $this->findProject($project_id) : null;

        return $this->render('issues/new.html.twig', $this->newForm->view($request, $project));
    }

    /** Form refresh (updateIssueFrom) for a new issue */
    #[Route('/projects/{project_id}/issues/new.js', name: 'issue_form_new', methods: ['POST'])]
    #[Route('/issues/new.js', name: 'issues_form_new', methods: ['POST'])]
    public function formNew(Request $request, ?string $project_id = null): Response
    {
        $project = null !== $project_id ? $this->findProject($project_id) : null;
        $vars = $this->newForm->refresh($request, $project);
        $js = IssueJs::newForm(
            (string) $request->request->get('form_update_triggered_by'),
            $vars['issue'],
            $this->renderView('issues/_form.html.twig', $vars),
            fn (): string => $this->renderView('issues/_watchers_form.html.twig', $vars),
        );

        return new Response($js, 200, ['Content-Type' => IssueJs::CONTENT_TYPE]);
    }

    #[Route('/projects/{project_id}/issues.{_format}', name: 'issue_create', requirements: ['_format' => 'html|json|xml'], defaults: ['_format' => 'html'], methods: ['POST'])]
    public function create(Request $request, ?string $project_id = null): Response
    {
        $project = null !== $project_id ? $this->findProject($project_id) : null;
        $creation = $this->newForm->submit($request, $project, $this->callHook(...));
        $issue = $creation->issue;
        if ($this->isApi($request)) {
            if (!$creation->saved) {
                return $this->api->errors($request, $creation->errors);
            }
            $response = $this->api->render($request, ['issue' => $this->presenter->issue($issue)], 201, IssueApiPresenter::ISSUE_XML_ATTRIBUTES);
            $response->headers->set('Location', $this->generateUrl('issue_show', ['id' => $issue->id], UrlGeneratorInterface::ABSOLUTE_URL));

            return $response;
        }
        if (!$creation->saved) {
            return $this->render('issues/new.html.twig', $this->newForm->view($request, $project, $creation));
        }
        if ($creation->unsavedAttachments > 0) {
            $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => $creation->unsavedAttachments]));
        }
        $link = '<a href="'.htmlspecialchars($this->generateUrl('issue_show', ['id' => $issue->id])).'" title="'.htmlspecialchars($issue->subject).'">#'.$issue->id.'</a>';
        $this->addFlash('notice_html', $this->l('notice_issue_successful_create', ['id' => $link]));
        if ($request->request->has('continue')) {
            $params = $this->newForm->continueParams($request, $issue, $project);

            return null !== $project
                ? $this->redirectToRoute('issue_new', ['project_id' => $issue->project->identifier] + $params)
                : $this->redirectToRoute('issues_new', $params);
        }
        if ($request->request->has('follow')) {
            return $this->redirectToRoute('issue_show', ['id' => $issue->id]);
        }

        return $this->redirectBackOr($request, $this->generateUrl('issue_show', ['id' => $issue->id]));
    }

    /**
     * issues#create without project (find_optional_project + authorize_global, anonymous => 401). Its own
     * action because the API key is accepted per action (App\Security\KeyAuthScope).
     */
    #[Route('/issues.{_format}', name: 'issues_create', requirements: ['_format' => 'html|json|xml'], defaults: ['_format' => 'html'], methods: ['POST'])]
    public function createGlobal(Request $request): Response
    {
        return $this->create($request);
    }

    // ------------------------------------------------------------------------------------------
    // Edit / update
    // ------------------------------------------------------------------------------------------

    #[Route('/issues/{id}/edit', name: 'issue_edit', requirements: ['id' => '\d+'], methods: ['GET', 'PATCH'])]
    public function edit(Request $request, int $id): Response
    {
        $issue = $this->finder->find($id);
        $this->editForm->authorize($issue);
        $attrs = IssueParams::issue($request);
        if ([] !== $attrs) {
            $this->editForm->assign($issue, $attrs);
        }

        return $this->render('issues/edit.html.twig', $this->editForm->page($request, $issue));
    }

    /** Form refresh (updateIssueFrom) for an existing issue */
    #[Route('/issues/{id}/edit.js', name: 'issue_form_edit', requirements: ['id' => '\d+'], methods: ['POST', 'PATCH'])]
    public function formEdit(Request $request, int $id): Response
    {
        $issue = $this->finder->find($id);
        $this->editForm->authorize($issue);
        $refresh = $this->editForm->refresh($request, $issue);
        $js = IssueJs::editForm($this->renderView('issues/_form.html.twig', $refresh['form']), $refresh['time_loggable'], $refresh['notes_addable']);
        $this->editForm->discard($issue);

        return new Response($js, 200, ['Content-Type' => IssueJs::CONTENT_TYPE]);
    }

    #[Route('/issues/{id}.{_format}', name: 'issue_update', requirements: ['id' => '\d+', '_format' => 'html|json|xml'], defaults: ['_format' => 'html'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): Response
    {
        $isApi = $this->isApi($request);
        $issue = $this->finder->find($id);
        $this->editForm->authorize($issue);
        $update = $this->editForm->submit($request, $issue, $this->callHook(...));
        if ($update->cancelled) {
            return $this->redirectToRoute('issue_show', ['id' => $issue->id]);
        }
        if ($update->unsavedAttachments > 0) {
            $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => $update->unsavedAttachments]));
        }
        if ($isApi) {
            return match (true) {
                $update->conflict => new Response('', 409),
                [] !== $update->errors => $this->api->errors($request, $update->errors),
                default => $this->api->noContent(),
            };
        }
        if (!$update->isSaved()) {
            return $this->render('issues/edit.html.twig', $this->editForm->page($request, $issue, $update));
        }
        if ($update->journalAdded && !IssueParams::get($request, 'no_flash')) {
            $this->flashNotice('notice_successful_update');
        }
        $this->navigation->rememberFromParams($request);

        return $this->redirectBackOr($request, $this->generateUrl('issue_show', ['id' => $issue->id]));
    }

    // ------------------------------------------------------------------------------------------
    // Bulk edit / update
    // ------------------------------------------------------------------------------------------

    #[Route('/issues/bulk_edit', name: 'issues_bulk_edit', methods: ['GET', 'POST'])]
    #[Route('/issues/bulk_edit.{_format}', name: 'issues_bulk_edit_js', requirements: ['_format' => 'js'], methods: ['GET', 'POST'])]
    public function bulkEdit(Request $request): Response
    {
        $vars = $this->bulkForm->view($request);
        if ('js' === $request->attributes->get('_format')) {
            return new Response(IssueJs::bulkEditForm($this->renderBlockView('issues/bulk_edit.html.twig', 'content', $vars)), 200, ['Content-Type' => IssueJs::CONTENT_TYPE]);
        }

        return $this->render('issues/bulk_edit.html.twig', $vars);
    }

    #[Route('/issues/bulk_update', name: 'issues_bulk_update', methods: ['POST', 'PATCH'])]
    public function bulkUpdate(Request $request): Response
    {
        $update = $this->bulkForm->submit($request, $this->callHook(...));
        if (!$update->isSaved()) {
            return $this->render('issues/bulk_edit.html.twig', $this->bulkForm->view($request, $update));
        }
        if ([] !== $update->saved) {
            $this->flashNotice('notice_successful_update');
        }
        if ($request->request->has('follow')) {
            if (null !== $issue = $update->followedIssue()) {
                return $this->redirectToRoute('issue_show', ['id' => $issue->id]);
            }
            if (null !== $project = $update->followedProject()) {
                return $this->redirectToRoute('project_issues', ['project_id' => $project->identifier]);
            }
        }
        $project = $update->project();

        return $this->redirectBackOr($request, null !== $project ? $this->generateUrl('project_issues', ['project_id' => $project->identifier]) : $this->generateUrl('issues_index'));
    }

    // ------------------------------------------------------------------------------------------
    // Destroy
    // ------------------------------------------------------------------------------------------

    #[Route('/issues/{id}.{_format}', name: 'issue_destroy', requirements: ['id' => '\d+', '_format' => 'html|json|xml'], defaults: ['_format' => 'html'], methods: ['DELETE'])]
    public function destroy(Request $request, ?int $id = null): Response
    {
        if (null !== $id) {
            $request->query->set('ids', [$id]);
        }
        $outcome = $this->deletion->submit($request);
        if (!$outcome->isDeleted()) {
            return $this->render('issues/destroy.html.twig', $outcome->confirmation);
        }
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }
        $this->flashNotice('notice_successful_delete');
        $project = $outcome->project;

        return $this->redirectBackOr($request, null !== $project ? $this->generateUrl('project_issues', ['project_id' => $project->identifier]) : $this->generateUrl('issues_index'));
    }

    /**
     * issues#destroy of the issues selected in a list. Its own action because the API key is accepted per
     * action (App\Security\KeyAuthScope) and is not for this one.
     */
    #[Route('/issues', name: 'issues_destroy', methods: ['DELETE'])]
    public function destroyMany(Request $request): Response
    {
        return $this->destroy($request);
    }
}

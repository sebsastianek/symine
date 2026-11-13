<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\IssueApiPresenter;
use App\Entity\Attachment;
use App\Entity\CustomField;
use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\IssueRelation;
use App\Entity\Journal;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\TimeEntry;
use App\Entity\TimeEntryActivity;
use App\Entity\Tracker;
use App\Entity\User;
use App\Service\AttachmentService;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Service\TextFormatter;
use App\Service\WatcherService;
use App\Twig\IssuesExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Port of Redmine's IssuesController (except index): show, new/create, edit/update, bulk edit, copy, destroy. */
final class IssuesController extends AbstractController
{
    public function __construct(
        private readonly IssueService $issues,
        private readonly CustomFieldService $customFields,
        private readonly AttachmentService $attachments,
        private readonly WatcherService $watchers,
        private readonly IssueApiPresenter $presenter,
        private readonly ApiRenderer $api,
        private readonly IssuesExtension $helpers,
        private readonly TextFormatter $formatter,
        private readonly \App\Service\IssueNavigation $navigation,
    ) {
    }

    // ------------------------------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------------------------------

    #[Route('/issues/{id}.{_format}', name: 'issue_show', requirements: ['id' => '\d+', '_format' => 'html|json|xml|atom|pdf'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function show(Request $request, int $id, string $_format = 'html', ?\App\Pdf\PdfExport $pdfExport = null): Response
    {
        $issue = $this->findIssue($id);
        $this->authorize('view_issues', $issue->project);
        if ('pdf' === $_format && null !== $pdfExport) {
            return $pdfExport->issue($issue);
        }
        if ($this->isApi($request)) {
            return $this->api->render($request, ['issue' => $this->presenter->issue($issue, $this->includes($request))], 200, IssueApiPresenter::ISSUE_XML_ATTRIBUTES);
        }
        $journals = $this->presenter->visibleJournals($issue);
        if ('atom' === $_format) {
            return $this->render('issues/show.atom.twig', ['issue' => $issue, 'journals' => array_reverse($journals)], new Response('', 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']));
        }
        if ('desc' === $this->user()->getPref()->commentsSorting()) {
            $journals = array_reverse($journals);
        }

        return $this->render('issues/show.html.twig', $this->showVars($request, $issue, $journals) + $this->editVars($request, $issue) + $this->navigation->retrieve($request, $issue));
    }

    /** @param list<Journal> $journals */
    private function showVars(Request $request, Issue $issue, array $journals): array
    {
        $project = $issue->project;
        $timeEntries = [];
        $canViewTime = $this->auth->allowedTo('view_time_entries', $project);
        $spent = $canViewTime ? $this->issues->spentHours($issue) : 0.0;
        $hasChangesets = [] !== $this->presenter->changesets($issue);
        $attachments = $this->attachments->forContainer('Issue', $issue->id);
        $journalsByAttachment = [];
        foreach ($journals as $j) {
            foreach ($j->details as $d) {
                if ('attachment' === $d->property && null !== $d->value) {
                    $journalsByAttachment[(int) $d->propKey] = $j;
                }
            }
        }
        $tabs = [];
        if ([] !== $journals) {
            $tabs[] = ['name' => 'history', 'label' => 'label_history', 'onclick' => 'showIssueHistory("history", this.href)'];
            if (array_filter($journals, static fn (Journal $j) => $j->hasNotes())) {
                $tabs[] = ['name' => 'notes', 'label' => 'label_issue_history_notes', 'onclick' => 'showIssueHistory("notes", this.href)'];
            }
            if (array_filter($journals, static fn (Journal $j) => $j->details->count() > 0)) {
                $tabs[] = ['name' => 'properties', 'label' => 'label_issue_history_properties', 'onclick' => 'showIssueHistory("properties", this.href)'];
            }
        }
        if ($canViewTime && $spent > 0) {
            $tabs[] = ['name' => 'time_entries', 'label' => 'label_time_entry_plural', 'remote' => true,
                'onclick' => "getRemoteTab('time_entries', '".$this->generateUrl('issue_tab', ['id' => $issue->id, 'name' => 'time_entries'])."', '".$this->generateUrl('issue_show', ['id' => $issue->id, 'tab' => 'time_entries'])."')"];
        }
        if ($hasChangesets) {
            $tabs[] = ['name' => 'changesets', 'label' => 'label_associated_revisions', 'remote' => true,
                'onclick' => "getRemoteTab('changesets', '".$this->generateUrl('issue_tab', ['id' => $issue->id, 'name' => 'changesets'])."', '".$this->generateUrl('issue_show', ['id' => $issue->id, 'tab' => 'changesets'])."')"];
        }
        $selectedTab = (string) $request->query->get('tab', '');
        if ('' === $selectedTab) {
            $pref = $this->user()->getPref()->get('history_default_tab');
            $selectedTab = match ($pref) {
                'last_tab_visited' => (string) ($request->cookies->get('history_last_tab') ?: 'notes'),
                '' => 'notes',
                null => '',
                default => (string) $pref,
            };
        }
        if (!array_filter($tabs, static fn ($t) => $t['name'] === $selectedTab)) {
            $selectedTab = $tabs[0]['name'] ?? '';
        }
        if ('time_entries' === $selectedTab) {
            $timeEntries = $this->timeEntries($issue);
        }
        $watchers = $this->watchers->watchers('Issue', (int) $issue->id);

        return [
            'project' => $project,
            'issue' => $issue,
            'journals' => $journals,
            'relations' => $this->presenter->visibleRelations($issue),
            'attachments' => $attachments,
            'journals_by_attachment' => $journalsByAttachment,
            'tabs' => $tabs,
            'selected_tab' => $selectedTab,
            'time_entries' => $timeEntries,
            'changesets' => 'changesets' === $selectedTab ? $this->presenter->changesets($issue) : [],
            'can_view_time' => $canViewTime,
            'issue_watchers' => $watchers,
            'is_editable' => $this->issues->isEditable($issue),
            'is_deletable' => $this->issues->isDeletable($issue),
            'is_copyable' => $this->auth->allowedTo('copy_issues', $project) && [] !== $this->projects->allowedTargetProjects(),
            'is_time_loggable' => $this->issues->isTimeLoggable($issue),
            'notes_addable' => $this->issues->isNotesAddable($issue),
            'attachments_deletable' => $this->issues->attachmentsDeletable($issue),
            'can_view_private_notes' => $this->issues->canViewPrivateNotes($issue),
            'issue_url' => $this->generateUrl('issue_show', ['id' => $issue->id], UrlGeneratorInterface::ABSOLUTE_URL),
            'half_custom_values' => $this->helpers->customValuesForDisplay($issue, 'half'),
            'full_custom_values' => $this->helpers->customValuesForDisplay($issue, 'full'),
            'has_assignable_versions' => [] !== $this->issues->assignableVersions($issue),
            'main_menu_selected' => 'issues',
            'page_title' => $issue->tracker->name.' #'.$issue->id.': '.$issue->subject,
            'body_css' => 'controller-issues action-show',
        ];
    }

    /** @return list<TimeEntry> */
    private function timeEntries(Issue $issue): array
    {
        $cond = $this->auth->allowedToCondition('view_time_entries', 'IDENTITY(t.project)');

        return $this->em->createQuery("SELECT t FROM App\Entity\TimeEntry t WHERE t.issue = :i AND ($cond) ORDER BY t.spentOn DESC, t.createdOn DESC")
            ->setParameter('i', $issue->id)->getResult();
    }

    #[Route('/issues/{id}/tab/{name}', name: 'issue_tab', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function tab(Request $request, int $id, string $name): Response
    {
        $issue = $this->findIssue($id);
        $this->authorize('view_issues', $issue->project);
        if (!$request->isXmlHttpRequest()) {
            throw new HttpException(422);
        }

        return match ($name) {
            'time_entries' => $this->render('issues/tabs/_time_entries.html.twig', ['time_entries' => $this->timeEntries($issue), 'issue' => $issue]),
            'changesets' => $this->render('issues/tabs/_changesets.html.twig', ['changesets' => $this->presenter->changesets($issue), 'issue' => $issue, 'project' => $issue->project]),
            default => new Response(''),
        };
    }

    // ------------------------------------------------------------------------------------------
    // New / create / copy
    // ------------------------------------------------------------------------------------------

    #[Route('/projects/{project_id}/issues/new', name: 'issue_new', methods: ['GET', 'POST'])]
    public function new(Request $request, string $project_id): Response
    {
        return $this->renderNew($request, $this->findProject($project_id));
    }

    #[Route('/issues/new', name: 'issues_new', methods: ['GET', 'POST'])]
    public function newGlobal(Request $request): Response
    {
        return $this->renderNew($request, null);
    }

    #[Route('/projects/{project_id}/issues/{copy_from}/copy', name: 'issue_copy', requirements: ['copy_from' => '\d+'], methods: ['GET'])]
    public function copy(Request $request, string $project_id, int $copy_from): Response
    {
        $request->query->set('copy_from', (string) $copy_from);

        return $this->renderNew($request, $this->findProject($project_id));
    }

    /** Form refresh (updateIssueFrom) for a new issue */
    #[Route('/projects/{project_id}/issues/new.js', name: 'issue_form_new', methods: ['POST'])]
    public function formNew(Request $request, string $project_id): Response
    {
        return $this->refreshNewForm($request, $this->findProject($project_id));
    }

    #[Route('/issues/new.js', name: 'issues_form_new', methods: ['POST'])]
    public function formNewGlobal(Request $request): Response
    {
        return $this->refreshNewForm($request, null);
    }

    private function refreshNewForm(Request $request, ?Project $project): Response
    {
        $issue = $this->buildNewIssue($request, $project, 'new');
        if ($issue instanceof Response) {
            return $issue;
        }
        $vars = $this->formVars($request, $issue, $project);
        $html = $this->renderView('issues/_form.html.twig', $vars);
        $js = 'replaceIssueFormWith('.json_encode($html, \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE).');';
        $trigger = (string) $request->request->get('form_update_triggered_by');
        if ('issue_project_id' === $trigger) {
            $js .= '$("#watchers_form_container").html('.json_encode($this->renderView('issues/_watchers_form.html.twig', $vars), \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE).');';
        } elseif ('issue_category_id' === $trigger) {
            $name = $issue->category?->assignedTo?->getName();
            $js .= "$('#issue_assigned_to_id').find('option').first().removeAttr('label').html(".json_encode(null !== $name ? htmlspecialchars($name) : '&nbsp;').');';
        }

        return $this->js($js);
    }

    private function renderNew(Request $request, ?Project $project, ?Issue $issue = null, array $errors = []): Response
    {
        if (null === $issue) {
            $this->authorizeNewIssue($project);
        }
        $issue ??= $this->buildNewIssue($request, $project, 'new');
        if ($issue instanceof Response) {
            return $issue;
        }
        $vars = $this->formVars($request, $issue, $project);
        $copyFrom = $this->copySource($request);

        return $this->render('issues/new.html.twig', $vars + [
            'project' => $project,
            'errors' => $errors,
            'copy_from' => $copyFrom,
            'link_copy' => $this->linkCopy($request) || $request->isMethod('GET'),
            'copy_attachments' => $this->copyAttachments($request) || $request->isMethod('GET'),
            'copy_subtasks' => '' !== (string) self::param($request, 'copy_subtasks', '') || $request->isMethod('GET'),
            'copy_from_has_attachments' => $copyFrom && [] !== $this->attachments->forContainer('Issue', $copyFrom->id),
            'saved_attachments' => $this->savedAttachments[spl_object_id($issue)] ?? [],
            'main_menu_selected' => '1' === $this->settings->string('new_item_menu_tab') ? 'new_issue' : 'issues',
            'page_title' => $this->l('label_issue_new'),
            'body_css' => 'controller-issues action-'.($request->isMethod('GET') ? 'new' : 'create'),
        ]);
    }

    #[Route('/projects/{project_id}/issues.{_format}', name: 'issue_create', requirements: ['_format' => 'html|json|xml'], defaults: ['_format' => 'html'], methods: ['POST'])]
    public function create(Request $request, string $project_id): Response
    {
        return $this->doCreate($request, $this->findProject($project_id));
    }

    #[Route('/issues.{_format}', name: 'issues_create', requirements: ['_format' => 'html|json|xml'], defaults: ['_format' => 'html'], methods: ['POST'])]
    public function createGlobal(Request $request): Response
    {
        // find_optional_project + authorize_global (anonymous => 401): see authorizeNewIssue() in doCreate()
        return $this->doCreate($request, null);
    }

    /**
     * find_optional_project + authorize_global of issues#new/create: the user needs add_issues or copy_issues
     * (on the project, or on any project without one), anonymous users are sent to the login page.
     */
    private function authorizeNewIssue(?Project $project): void
    {
        $global = null === $project;
        if (!$this->auth->allowedTo('add_issues', $project, null, $global) && !$this->auth->allowedTo('copy_issues', $project, null, $global)) {
            $this->deny();
        }
    }

    private function doCreate(Request $request, ?Project $project): Response
    {
        $isApi = $this->isApi($request);
        $this->authorizeNewIssue($project);
        $issue = $this->buildNewIssue($request, $project, 'create');
        if ($issue instanceof Response) {
            return $issue;
        }
        if (!isset($issue->project)) {
            if ($isApi) {
                // same errors as Redmine: without project there is no tracker nor default status
                return $this->api->errors($request, $this->issues->validate($issue));
            }
            throw new HttpException(422, $this->l('error_no_projects_with_tracker_allowed_for_new_issue'));
        }
        if (!$this->auth->allowedTo('add_issues', $issue->project)) {
            $this->deny();
        }
        $params = $this->issueParams($request);
        [$atts, $attErrors] = $this->attachments->fromRequest($request, $this->user(), $isApi ? (\is_array($params['uploads'] ?? null) ? $params['uploads'] : []) : null);
        if ([] !== $atts) {
            $this->em->flush();
            $this->issues->addAttachments($issue, $atts);
            $this->savedAttachments[spl_object_id($issue)] = $atts;
        }
        $copyFrom = $this->copySource($request);
        $this->callHook('controller_issues_new_before_save', ['params' => $request->request->all(), 'issue' => $issue, 'project' => $issue->project]);
        $errors = $this->issues->save($issue);
        if ([] !== $errors) {
            if ($isApi) {
                return $this->api->errors($request, $errors);
            }

            return $this->renderNew($request, $project, $issue, $errors);
        }
        $this->callHook('controller_issues_new_after_save', ['params' => $request->request->all(), 'issue' => $issue, 'project' => $issue->project]);
        if ($copyFrom) {
            $this->issues->afterCopy($issue);
        }
        if ($isApi) {
            $response = $this->api->render($request, ['issue' => $this->presenter->issue($issue)], 201, IssueApiPresenter::ISSUE_XML_ATTRIBUTES);
            $response->headers->set('Location', $this->generateUrl('issue_show', ['id' => $issue->id], UrlGeneratorInterface::ABSOLUTE_URL));

            return $response;
        }
        if ([] !== $attErrors) {
            $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => \count($attErrors)]));
        }
        $link = '<a href="'.htmlspecialchars($this->generateUrl('issue_show', ['id' => $issue->id])).'" title="'.htmlspecialchars($issue->subject).'">#'.$issue->id.'</a>';
        $this->addFlash('notice_html', $this->l('notice_issue_successful_create', ['id' => $link]));
        if ($request->request->has('continue')) {
            $attrs = ['tracker_id' => $issue->tracker->id];
            if ($issue->parent) {
                $attrs['parent_issue_id'] = $issue->parent->id;
            }
            $url = ['issue' => $attrs];
            if ($back = $request->request->get('back_url')) {
                $url['back_url'] = $back;
            }
            if (null !== $project) {
                return $this->redirectToRoute('issue_new', ['project_id' => $issue->project->identifier] + $url);
            }
            $url['issue']['project_id'] = $issue->project->id;

            return $this->redirectToRoute('issues_new', $url);
        }
        if ($request->request->has('follow')) {
            return $this->redirectToRoute('issue_show', ['id' => $issue->id]);
        }

        return $this->redirectBackOr($request, $this->generateUrl('issue_show', ['id' => $issue->id]));
    }

    /** @var array<int, list<Attachment>> */
    private array $savedAttachments = [];

    private function copySource(Request $request): ?Issue
    {
        $id = self::param($request, 'copy_from');
        if (null === $id || '' === $id) {
            return null;
        }
        $source = $this->em->find(Issue::class, (int) $id);
        if (null === $source || !$this->issues->isVisible($source)) {
            throw $this->createNotFoundException();
        }

        return $source;
    }

    private function linkCopy(Request $request): bool
    {
        return match ($this->settings->string('link_copied_issue')) {
            'yes' => true,
            'no' => false,
            default => '1' === (string) self::param($request, 'link_copy'),
        };
    }

    private function copyAttachments(Request $request): bool
    {
        return match ($this->settings->string('copy_attachments_on_issue_copy')) {
            'yes' => true,
            'no' => false,
            default => '1' === (string) self::param($request, 'copy_attachments'),
        };
    }

    /** @return array<string, mixed> issue params from a form or an API payload */
    private function issueParams(Request $request): array
    {
        if ($this->isApi($request)) {
            $payload = ApiRenderer::payload($request);
            $issue = $payload['issue'] ?? [];
            if (!\is_array($issue)) {
                return [];
            }
            // XML: <custom_field id="1"><value>..</value></custom_field> (ids are attributes)
            if ('xml' === ApiRenderer::format($request) && isset($issue['custom_fields'])) {
                $xml = @simplexml_load_string((string) $request->getContent());
                $cfs = [];
                foreach ($xml && isset($xml->custom_fields) ? $xml->custom_fields->custom_field : [] as $cf) {
                    $value = $cf->value;
                    if (isset($value['type']) && 'array' === (string) $value['type']) {
                        $v = [];
                        foreach ($value->value as $item) {
                            $v[] = (string) $item;
                        }
                    } else {
                        $v = (string) $value;
                    }
                    $cfs[] = ['id' => (string) ($cf['id'] ?? $cf->id), 'value' => $v];
                }
                $issue['custom_fields'] = $cfs;
            }
            // API custom_fields: [{id:, value:}] => custom_field_values
            if (isset($issue['custom_fields']) && \is_array($issue['custom_fields'])) {
                $cfv = [];
                foreach ($issue['custom_fields'] as $cf) {
                    if (\is_array($cf) && isset($cf['id'])) {
                        $cfv[(string) $cf['id']] = $cf['value'] ?? '';
                    }
                }
                $issue['custom_field_values'] = ($issue['custom_field_values'] ?? []) + $cfv;
                unset($issue['custom_fields']);
            }
            if (isset($issue['uploads']['upload'])) {
                $issue['uploads'] = $issue['uploads']['upload'];
            }
            if (isset($issue['uploads']['token'])) {
                $issue['uploads'] = [$issue['uploads']];
            }
            if (isset($issue['watcher_user_ids']['watcher_user_id'])) {
                $issue['watcher_user_ids'] = (array) $issue['watcher_user_ids']['watcher_user_id'];
            }

            return $issue;
        }

        $all = $request->isMethod('GET') ? $request->query->all() : $request->request->all() + $request->query->all();

        return \is_array($all['issue'] ?? null) ? $all['issue'] : [];
    }

    /**
     * IssuesController#build_new_issue_from_params.
     */
    private function buildNewIssue(Request $request, ?Project $project, string $action): Issue|Response
    {
        $user = $this->user();
        $attrs = $this->issueParams($request);
        $copyFrom = $this->copySource($request);
        if ($copyFrom) {
            if (!$this->auth->allowedTo('copy_issues', $copyFrom->project)) {
                $this->deny();
            }
            $isGet = $request->isMethod('GET');
            $issue = $this->issues->copyFrom($copyFrom, $user, [
                'link' => $this->linkCopy($request) || $isGet,
                'attachments' => $this->copyAttachments($request) || $isGet,
                'subtasks' => '' !== (string) self::param($request, 'copy_subtasks', '') || $isGet,
                'watchers' => $this->auth->allowedTo('add_issue_watchers', $project),
            ]);
            if (null !== $project && $project->id !== $issue->project->id) {
                $this->issues->changeProject($issue, $project, true);
            }
        } else {
            $target = $project;
            if (null === $target) {
                $pid = $attrs['project_id'] ?? null;
                $allowed = $this->projects->allowedTargetProjects($user);
                foreach ($allowed as $p) {
                    if (null !== $pid && ((string) $p->id === (string) $pid || $p->identifier === (string) $pid)) {
                        $target = $p;
                    }
                }
                if (null === $target && ($request->isMethod('GET') || 'new' === $action)) {
                    $target = $allowed[0] ?? null;
                }
            }
            if (null === $target) {
                if ($this->isApi($request) || 'create' === $action) {
                    $issue = new Issue();
                    $issue->author = $user;

                    return $issue;
                }
                throw new HttpException(403, $this->l('error_no_projects_with_tracker_allowed_for_new_issue'));
            }
            $issue = $this->issues->newIssue($target, $user);
            $offset = $this->settings->string('default_issue_due_date_offset');
            if ('' !== $offset && is_numeric($offset)) {
                $issue->dueDate = $this->i18n->today($user)->modify('+'.(int) $offset.' days');
            }
        }
        if (null !== $project) {
            unset($attrs['project_id']);
        }
        if ('new' === $action && null !== self::param($request, 'was_default_status') && (string) self::param($request, 'was_default_status') === (string) ($attrs['status_id'] ?? '')) {
            unset($attrs['status_id']);
        }
        $trigger = (string) self::param($request, 'form_update_triggered_by', '');
        if ('new' === $action && 'issue_project_id' === $trigger) {
            unset($attrs['fixed_version_id']);
        }
        if ('new' === $action && \in_array($trigger, ['issue_project_id', 'issue_tracker_id'], true) && '1' !== (string) ($attrs['is_private'] ?? '')) {
            unset($attrs['is_private']);
        }
        if ('me' === ($attrs['assigned_to_id'] ?? null)) {
            $attrs['assigned_to_id'] = $user->id;
        }
        // the API/forms send tracker/status/priority ids as integers or strings
        $this->issues->setSafeAttributes($issue, $attrs, $user);

        if (!isset($issue->tracker)) {
            if ($issue->project->trackers->count() > 0) {
                throw new HttpException(403, $this->l('error_no_tracker_allowed_for_new_issue_in_project'));
            }
            throw new HttpException(500, $this->l('error_no_tracker_in_project'));
        }
        if (!isset($issue->status)) {
            throw new HttpException(500, $this->l('error_no_default_issue_status'));
        }

        return $issue;
    }

    // ------------------------------------------------------------------------------------------
    // Edit / update
    // ------------------------------------------------------------------------------------------

    #[Route('/issues/{id}/edit', name: 'issue_edit', requirements: ['id' => '\d+'], methods: ['GET', 'PATCH'])]
    public function edit(Request $request, int $id): Response
    {
        $issue = $this->findIssue($id);
        $this->authorizeEdit($issue);
        $attrs = $this->issueParams($request);
        if ([] !== $attrs) {
            $this->applyUpdateParams($issue, $attrs);
        }

        return $this->renderEdit($request, $issue);
    }

    /** Form refresh (updateIssueFrom) for an existing issue */
    #[Route('/issues/{id}/edit.js', name: 'issue_form_edit', requirements: ['id' => '\d+'], methods: ['POST', 'PATCH'])]
    public function formEdit(Request $request, int $id): Response
    {
        $issue = $this->findIssue($id);
        $this->authorizeEdit($issue);
        $this->applyUpdateParams($issue, $this->issueParams($request));
        $vars = $this->formVars($request, $issue, $issue->project);
        $js = 'replaceIssueFormWith('.json_encode($this->renderView('issues/_form.html.twig', $vars), \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE).');';
        $js .= $this->auth->allowedTo('log_time', $issue->project) ? "$('#log_time').show();" : "$('#log_time').hide();";
        $js .= $this->issues->isNotesAddable($issue) ? "$('#add_notes').show();$('#add_attachments').show();" : "$('#add_notes').hide();$('#add_attachments').hide();";
        $this->em->detach($issue);

        return $this->js($js);
    }

    private function renderEdit(Request $request, Issue $issue, array $errors = [], array $extra = [], int $status = 200): Response
    {
        return $this->render('issues/edit.html.twig', $extra + $this->editVars($request, $issue) + [
            'project' => $issue->project,
            'issue' => $issue,
            'errors' => $errors,
            'main_menu_selected' => 'issues',
            'page_title' => $issue->tracker->name.' #'.$issue->id.': '.$issue->subject,
            'body_css' => 'controller-issues action-'.($request->isMethod('GET') ? 'edit' : 'update'),
        ], new Response('', $status));
    }

    /** Variables of the issues/_edit partial */
    private function editVars(Request $request, Issue $issue): array
    {
        $timeParams = $request->request->all('time_entry');

        return $this->formVars($request, $issue, $issue->project) + [
            'attributes_editable' => $this->issues->isAttributesEditable($issue),
            'notes_addable' => $this->issues->isNotesAddable($issue),
            'time_loggable' => $this->issues->isTimeLoggable($issue),
            'attachments_addable' => $this->issues->isAttributesEditable($issue) || $this->issues->isNotesAddable($issue),
            'existing_attachments' => $this->attachments->forContainer('Issue', $issue->id),
            'deleted_attachment_ids' => array_map('intval', (array) ($this->issueParams($request)['deleted_attachment_ids'] ?? [])),
            'activities' => $this->activities($issue->project),
            'time_entry' => $timeParams,
            'notes' => $this->issues->pendingNotes($issue),
            'private_notes' => '1' === (string) ($this->issueParams($request)['private_notes'] ?? ''),
            'last_journal_id' => $request->request->get('last_journal_id') ?? $this->lastJournalId($issue),
            'conflict' => false,
            'conflict_journals' => [],
            'saved_attachments' => $this->savedAttachments[spl_object_id($issue)] ?? [],
        ];
    }

    private function lastJournalId(Issue $issue): ?int
    {
        $id = $this->em->getConnection()->fetchOne("SELECT MAX(id) FROM journals WHERE journalized_type = 'Issue' AND journalized_id = ?", [$issue->id]);

        return false === $id || null === $id ? null : (int) $id;
    }

    #[Route('/issues/{id}.{_format}', name: 'issue_update', requirements: ['id' => '\d+', '_format' => 'html|json|xml'], defaults: ['_format' => 'html'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): Response
    {
        $isApi = $this->isApi($request);
        $issue = $this->findIssue($id);
        $this->authorizeEdit($issue);
        $attrs = $this->issueParams($request);
        $resolution = (string) $request->request->get('conflict_resolution', '');
        if ([] !== $attrs && '' !== $resolution) {
            switch ($resolution) {
                case 'overwrite':
                    unset($attrs['lock_version']);
                    break;
                case 'add_notes':
                    $attrs = array_intersect_key($attrs, ['notes' => 1, 'private_notes' => 1]);
                    break;
                case 'cancel':
                    return $this->redirectToRoute('issue_show', ['id' => $issue->id]);
            }
        }
        // optimistic locking (ActiveRecord::StaleObjectError)
        $currentLock = (int) $this->em->getConnection()->fetchOne('SELECT lock_version FROM issues WHERE id = ?', [$issue->id]);
        $conflict = \array_key_exists('lock_version', $attrs) && '' !== (string) $attrs['lock_version'] && (int) $attrs['lock_version'] !== $currentLock;
        unset($attrs['lock_version']);

        // attachments (stored before the issue attributes change: flushing them must not save the issue)
        $uploads = $isApi ? (\is_array($attrs['uploads'] ?? null) ? $attrs['uploads'] : []) : null;
        $attachmentsAddable = $this->issues->isAttributesEditable($issue) || $this->issues->isNotesAddable($issue);
        $atts = [];
        if ($attachmentsAddable && !$conflict) {
            [$atts] = $this->attachments->fromRequest($request, $this->user(), $uploads);
            if ([] !== $atts) {
                $this->em->flush();
                $this->savedAttachments[spl_object_id($issue)] = $atts;
            }
        } elseif (!$attachmentsAddable && ([] !== $request->files->all('attachments') || [] !== (array) $uploads)) {
            $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => \count($request->files->all('attachments')) ?: \count((array) $uploads)]));
        }
        $this->applyUpdateParams($issue, $attrs);
        if ([] !== $atts) {
            $this->issues->addAttachments($issue, $atts);
        }

        if ($conflict) {
            if ($isApi) {
                return new Response('', 409);
            }
            $conflictJournals = [];
            if ($last = $request->request->get('last_journal_id')) {
                foreach ($this->presenter->visibleJournals($issue) as $j) {
                    if ($j->id > (int) $last) {
                        $conflictJournals[] = $j;
                    }
                }
            }

            return $this->renderEdit($request, $issue, [], ['conflict' => true, 'conflict_journals' => $conflictJournals, 'lock_version' => $request->request->all('issue')['lock_version'] ?? null]);
        }

        // time entry logged along with the update
        $timeEntry = null;
        $timeParams = $isApi ? [] : $request->request->all('time_entry');
        $errors = [];
        if ([] !== $timeParams && ('' !== trim((string) ($timeParams['hours'] ?? '')) || '' !== trim((string) ($timeParams['comments'] ?? '')))
            && $this->auth->allowedTo('log_time', $issue->project)) {
            [$timeEntry, $errors] = $this->buildTimeEntry($issue, $timeParams);
        }
        if ([] === $errors) {
            $journalCountBefore = $issue->journals->count();
            $hookContext = ['params' => $request->request->all(), 'issue' => $issue, 'time_entry' => $timeEntry, 'journal' => null, 'project' => $issue->project];
            $this->callHook('controller_issues_edit_before_save', $hookContext);
            $errors = $this->issues->save($issue);
            if ([] === $errors && $timeEntry) {
                $this->em->persist($timeEntry);
                $this->em->flush();
            }
            if ([] === $errors) {
                $hookContext['journal'] = $issue->journals->count() > $journalCountBefore ? ($issue->journals->last() ?: null) : null;
                $this->callHook('controller_issues_edit_after_save', $hookContext);
            }
        }
        if ([] !== $errors) {
            if ($isApi) {
                return $this->api->errors($request, $errors);
            }

            return $this->renderEdit($request, $issue, $errors);
        }
        if ($isApi) {
            return $this->api->noContent();
        }
        if ($issue->journals->count() > ($journalCountBefore ?? 0) && !self::param($request, 'no_flash')) {
            $this->flashNotice('notice_successful_update');
        }

        $this->navigation->rememberFromParams($request);

        return $this->redirectBackOr($request, $this->generateUrl('issue_show', ['id' => $issue->id]));
    }

    /** @return array{0: TimeEntry, 1: list<string>} */
    private function buildTimeEntry(Issue $issue, array $params): array
    {
        $user = $this->user();
        $te = new TimeEntry();
        $te->project = $issue->project;
        $te->issue = $issue;
        $te->user = $user;
        $te->author = $user;
        $te->setSpentOn($this->i18n->today($user));
        $errors = [];
        $hours = IssueService::parseHours($params['hours'] ?? null);
        if (null === $hours) {
            $errors[] = $this->l('field_hours').' '.$this->l('activerecord.errors.messages.'.('' === trim((string) ($params['hours'] ?? '')) ? 'blank' : 'invalid'));
        } elseif ($hours < 0 || (0.0 === $hours && !$this->settings->bool('timelog_accept_0_hours'))) {
            $errors[] = $this->l('field_hours').' '.$this->l('activerecord.errors.messages.invalid');
        } else {
            $te->hours = $hours;
        }
        $te->comments = isset($params['comments']) ? mb_substr((string) $params['comments'], 0, 1024) : null;
        $activity = null;
        foreach ($this->activities($issue->project) as $a) {
            if ((string) $a->id === (string) ($params['activity_id'] ?? '')) {
                $activity = $a;
            }
        }
        if (null === $activity) {
            $errors[] = $this->l('field_activity').' '.$this->l('activerecord.errors.messages.blank');
        } else {
            $te->activity = $activity;
        }
        if (\in_array('comments', $this->settings->array('timelog_required_fields'), true) && '' === trim((string) $te->comments)) {
            $errors[] = $this->l('field_comments').' '.$this->l('activerecord.errors.messages.blank');
        }

        return [$te, $errors];
    }

    /** Project#activities: active system activities, overridden by project specific ones @return list<TimeEntryActivity> */
    private function activities(Project $project): array
    {
        $system = $this->em->createQuery('SELECT a FROM App\Entity\TimeEntryActivity a WHERE a.project IS NULL ORDER BY a.position')->getResult();
        $overrides = $this->em->createQuery('SELECT a FROM App\Entity\TimeEntryActivity a WHERE a.project = :p')->setParameter('p', $project->id)->getResult();
        $byParent = [];
        foreach ($overrides as $o) {
            if ($o->parent) {
                $byParent[$o->parent->id] = $o;
            }
        }
        $out = [];
        foreach ($system as $a) {
            $eff = $byParent[$a->id] ?? $a;
            if ($eff->active) {
                $out[] = $eff;
            }
        }

        return $out;
    }

    private function applyUpdateParams(Issue $issue, array $attrs): void
    {
        if ('me' === ($attrs['assigned_to_id'] ?? null)) {
            $attrs['assigned_to_id'] = $this->user()->id;
        }
        $attrs = self::replaceNoneValues($attrs);
        $this->issues->setSafeAttributes($issue, $attrs);
    }

    private static function replaceNoneValues(array $attrs): array
    {
        foreach ($attrs as $k => $v) {
            if ('__none__' === $v || 'none' === $v) {
                $attrs[$k] = '';
            }
        }
        if (isset($attrs['custom_field_values']) && \is_array($attrs['custom_field_values'])) {
            foreach ($attrs['custom_field_values'] as $k => $v) {
                if (\is_array($v)) {
                    if (false !== ($pos = array_search('__none__', $v, true))) {
                        unset($v[$pos]);
                        $v[] = '';
                    }
                    $attrs['custom_field_values'][$k] = array_values($v);
                } elseif ('__none__' === $v) {
                    $attrs['custom_field_values'][$k] = '';
                }
            }
        }

        return $attrs;
    }

    private function authorizeEdit(Issue $issue): void
    {
        $p = $issue->project;
        if (!$this->auth->allowedTo('edit_issues', $p) && !$this->auth->allowedTo('edit_own_issues', $p) && !$this->auth->allowedTo('add_issue_notes', $p)) {
            $this->deny();
        }
        if (!$this->issues->isEditable($issue)) {
            $this->deny();
        }
    }

    // ------------------------------------------------------------------------------------------
    // Form variables
    // ------------------------------------------------------------------------------------------

    /** Variables used by issues/_form and issues/_attributes */
    private function formVars(Request $request, Issue $issue, ?Project $project): array
    {
        $user = $this->user();
        $safe = $this->issues->safeAttributeNames($issue, $user);
        $required = [];
        foreach (['description', 'assigned_to_id', 'category_id', 'fixed_version_id', 'parent_issue_id', 'start_date', 'due_date', 'estimated_hours', 'done_ratio'] as $a) {
            if ($this->issues->isRequiredAttribute($issue, $a, $issue->isNew() ? ($issue->author ?? $user) : $user)) {
                $required[] = $a;
            }
        }
        // projects
        if ($issue->parent) {
            $projects = array_values(array_filter($this->projects->allowedTargetProjects($user, $issue->isNew() ? null : $issue->project), fn (Project $p) => $this->parentProjectValid($p, $issue->parent)));
        } elseif (null !== $project && $issue->isNew() && null === $this->copySource($request)) {
            $root = $project->getRoot();
            $projects = array_values(array_filter($this->projects->allowedTargetProjects($user), static fn (Project $p) => $p->getRoot()->id === $root->id));
        } else {
            $projects = $this->projects->allowedTargetProjects($user, $issue->isNew() ? null : $issue->project);
        }
        if (isset($issue->project) && !\in_array($issue->project, $projects, true)) {
            $projects[] = $issue->project;
        }
        // trackers
        $trackers = $this->projects->allowedTargetTrackers($issue->project, $user, $issue->isNew() ? null : $this->originalTrackerId($issue));
        if ($issue->isNew() && $issue->parent) {
            $trackers = array_values(array_filter($trackers, static fn (Tracker $t) => $t->id === $issue->tracker->id || $t->isCoreFieldEnabled('parent_issue_id')));
        }
        if (!\in_array($issue->tracker, $trackers, true)) {
            $trackers[] = $issue->tracker;
        }
        // custom fields
        $current = $issue->isNew() ? $this->customFields->defaultValues($this->customFields->availableIssueFields($issue)) : $this->customFields->values('Issue', $issue->id);
        $pending = $this->issues->pendingCustomValues($issue);
        $cfs = [];
        $requiredNames = $this->issues->requiredAttributeNames($issue, $issue->isNew() ? ($issue->author ?? $user) : $user);
        foreach ($this->issues->editableCustomFields($issue, $user) as $cf) {
            $value = \array_key_exists($cf->id, $pending) ? $pending[$cf->id] : (\array_key_exists((string) $cf->id, $pending) ? $pending[(string) $cf->id] : ($current[$cf->id] ?? null));
            $cfs[] = ['field' => $cf, 'value' => $value, 'required' => \in_array((string) $cf->id, $requiredNames, true), 'full_width' => $this->helpers->isFullWidth($cf)];
        }
        $assignable = $this->issues->assignableUsers($issue);
        $watcherCandidates = [];
        $watcherChecked = [];
        if ($issue->isNew() && \in_array('watcher_user_ids', $safe, true)) {
            $submitted = array_filter(array_map('intval', (array) ($this->issueParams($request)['watcher_user_ids'] ?? [])));
            foreach ($submitted as $uid) {
                if ($u = $this->em->find(User::class, $uid)) {
                    if ($u->isActive()) {
                        $watcherCandidates[$u->id] = $u;
                        $watcherChecked[] = $u->id;
                    }
                }
            }
            $assignableWatchers = $this->assignableWatchers($issue->project);
            if (\count($assignableWatchers) <= 20) {
                foreach ($assignableWatchers as $u) {
                    $watcherCandidates[$u->id] ??= $u;
                }
            }
            if ($copy = $this->copySource($request)) {
                foreach ($this->watchers->watchers('Issue', (int) $copy->id) as $w) {
                    if ($request->isMethod('GET')) {
                        $watcherChecked[] = $w->id;
                        $watcherCandidates[$w->id] ??= $w;
                    }
                }
            }
        }

        return [
            'issue' => $issue,
            'project' => $project ?? (isset($issue->project) ? $issue->project : null),
            'form_project' => $project,
            'safe' => $safe,
            'required_attrs' => $required,
            'allowed_statuses' => $this->issues->newStatusesAllowedTo($issue, $user, false, $issue->isNew() ? [] : $this->issues->originalValues($issue)),
            'transition_warning' => $this->issues->closableWarning($issue),
            'priorities' => $this->em->getRepository(IssuePriority::class)->findBy(['active' => true], ['position' => 'ASC']),
            'projects_for_select' => $projects,
            'show_project_select' => (\in_array('project_id', $safe, true)) && (null === $project || \count($projects) > 1 || null !== $this->copySource($request)),
            'trackers_for_select' => $trackers,
            'assignable_users' => $assignable,
            'me_assignable' => \in_array($user, $assignable, true),
            'assignable_versions' => $this->issues->assignableVersions($issue),
            'categories' => $this->categories($issue->project),
            'form_custom_fields' => $cfs,
            'parent_issue_id' => $request->isMethod('GET') && !\array_key_exists('parent_issue_id', $this->issueParams($request)) ? $issue->parent?->id : ($this->issueParams($request)['parent_issue_id'] ?? $issue->parent?->id),
            'watcher_candidates' => array_values($watcherCandidates),
            'watcher_checked' => $watcherChecked,
            'done_ratio_options' => range(0, 100, max(1, $this->settings->int('issue_done_ratio_interval') ?: 10)),
            'use_field_for_done_ratio' => !$this->issues->useStatusForDoneRatio(),
            'is_default_status' => isset($issue->tracker->defaultStatus) && $issue->tracker->defaultStatus?->id === $issue->status->id,
            'back_url' => self::param($request, 'back_url'),
            'form_update_url' => $this->helpers->updateIssueFormPath($project, $issue),
            'preview_url' => $this->generateUrl('preview_issue', array_filter(['project_id' => isset($issue->project) ? $issue->project->identifier : null, 'issue_id' => $issue->id])),
            'parent_autocomplete_url' => $this->generateUrl('issues_auto_complete', array_filter(['project_id' => isset($issue->project) ? $issue->project->identifier : null, 'scope' => $this->settings->string('cross_project_subtasks'), 'status' => $issue->isClosed() ? 'c' : 'o', 'issue_id' => $issue->id])),
        ];
    }

    private function originalTrackerId(Issue $issue): ?int
    {
        $id = $this->em->getConnection()->fetchOne('SELECT tracker_id FROM issues WHERE id = ?', [$issue->id]);

        return false === $id ? null : (int) $id;
    }

    private function parentProjectValid(Project $p, Issue $parent): bool
    {
        $tmp = new Issue();
        $tmp->project = $p;

        return $this->issues->isValidParentProject($tmp, $parent);
    }

    /** @return list<\App\Entity\IssueCategory> */
    private function categories(Project $project): array
    {
        $c = $project->issueCategories->toArray();
        usort($c, static fn ($a, $b) => strcasecmp($a->name, $b->name));

        return $c;
    }

    /** Users that can be added as watchers (Principal.assignable_watchers on project members) @return list<User> */
    private function assignableWatchers(Project $project): array
    {
        $users = array_values(array_filter($this->projects->members($project), static fn ($p) => $p instanceof User && $p->isActive()));
        usort($users, static fn (User $a, User $b) => strcasecmp($a->getName(), $b->getName()));

        return $users;
    }

    // ------------------------------------------------------------------------------------------
    // Bulk edit / update
    // ------------------------------------------------------------------------------------------

    /** @return list<Issue> */
    private function findIssues(Request $request): array
    {
        $ids = self::param($request, 'ids') ?? self::param($request, 'id');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        if ([] === $ids) {
            throw $this->createNotFoundException();
        }
        $issues = $this->em->getRepository(Issue::class)->findBy(['id' => $ids], ['id' => 'ASC']);
        if (\count($issues) !== \count($ids)) {
            throw $this->createNotFoundException();
        }
        foreach ($issues as $i) {
            if (!$this->issues->isVisible($i)) {
                $this->deny();
            }
        }

        return $issues;
    }

    #[Route('/issues/bulk_edit', name: 'issues_bulk_edit', methods: ['GET', 'POST'])]
    #[Route('/issues/bulk_edit.{_format}', name: 'issues_bulk_edit_js', requirements: ['_format' => 'js'], methods: ['GET', 'POST'])]
    public function bulkEdit(Request $request, array $unsaved = [], array $saved = []): Response
    {
        $issues = $this->findIssues($request);
        $copy = '' !== (string) self::param($request, 'copy', '');
        $projects = $this->uniqueProjects($issues);
        foreach ($projects as $p) {
            $this->authorizeBulk($p, $copy);
        }
        if ($copy) {
            foreach ($projects as $p) {
                if (!$this->auth->allowedTo('copy_issues', $p)) {
                    $this->deny();
                }
            }
        } else {
            foreach ($issues as $i) {
                if (!$this->issues->isAttributesEditable($i)) {
                    $this->deny();
                }
            }
        }
        $params = self::param($request, 'issue');
        $params = \is_array($params) ? $params : [];
        $allowedProjects = $this->projects->allowedTargetProjects();
        $targetProject = null;
        foreach ($allowedProjects as $p) {
            if ((string) $p->id === (string) ($params['project_id'] ?? '')) {
                $targetProject = $p;
            }
        }
        $targetProjects = $targetProject ? [$targetProject] : $projects;
        $trackers = null;
        foreach ($targetProjects as $p) {
            $t = $this->projects->allowedTargetTrackers($p);
            $trackers = null === $trackers ? $t : array_values(array_filter($trackers, static fn ($x) => \in_array($x, $t, true)));
        }
        // like Redmine, the edited issues get the target project/tracker/status (in memory only,
        // restored below) so that statuses, custom fields and safe attributes match the new values
        $originals = array_map(static fn (Issue $i) => [$i->project, $i->tracker, $i->status], $issues);
        $targetTracker = null;
        foreach ($trackers ?? [] as $t) {
            if ((string) $t->id === (string) ($params['tracker_id'] ?? '')) {
                $targetTracker = $t;
            }
        }
        foreach ($issues as $i) {
            if ($targetProject) {
                $i->project = $targetProject;
            }
            if ($targetTracker) {
                $i->tracker = $targetTracker;
            }
        }
        $statuses = [];
        if (!$copy) {
            $statuses = null;
            foreach ($issues as $i) {
                $s = $this->issues->newStatusesAllowedTo($i);
                $statuses = null === $statuses ? $s : array_values(array_filter($statuses, static fn ($x) => \in_array($x, $s, true)));
            }
        }
        foreach ($statuses ?? [] as $st) {
            if ((string) $st->id === (string) ($params['status_id'] ?? '')) {
                foreach ($issues as $i) {
                    $i->status = $st;
                }
            }
        }
        $assignables = null;
        $versions = null;
        $categories = null;
        foreach ($targetProjects as $p) {
            $a = $this->projects->assignableUsers($p);
            $assignables = null === $assignables ? $a : array_values(array_filter($assignables, static fn ($x) => \in_array($x, $a, true)));
            $v = array_values(array_filter($this->projects->sharedVersions($p), static fn ($x) => $x->isOpen()));
            $versions = null === $versions ? $v : array_values(array_filter($versions, static fn ($x) => \in_array($x, $v, true)));
            $names = array_map(static fn ($c) => $c->name, $p->issueCategories->toArray());
            $c = $p->issueCategories->toArray();
            $categories = null === $categories ? $c : array_values(array_filter($categories, static fn ($x) => \in_array($x->name, $names, true)));
        }
        $customFields = null;
        $safe = null;
        foreach ($issues as $i) {
            $f = $this->issues->editableCustomFields($i);
            $customFields = null === $customFields ? $f : array_values(array_filter($customFields, static fn ($x) => \in_array($x, $f, true)));
            $s = $this->issues->safeAttributeNames($i);
            $safe = null === $safe ? $s : array_values(array_intersect($safe, $s));
        }
        foreach ($issues as $k => $i) {
            [$i->project, $i->tracker, $i->status] = $originals[$k];
        }
        $customFields = array_values(array_filter($customFields ?? [], static fn (CustomField $cf) => !\in_array($cf->fieldFormat, ['attachment'], true)));
        $values = $this->customFields->valuesFor('Issue', array_map(static fn ($i) => $i->id, $issues));
        $valuesByField = [];
        foreach ($customFields as $cf) {
            $without = [];
            foreach ($issues as $i) {
                $v = $values[$i->id][$cf->id] ?? null;
                if ([] === array_filter((array) $v, static fn ($x) => null !== $x && '' !== $x)) {
                    continue;
                }
                $without[] = $i->id;
            }
            // issues for which the field is not available
            $missing = array_values(array_filter($issues, fn (Issue $i) => !\in_array($cf, $this->customFields->availableIssueFields($i), true)));
            if ([] !== $missing) {
                $valuesByField[$cf->id] = array_map(static fn ($i) => $i->id, $missing);
            }
            unset($without);
        }
        $project = 1 === \count($projects) ? $projects[0] : null;
        $hasAttachments = false;
        foreach ($issues as $i) {
            if ([] !== $this->attachments->forContainer('Issue', $i->id)) {
                $hasAttachments = true;
            }
        }
        $watchersPresent = false;
        if ($copy) {
            $allowedWatchers = true;
            foreach ($projects as $p) {
                $allowedWatchers = $allowedWatchers && $this->auth->allowedTo('add_issue_watchers', $p);
            }
            $watchersPresent = $allowedWatchers && (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM watchers WHERE watchable_type = 'Issue' AND watchable_id IN (".implode(',', array_map(static fn ($i) => (int) $i->id, $issues)).')') > 0;
        }

        $vars = [
            'issues' => $issues,
            'copy' => $copy,
            'project' => $project,
            'projects' => $projects,
            'allowed_projects' => $allowedProjects,
            'target_project' => $targetProject,
            'trackers' => $trackers ?? [],
            'available_statuses' => $statuses ?? [],
            'priorities' => $this->em->getRepository(IssuePriority::class)->findBy(['active' => true], ['position' => 'ASC']),
            'assignables' => $assignables ?? [],
            'versions' => $versions ?? [],
            'categories' => $categories ?? [],
            'custom_fields' => $customFields,
            'values_by_custom_field' => $valuesByField,
            'safe' => $safe ?? [],
            'issue_params' => $params,
            'notes' => self::param($request, 'notes'),
            'attachments_present' => $copy && $hasAttachments && 'ask' === $this->settings->string('copy_attachments_on_issue_copy'),
            'subtasks_present' => $copy && [] !== array_filter($issues, static fn (Issue $i) => !$i->isLeaf()),
            'watchers_present' => $watchersPresent,
            'unsaved_issues' => $unsaved,
            'saved_issues' => $saved,
            'done_ratio_options' => range(0, 100, max(1, $this->settings->int('issue_done_ratio_interval') ?: 10)),
            'use_field_for_done_ratio' => !$this->issues->useStatusForDoneRatio(),
            'back_url' => self::param($request, 'back_url'),
            'main_menu_selected' => 'issues',
            'page_title' => $this->l($copy ? 'button_copy' : 'label_bulk_edit_selected_issues'),
            'body_css' => 'controller-issues action-bulk_edit',
        ];
        if ('js' === $request->attributes->get('_format')) {
            // bulk_edit.js.erb: updateBulkEditFrom() replaces the form after a project/tracker/status change
            $html = $this->container->get('twig')->load('issues/bulk_edit.html.twig')->renderBlock('content', $vars);

            return $this->js("$('#content').html(".json_encode($html, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG).');');
        }

        return $this->render('issues/bulk_edit.html.twig', $vars);
    }

    #[Route('/issues/bulk_update', name: 'issues_bulk_update', methods: ['POST', 'PATCH'])]
    public function bulkUpdate(Request $request): Response
    {
        $issues = $this->findIssues($request);
        $copy = '' !== (string) self::param($request, 'copy', '');
        $projects = $this->uniqueProjects($issues);
        // Rails params: query string (context menu links) merged with the form body
        $attributes = array_replace($request->query->all('issue'), $request->request->all('issue'));
        // parse_params_for_bulk_update: drop blank values
        $attributes = array_filter($attributes, static fn ($v) => !(null === $v || '' === $v || (\is_array($v) && [] === $v)));
        if (isset($attributes['custom_field_values']) && \is_array($attributes['custom_field_values'])) {
            $attributes['custom_field_values'] = array_filter($attributes['custom_field_values'], static fn ($v) => !(null === $v || '' === $v || (\is_array($v) && [] === array_filter($v, static fn ($x) => '' !== $x))));
        }
        $attributes = self::replaceNoneValues($attributes);
        $copySubtasks = '1' === (string) $request->request->get('copy_subtasks');
        $copyWatchers = '1' === (string) $request->request->get('copy_watchers');
        if ($copy) {
            foreach ($projects as $p) {
                if (!$this->auth->allowedTo('copy_issues', $p)) {
                    $this->deny();
                }
            }
            $targets = $projects;
            if (!empty($attributes['project_id'])) {
                $targets = array_filter([$this->em->find(Project::class, (int) $attributes['project_id'])]);
            }
            foreach ($targets as $p) {
                if (!$this->auth->allowedTo('add_issues', $p)) {
                    $this->deny();
                }
            }
            foreach ($projects as $p) {
                if (!$this->auth->allowedTo('add_issue_watchers', $p)) {
                    $copyWatchers = false;
                }
            }
        } else {
            foreach ($issues as $i) {
                if (!$this->issues->isAttributesEditable($i)) {
                    $this->deny();
                }
            }
        }
        if ($copy && $copySubtasks) {
            $issues = array_values(array_filter($issues, static function (Issue $i) use ($issues) {
                foreach ($issues as $o) {
                    if ($i->isDescendantOf($o)) {
                        return false;
                    }
                }

                return true;
            }));
        }
        $notes = (string) $request->request->get('notes', '');
        $unsaved = [];
        $saved = [];
        foreach ($issues as $orig) {
            $this->em->refresh($orig);
            if ($copy) {
                $issue = $this->issues->copyFrom($orig, null, ['attachments' => $this->copyAttachments($request), 'subtasks' => $copySubtasks, 'watchers' => $copyWatchers, 'link' => $this->linkCopy($request)]);
            } else {
                $issue = $orig;
            }
            $this->issues->setSafeAttributes($issue, $attributes);
            if ('' !== $notes) {
                $this->issues->setNotes($issue, $notes, false);
            }
            $this->callHook('controller_issues_bulk_edit_before_save', ['params' => $request->request->all(), 'issue' => $issue, 'project' => $issue->project]);
            $journalCount = $issue->journals->count();
            if ([] === $this->issues->save($issue)) {
                if ($copy) {
                    $this->issues->afterCopy($issue);
                }
                $this->callHook('controller_issues_bulk_edit_after_save', ['params' => $request->request->all(), 'issue' => $issue, 'journal' => $issue->journals->count() > $journalCount ? ($issue->journals->last() ?: null) : null, 'project' => $issue->project]);
                $saved[] = $issue;
            } else {
                $unsaved[] = $orig;
            }
        }
        if ([] === $unsaved) {
            if ([] !== $saved) {
                $this->flashNotice('notice_successful_update');
            }
            if ($request->request->has('follow')) {
                if (1 === \count($issues) && 1 === \count($saved)) {
                    return $this->redirectToRoute('issue_show', ['id' => $saved[0]->id]);
                }
                $ps = array_unique(array_map(static fn (Issue $i) => $i->project->identifier, $saved));
                if (1 === \count($ps)) {
                    return $this->redirect($this->issuesListUrl($saved[0]->project));
                }
            }
            $project = 1 === \count($projects) ? $projects[0] : null;

            return $this->redirectBackOr($request, $this->issuesListUrl($project));
        }
        $this->flashError($this->l('notice_failed_to_save_issues', ['count' => \count($unsaved), 'total' => \count($issues), 'ids' => implode(', ', array_map(static fn ($i) => '#'.$i->id, $unsaved))]));
        $request->query->set('ids', array_map(static fn ($i) => $i->id, $unsaved));
        $request->request->remove('ids');

        return $this->bulkEdit($request, $unsaved, $saved);
    }

    private function issuesListUrl(?Project $project): string
    {
        try {
            return null !== $project ? $this->generateUrl('project_issues', ['project_id' => $project->identifier]) : $this->generateUrl('issues_index');
        } catch (\Symfony\Component\Routing\Exception\RouteNotFoundException) {
            return $this->generateUrl('home');
        }
    }

    /** @param list<Issue> $issues @return list<Project> */
    private function uniqueProjects(array $issues): array
    {
        $out = [];
        foreach ($issues as $i) {
            $out[$i->project->id] = $i->project;
        }

        return array_values($out);
    }

    private function authorizeBulk(Project $p, bool $copy): void
    {
        if ($copy) {
            return;
        }
        if (!$this->auth->allowedTo('edit_issues', $p) && !$this->auth->allowedTo('edit_own_issues', $p)) {
            $this->deny();
        }
    }

    // ------------------------------------------------------------------------------------------
    // Destroy
    // ------------------------------------------------------------------------------------------

    #[Route('/issues/{id}.{_format}', name: 'issue_destroy', requirements: ['id' => '\d+', '_format' => 'html|json|xml'], defaults: ['_format' => 'html'], methods: ['DELETE'])]
    public function destroy(Request $request, int $id): Response
    {
        $request->query->set('ids', [$id]);

        return $this->destroyIssues($request);
    }

    #[Route('/issues', name: 'issues_destroy', methods: ['DELETE'])]
    public function destroyMany(Request $request): Response
    {
        return $this->destroyIssues($request);
    }

    private function destroyIssues(Request $request): Response
    {
        $isApi = $this->isApi($request);
        $issues = $this->findIssues($request);
        foreach ($issues as $i) {
            if (!$this->auth->allowedTo('delete_issues', $i->project) || !$this->issues->isDeletable($i)) {
                $this->deny();
            }
        }
        $projects = $this->uniqueProjects($issues);
        $project = 1 === \count($projects) ? $projects[0] : null;
        $ids = [];
        foreach ($issues as $i) {
            $ids[] = $i->id;
            foreach ($i->getDescendants() as $d) {
                $ids[] = $d->id;
            }
        }
        $hours = $this->issues->spentHoursOn($ids);
        $todo = (string) self::param($request, 'todo', '');
        $reassignTo = null;
        if ($hours > 0) {
            $error = null;
            switch ($todo) {
                case 'destroy':
                    break;
                case 'nullify':
                    if (\in_array('issue_id', $this->settings->array('timelog_required_fields'), true)) {
                        $error = $this->l('field_issue').' '.$this->l('activerecord.errors.messages.blank');
                    }
                    break;
                case 'reassign':
                    $target = $project ? $this->em->find(Issue::class, (int) self::param($request, 'reassign_to_id')) : null;
                    if (null === $target || $target->project->id !== $project->id) {
                        $error = $this->l('error_issue_not_found_in_project');
                    } elseif (\in_array($target->id, $ids, true)) {
                        $error = $this->l('error_cannot_reassign_time_entries_to_an_issue_about_to_be_deleted');
                    } else {
                        $reassignTo = $target;
                    }
                    break;
                default:
                    if (!$isApi) {
                        $error = '';
                    } else {
                        $todo = 'destroy';
                    }
            }
            if (null !== $error) {
                return $this->render('issues/destroy.html.twig', [
                    'issues' => $issues,
                    'project' => $project,
                    'hours' => $hours,
                    'error' => '' === $error ? null : $error,
                    'reassign_to_id' => self::param($request, 'reassign_to_id'),
                    'back_url' => self::param($request, 'back_url'),
                    'main_menu_selected' => 'issues',
                    'page_title' => $this->l('label_confirmation'),
                    'body_css' => 'controller-issues action-destroy',
                ]);
            }
        }
        if ($reassignTo) {
            $this->em->getConnection()->executeStatement('UPDATE time_entries SET project_id = ? WHERE issue_id IN (?)', [$reassignTo->project->id, $ids], [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }
        $this->issues->destroy($issues, '' === $todo ? 'destroy' : $todo, $reassignTo?->id);
        if ($isApi) {
            return $this->api->noContent();
        }
        $this->flashNotice('notice_successful_delete');
        $project = null !== $project ? $this->em->find(Project::class, $project->id) : null;

        return $this->redirectBackOr($request, $this->issuesListUrl($project));
    }

    // ------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------

    private function findIssue(int $id): Issue
    {
        $issue = $this->em->find(Issue::class, $id);
        if (null === $issue) {
            throw $this->createNotFoundException();
        }
        if (!$this->issues->isVisible($issue)) {
            $this->deny();
        }

        return $issue;
    }

    /** @return list<string> */
    private function includes(Request $request): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $request->query->get('include', '')))));
    }

    /** Request parameter from the body, then the query string (Request::get() is deprecated) */
    private static function param(Request $request, string $key, mixed $default = null): mixed
    {
        if ($request->request->has($key)) {
            return $request->request->all()[$key];
        }
        if ($request->query->has($key)) {
            return $request->query->all()[$key];
        }

        return $request->attributes->get($key, $default);
    }

    private function js(string $js): Response
    {
        return new Response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }
}

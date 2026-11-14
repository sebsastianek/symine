<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Issue;
use App\Entity\Project;
use App\I18n\Localizer;
use App\Security\AccessGuard;
use App\Security\ApiRequestMatcher;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\IssueService;
use App\Service\ProjectService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The "new issue" form (issues#new / #create, also used to copy an issue): builds the issue from the submitted
 * parameters, saves it and gives the variables of the page.
 */
final class NewIssueForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUser $currentUser,
        private readonly Authorization $auth,
        private readonly AccessGuard $guard,
        private readonly IssueService $issues,
        private readonly ProjectService $projects,
        private readonly AttachmentService $attachments,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly IssueCopyOptions $copy,
        private readonly IssueFormView $form,
    ) {
    }

    /**
     * find_optional_project + authorize_global of issues#new/create: the user needs add_issues or copy_issues
     * (on the project, or on any project without one), anonymous users are sent to the login page.
     */
    public function authorize(?Project $project): void
    {
        $global = null === $project;
        if (!$this->auth->allowedTo('add_issues', $project, null, $global) && !$this->auth->allowedTo('copy_issues', $project, null, $global)) {
            $this->guard->deny();
        }
    }

    /**
     * IssuesController#build_new_issue_from_params.
     *
     * @param 'new'|'create' $action
     */
    public function build(Request $request, ?Project $project, string $action): Issue
    {
        $user = $this->currentUser->get();
        $attrs = IssueParams::issue($request);
        $copyFrom = $this->copy->source($request);
        if ($copyFrom) {
            if (!$this->auth->allowedTo('copy_issues', $copyFrom->project)) {
                $this->guard->deny();
            }
            $isGet = $request->isMethod('GET');
            $issue = $this->issues->copyFrom($copyFrom, $user, [
                'link' => $this->copy->link($request) || $isGet,
                'attachments' => $this->copy->attachments($request) || $isGet,
                'subtasks' => $this->copy->subtasks($request) || $isGet,
                'watchers' => $this->auth->allowedTo('add_issue_watchers', $project),
            ]);
            // @issue.parent_issue_id = @copy_from.parent_id (Issue#copy_from itself does not copy the parent)
            $issue->parent = $copyFrom->parent;
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
                if (ApiRequestMatcher::isApiRequest($request) || 'create' === $action) {
                    $issue = new Issue();
                    $issue->author = $user;

                    return $issue;
                }
                throw new HttpException(403, $this->i18n->l('error_no_projects_with_tracker_allowed_for_new_issue'));
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
        if ('new' === $action && null !== IssueParams::get($request, 'was_default_status') && (string) IssueParams::get($request, 'was_default_status') === (string) ($attrs['status_id'] ?? '')) {
            unset($attrs['status_id']);
        }
        $trigger = (string) IssueParams::get($request, 'form_update_triggered_by', '');
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
                throw new HttpException(403, $this->i18n->l('error_no_tracker_allowed_for_new_issue_in_project'));
            }
            throw new HttpException(500, $this->i18n->l('error_no_tracker_in_project'));
        }
        if (!isset($issue->status)) {
            throw new HttpException(500, $this->i18n->l('error_no_default_issue_status'));
        }

        return $issue;
    }

    /**
     * Creates the issue submitted by the form or the API (issues#create).
     *
     * @param \Closure(string, array<string, mixed>): mixed $hook call_hook of the calling controller
     */
    public function submit(Request $request, ?Project $project, \Closure $hook): IssueCreation
    {
        $isApi = ApiRequestMatcher::isApiRequest($request);
        $this->authorize($project);
        $issue = $this->build($request, $project, 'create');
        if (!isset($issue->project)) {
            if ($isApi) {
                // same errors as Redmine: without project there is no tracker nor default status
                return new IssueCreation($issue, false, $this->issues->validate($issue));
            }
            throw new HttpException(422, $this->i18n->l('error_no_projects_with_tracker_allowed_for_new_issue'));
        }
        if (!$this->auth->allowedTo('add_issues', $issue->project)) {
            $this->guard->deny();
        }
        $params = IssueParams::issue($request);
        [$atts, $attErrors] = $this->attachments->fromRequest($request, $this->currentUser->get(), $isApi ? (\is_array($params['uploads'] ?? null) ? $params['uploads'] : []) : null);
        if ([] !== $atts) {
            $this->em->flush();
            $this->issues->addAttachments($issue, $atts);
        }
        $copyFrom = $this->copy->source($request);
        $hook('controller_issues_new_before_save', ['params' => $request->request->all(), 'issue' => $issue, 'project' => $issue->project]);
        $errors = $this->issues->save($issue);
        if ([] !== $errors) {
            return new IssueCreation($issue, false, $errors, $atts);
        }
        $hook('controller_issues_new_after_save', ['params' => $request->request->all(), 'issue' => $issue, 'project' => $issue->project]);
        if ($copyFrom) {
            $this->issues->afterCopy($issue);
        }

        return new IssueCreation($issue, true, [], $atts, \count($attErrors));
    }

    /**
     * Variables of issues/new: a new issue built from the request parameters, or the issue that could not be
     * created with its errors.
     *
     * @return array<string, mixed>
     */
    public function view(Request $request, ?Project $project, ?IssueCreation $failed = null): array
    {
        if (null === $failed) {
            $this->authorize($project);
        }
        $issue = $failed?->issue ?? $this->build($request, $project, 'new');
        $vars = $this->form->vars($request, $issue, $project);
        $copyFrom = $this->copy->source($request);

        return $vars + [
            'project' => $project,
            'errors' => $failed?->errors ?? [],
            'copy_from' => $copyFrom,
            'link_copy' => $this->copy->link($request) || $request->isMethod('GET'),
            'copy_attachments' => $this->copy->attachments($request) || $request->isMethod('GET'),
            'copy_subtasks' => $this->copy->subtasks($request) || $request->isMethod('GET'),
            'copy_from_has_attachments' => $copyFrom && [] !== $this->attachments->forContainer('Issue', $copyFrom->id),
            'saved_attachments' => $failed?->savedAttachments ?? [],
            'main_menu_selected' => '1' === $this->settings->string('new_item_menu_tab') ? 'new_issue' : 'issues',
            'page_title' => $this->i18n->l('label_issue_new'),
            'body_css' => 'controller-issues action-'.($request->isMethod('GET') ? 'new' : 'create'),
        ];
    }

    /**
     * Variables of the form refreshed after a field was changed (updateIssueFrom): issues/_form and
     * issues/_watchers_form.
     *
     * @return array<string, mixed>
     */
    public function refresh(Request $request, ?Project $project): array
    {
        return $this->form->vars($request, $this->build($request, $project, 'new'), $project);
    }

    /**
     * Parameters of the next "new issue" form after "Create and add another": same tracker and parent, and
     * the project when the form is the one without project.
     *
     * @return array<string, mixed>
     */
    public function continueParams(Request $request, Issue $issue, ?Project $project): array
    {
        $attrs = ['tracker_id' => $issue->tracker->id];
        if ($issue->parent) {
            $attrs['parent_issue_id'] = $issue->parent->id;
        }
        if (null === $project) {
            $attrs['project_id'] = $issue->project->id;
        }
        $url = ['issue' => $attrs];
        if ($back = $request->request->get('back_url')) {
            $url['back_url'] = $back;
        }

        return $url;
    }
}

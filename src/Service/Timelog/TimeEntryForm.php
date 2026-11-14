<?php

declare(strict_types=1);

namespace App\Service\Timelog;

use App\Api\ApiRenderer;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\TimeEntry;
use App\I18n\Localizer;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\IssueService;
use App\Service\Settings;
use App\Service\TimeEntryService;
use App\Twig\TimelogExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The form logging or editing one time entry (timelog/new, timelog/edit) and its API counterpart.
 *
 * Fields: time_entry[project_id|issue_id|user_id|spent_on|hours|comments|activity_id|custom_field_values],
 * project_id / issue_id (where the time is logged, when the route does not say), continue, back_url.
 */
final class TimeEntryForm
{
    public function __construct(
        private readonly TimeEntryService $timeEntries,
        private readonly TimelogScope $scope,
        private readonly Authorization $auth,
        private readonly AccessGuard $guard,
        private readonly CurrentUser $currentUser,
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly IssueService $issues,
        private readonly TimelogExtension $helper,
    ) {
    }

    /** The entry of the "new" form, in the project or the issue of the request, with the values given to prefill it */
    public function blank(Request $request, ?string $projectId, ?int $issueId): TimeEntryDraft
    {
        if (null === $projectId && null === $issueId && $request->attributes->get('_js')) {
            if ($request->request->get('project_id')) {
                $projectId = (string) $request->request->get('project_id');
            } elseif ($request->request->get('issue_id')) {
                $issueId = (int) $request->request->get('issue_id');
            }
        }
        // find_optional_issue / find_optional_project: params[:issue_id] and params[:project_id] may come from the query string
        if (null === $projectId && null === $issueId) {
            if (null !== ($param = TimelogScope::param($request, 'issue_id'))) {
                $issueId = (int) $param;
            } else {
                $projectId = TimelogScope::param($request, 'project_id');
            }
        }
        [$project, $issue] = $this->scope->issueOrProject($projectId, $issueId);
        $entry = $this->timeEntries->newEntry($project, $issue);
        $this->prefill($entry, $request);

        return new TimeEntryDraft($entry, $project, $issue);
    }

    /** Starts editing the entry: the values given in the request (the form being refreshed) are applied to it */
    public function edit(TimeEntry $entry, Request $request): void
    {
        $this->timeEntries->snapshot($entry);
        $this->prefill($entry, $request);
    }

    /**
     * Logs the submitted entry. The draft carries the errors when it could not be saved.
     *
     * @param \Closure(TimeEntry): mixed $beforeSave called with the entry once it is filled in, before it is saved
     */
    public function create(Request $request, ?string $projectId, ?int $issueId, bool $isApi, \Closure $beforeSave): TimeEntryDraft
    {
        $attrs = self::submitted($request, $isApi);
        // the project/issue may also be given in the params (form hidden fields / API)
        if (null === $projectId && null === $issueId) {
            // params[:issue_id] / params[:project_id]: form fields, query string or top level keys of an API payload
            $payload = $isApi ? ApiRenderer::payload($request) : [];
            $topIssue = TimelogScope::param($request, 'issue_id') ?? (\is_scalar($payload['issue_id'] ?? null) && '' !== (string) $payload['issue_id'] ? (string) $payload['issue_id'] : null);
            $topProject = TimelogScope::param($request, 'project_id') ?? (\is_scalar($payload['project_id'] ?? null) && '' !== (string) $payload['project_id'] ? (string) $payload['project_id'] : null);
            if ($request->request->has('project_id')) {
                $projectId = (string) $request->request->get('project_id');
            } elseif ($request->request->has('issue_id')) {
                $issueId = (int) $request->request->get('issue_id');
            } elseif (null !== $topIssue) {
                $issueId = (int) $topIssue;
            } elseif (null !== $topProject) {
                $projectId = $topProject;
            } elseif (isset($attrs['issue_id']) && '' !== (string) $attrs['issue_id'] && !isset($attrs['project_id'])) {
                $issueId = (int) $attrs['issue_id'];
            }
        }
        if (null !== $issueId && null === $this->em->find(Issue::class, $issueId) && null === $projectId) {
            if ($isApi) {
                // same errors as Redmine (the project of the unknown issue is blank, hence invalid)
                return new TimeEntryDraft(new TimeEntry(), null, null, [
                    $this->i18n->l('field_project').' '.$this->i18n->l('activerecord.errors.messages.blank'),
                    $this->i18n->l('field_project').' '.$this->i18n->l('activerecord.errors.messages.invalid'),
                    $this->i18n->l('field_issue').' '.$this->i18n->l('activerecord.errors.messages.invalid'),
                ]);
            }
            throw new NotFoundHttpException();
        }
        [$project, $issue] = $this->scope->issueOrProject($projectId, $issueId);
        $entry = $this->timeEntries->newEntry($project, $issue);
        $entry->user = $this->currentUser->get();
        $this->timeEntries->setAttributes($entry, $attrs);
        if (isset($entry->project) && !$this->auth->allowedTo('log_time', $entry->project)) {
            $this->guard->deny();
        }
        $beforeSave($entry);

        return new TimeEntryDraft($entry, $project, $issue, $this->timeEntries->save($entry), $projectId, $issueId, $attrs);
    }

    /**
     * Saves the entry with the submitted values.
     *
     * @param \Closure(TimeEntry): mixed $beforeSave called with the entry once it is filled in, before it is saved
     *
     * @return list<string> the errors (empty when saved)
     */
    public function update(TimeEntry $entry, Request $request, bool $isApi, \Closure $beforeSave): array
    {
        $this->timeEntries->snapshot($entry);
        $this->timeEntries->setAttributes($entry, self::submitted($request, $isApi));
        $beforeSave($entry);
        $errors = $this->timeEntries->save($entry);
        if ([] !== $errors && $isApi) {
            // nothing is shown again to an API client: the rejected values are dropped
            $this->em->refresh($entry);
        }

        return $errors;
    }

    /**
     * Where "Create and continue" goes: the form the entry was logged from, prefilled like the entry just created.
     *
     * @return array{0: string, 1: array<string, mixed>} route name and parameters
     */
    public function continueRoute(Request $request, TimeEntryDraft $draft): array
    {
        $entry = $draft->entry;
        $options = ['time_entry' => array_filter([
            'project_id' => $draft->attrs['project_id'] ?? null,
            'issue_id' => $entry->issue?->id,
            'spent_on' => $entry->spentOn->format('Y-m-d'),
            'activity_id' => $entry->activity->id,
        ], static fn ($v) => null !== $v && '' !== $v)];
        if ($request->request->get('back_url')) {
            $options['back_url'] = (string) $request->request->get('back_url');
        }
        if (null !== $draft->projectId && isset($entry->project)) {
            $options['time_entry']['project_id'] ??= $entry->project->id;

            return ['time_entry_new', ['project_id' => $entry->project->identifier] + $options];
        }
        if (null !== $draft->issueId && null !== $entry->issue) {
            return ['issue_time_entry_new', ['issue_id' => $entry->issue->id] + $options];
        }

        return ['time_entry_new_global', $options];
    }

    /**
     * What the form shows (timelog/_form).
     *
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    public function view(Request $request, TimeEntry $entry, ?Project $project, ?Issue $issue = null, array $errors = []): array
    {
        $formProject = $entry->project ?? $project;
        $activities = $this->timeEntries->activities(isset($entry->project) ? $entry->project : $project);
        $hasDefault = false;
        foreach ($activities as $a) {
            $hasDefault = $hasDefault || $a->isDefault;
        }
        $users = [];
        if ($this->auth->allowedTo('log_time_for_other_users', $formProject)) {
            $users = $this->timeEntries->assignableUsers($formProject);
            if (isset($entry->user) && !\in_array($entry->user->id, array_map(static fn ($u) => $u->id, $users), true)) {
                $users[] = $entry->user;
            }
        }
        // _form.html.erb: @time_entry.editable_custom_field_values, also when no project is selected yet
        if (!isset($entry->project) && null !== $project) {
            $entry->project = $project;
        }

        return [
            'entry' => $entry,
            'project' => $project,
            'issue' => $issue,
            'errors' => $errors,
            'activities' => $activities,
            'blank_activity' => (isset($entry->activity) && !$entry->activity->active) || !$hasDefault,
            'users' => $users,
            'log_for_others' => $this->auth->allowedTo('log_time_for_other_users', $formProject),
            'log_time_projects' => $this->scope->logTimeProjects(),
            'custom_fields' => $this->timeEntries->editableCustomFields($entry),
            'custom_values' => $this->timeEntries->customValues($entry),
            'hours_value' => $this->hoursValue($entry, $request),
            'required_fields' => $this->settings->array('timelog_required_fields'),
            'route_project_id' => $request->attributes->get('project_id') ?? $request->request->get('project_id') ?? TimelogScope::param($request, 'project_id'),
            'route_issue_id' => $request->attributes->get('issue_id') ?? $request->request->get('issue_id') ?? TimelogScope::param($request, 'issue_id'),
            'back_url' => TimelogBackUrl::param($request),
            'main_menu_selected' => 'time_entries',
            'body_css' => 'controller-timelog action-'.(null === $entry->id ? 'new' : 'edit'),
            'page_title' => $this->i18n->l('label_spent_time'),
        ];
    }

    /** new.js.erb / edit.js.erb: refreshes activities and the issue link */
    public function refreshScript(TimeEntry $entry, ?Project $project): string
    {
        $activities = $this->timeEntries->activities(isset($entry->project) ? $entry->project : $project);
        $hasDefault = false;
        foreach ($activities as $a) {
            $hasDefault = $hasDefault || $a->isDefault;
        }
        $selected = isset($entry->activity) ? $entry->activity->id : null;
        if (null === $entry->id) {
            $selected = $this->timeEntries->defaultActivityId($this->currentUser->get(), isset($entry->project) ? $entry->project : null) ?? $selected;
            if (isset($entry->activity) && \in_array($entry->activity->id, array_map(static fn ($a) => $a->id, $activities), true)) {
                $selected = $entry->activity->id;
            }
        }
        $options = '';
        if ((isset($entry->activity) && !$entry->activity->active) || !$hasDefault) {
            $options .= '<option value="">--- '.htmlspecialchars($this->i18n->l('actionview_instancetag_blank_option')).' ---</option>';
        }
        foreach ($activities as $a) {
            $options .= '<option'.($a->id === $selected ? ' selected="selected"' : '').' value="'.$a->id.'">'.htmlspecialchars($a->name).'</option>';
        }
        $issueLink = null !== $entry->issue && $this->issues->isVisible($entry->issue) ? $this->helper->formatObject($entry->issue, true) : '';

        return "\$('#time_entry_activity_id').html('".TimelogJs::escape($options)."');\n\$('#time_entry_issue').html('".TimelogJs::escape($issueLink)."');\n";
    }

    /**
     * params[:time_entry]: of the API payload, or of the form
     *
     * @return array<string, mixed>
     */
    private static function submitted(Request $request, bool $isApi): array
    {
        return $isApi ? (array) (ApiRenderer::payload($request)['time_entry'] ?? []) : $request->request->all('time_entry');
    }

    /** Applies the values of the form being shown again: posted (the form refreshing itself) or given in the URL */
    private function prefill(TimeEntry $entry, Request $request): void
    {
        $attrs = $request->request->all('time_entry') ?: $request->query->all('time_entry');
        if ([] !== $attrs) {
            $this->timeEntries->setAttributes($entry, $attrs);
        }
    }

    private function hoursValue(TimeEntry $entry, Request $request): string
    {
        $attrs = $request->request->all('time_entry') ?: $request->query->all('time_entry');
        if (\array_key_exists('hours', $attrs)) {
            return (string) $attrs['hours'];
        }
        if (null === $entry->id && 0.0 === $entry->hours) {
            return '';
        }

        return $this->i18n->formatHours($entry->hours);
    }
}

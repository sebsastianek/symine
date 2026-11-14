<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Issue;
use App\Entity\IssueCategory;
use App\Entity\IssuePriority;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Security\CurrentUser;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Service\ProjectService;
use App\Service\Settings;
use App\Service\WatcherService;
use App\Twig\IssuesExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the variables of the issue form (issues/_form and issues/_attributes), shared by the new and the edit
 * pages: what the user may change and the choices offered for each field.
 */
final class IssueFormView
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUser $currentUser,
        private readonly IssueService $issues,
        private readonly ProjectService $projects,
        private readonly CustomFieldService $customFields,
        private readonly WatcherService $watchers,
        private readonly Settings $settings,
        private readonly IssuesExtension $helpers,
        private readonly IssueCopyOptions $copy,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Variables used by issues/_form and issues/_attributes
     *
     * @return array<string, mixed>
     */
    public function vars(Request $request, Issue $issue, ?Project $project): array
    {
        $user = $this->currentUser->get();
        $params = IssueParams::issue($request);
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
        } elseif (null !== $project && $issue->isNew() && null === $this->copy->source($request)) {
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
            $submitted = array_filter(array_map('intval', (array) ($params['watcher_user_ids'] ?? [])));
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
            if ($copy = $this->copy->source($request)) {
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
            'show_project_select' => (\in_array('project_id', $safe, true)) && (null === $project || \count($projects) > 1 || null !== $this->copy->source($request)),
            'trackers_for_select' => $trackers,
            'assignable_users' => $assignable,
            'me_assignable' => \in_array($user, $assignable, true),
            'assignable_versions' => $this->issues->assignableVersions($issue),
            'categories' => $this->categories($issue->project),
            'form_custom_fields' => $cfs,
            'parent_issue_id' => $request->isMethod('GET') && !\array_key_exists('parent_issue_id', $params) ? $issue->parent?->id : ($params['parent_issue_id'] ?? $issue->parent?->id),
            'watcher_candidates' => array_values($watcherCandidates),
            'watcher_checked' => $watcherChecked,
            'done_ratio_options' => range(0, 100, max(1, $this->settings->int('issue_done_ratio_interval') ?: 10)),
            'use_field_for_done_ratio' => !$this->issues->useStatusForDoneRatio(),
            'estimated_hours_input' => $this->issues->invalidEstimatedHours($issue),
            'is_default_status' => isset($issue->tracker->defaultStatus) && $issue->tracker->defaultStatus?->id === $issue->status->id,
            'back_url' => IssueParams::get($request, 'back_url'),
            'form_update_url' => $this->helpers->updateIssueFormPath($project, $issue),
            'preview_url' => $this->urls->generate('preview_issue', array_filter(['project_id' => isset($issue->project) ? $issue->project->identifier : null, 'issue_id' => $issue->id])),
            'parent_autocomplete_url' => $this->urls->generate('issues_auto_complete', array_filter(['project_id' => isset($issue->project) ? $issue->project->identifier : null, 'scope' => $this->settings->string('cross_project_subtasks'), 'status' => $issue->isClosed() ? 'c' : 'o', 'issue_id' => $issue->id])),
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

    /** @return list<IssueCategory> */
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
}

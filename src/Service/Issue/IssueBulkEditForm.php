<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\CustomField;
use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\Project;
use App\I18n\Localizer;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Service\AttachmentService;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Service\ProjectService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The bulk edit / bulk copy form of issues (issues#bulk_edit / #bulk_update): offers what can be changed on
 * all the selected issues at once and applies the changes to each of them.
 */
final class IssueBulkEditForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly AccessGuard $guard,
        private readonly IssueFinder $finder,
        private readonly IssueService $issues,
        private readonly ProjectService $projects,
        private readonly CustomFieldService $customFields,
        private readonly AttachmentService $attachments,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly IssueCopyOptions $copy,
    ) {
    }

    /**
     * Variables of issues/bulk_edit for the issues selected by the request, or for the issues a bulk update
     * could not save.
     *
     * @return array<string, mixed>
     */
    public function view(Request $request, ?IssueBulkUpdate $failed = null): array
    {
        if (null !== $failed) {
            // the form is shown again for the issues that could not be saved
            $request->query->set('ids', array_map(static fn ($i) => $i->id, $failed->unsaved));
            $request->request->remove('ids');
        }
        $issues = $this->finder->fromRequest($request);
        $copy = '' !== (string) IssueParams::get($request, 'copy', '');
        $projects = IssueFinder::projectsOf($issues);
        foreach ($projects as $p) {
            $this->authorizeBulk($p, $copy);
        }
        if ($copy) {
            foreach ($projects as $p) {
                if (!$this->auth->allowedTo('copy_issues', $p)) {
                    $this->guard->deny();
                }
            }
        } else {
            foreach ($issues as $i) {
                if (!$this->issues->isAttributesEditable($i)) {
                    $this->guard->deny();
                }
            }
        }
        $params = IssueParams::get($request, 'issue');
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
        $valuesByField = [];
        foreach ($customFields as $cf) {
            // issues for which the field is not available
            $missing = array_values(array_filter($issues, fn (Issue $i) => !\in_array($cf, $this->customFields->availableIssueFields($i), true)));
            if ([] !== $missing) {
                $valuesByField[$cf->id] = array_map(static fn ($i) => $i->id, $missing);
            }
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

        return [
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
            'notes' => IssueParams::get($request, 'notes'),
            'attachments_present' => $copy && $hasAttachments && 'ask' === $this->settings->string('copy_attachments_on_issue_copy'),
            'subtasks_present' => $copy && [] !== array_filter($issues, static fn (Issue $i) => !$i->isLeaf()),
            'watchers_present' => $watchersPresent,
            'unsaved_issues' => $failed?->unsaved ?? [],
            'saved_issues' => $failed?->saved ?? [],
            'bulk_error_messages' => $failed?->errorMessages ?? [],
            'bulk_total' => null !== $failed ? \count($failed->issues) : 0,
            'done_ratio_options' => range(0, 100, max(1, $this->settings->int('issue_done_ratio_interval') ?: 10)),
            'use_field_for_done_ratio' => !$this->issues->useStatusForDoneRatio(),
            'back_url' => IssueParams::get($request, 'back_url'),
            'main_menu_selected' => 'issues',
            'page_title' => $this->i18n->l($copy ? 'button_copy' : 'label_bulk_edit_selected_issues'),
            'body_css' => 'controller-issues action-bulk_edit',
        ];
    }

    /**
     * Updates or copies the selected issues with the submitted attributes (issues#bulk_update).
     *
     * @param \Closure(string, array<string, mixed>): mixed $hook call_hook of the calling controller
     */
    public function submit(Request $request, \Closure $hook): IssueBulkUpdate
    {
        $issues = $this->finder->fromRequest($request);
        $copy = '' !== (string) IssueParams::get($request, 'copy', '');
        $projects = IssueFinder::projectsOf($issues);
        $attributes = IssueParams::bulkAttributes($request);
        $copySubtasks = '1' === (string) $request->request->get('copy_subtasks');
        $copyWatchers = '1' === (string) $request->request->get('copy_watchers');
        if ($copy) {
            foreach ($projects as $p) {
                if (!$this->auth->allowedTo('copy_issues', $p)) {
                    $this->guard->deny();
                }
            }
            $targets = $projects;
            if (!empty($attributes['project_id'])) {
                $targets = array_filter([$this->em->find(Project::class, (int) $attributes['project_id'])]);
            }
            foreach ($targets as $p) {
                if (!$this->auth->allowedTo('add_issues', $p)) {
                    $this->guard->deny();
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
                    $this->guard->deny();
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
        /** @var array<string, list<string>> $errorMessages validation message => issue ids (bulk_edit_error_messages) */
        $errorMessages = [];
        foreach ($issues as $orig) {
            $this->em->refresh($orig);
            if ($copy) {
                $issue = $this->issues->copyFrom($orig, null, ['attachments' => $this->copy->attachments($request), 'subtasks' => $copySubtasks, 'watchers' => $copyWatchers, 'link' => $this->copy->link($request)]);
            } else {
                $issue = $orig;
            }
            // issue.init_journal(User.current, params[:notes]) then issue.safe_attributes = attributes:
            // "Private notes" comes with the attributes and must not be reset
            if ('' !== $notes) {
                $this->issues->setNotes($issue, $notes, false);
            }
            $this->issues->setSafeAttributes($issue, $attributes);
            $hook('controller_issues_bulk_edit_before_save', ['params' => $request->request->all(), 'issue' => $issue, 'project' => $issue->project]);
            $journalCount = $issue->journals->count();
            $issueErrors = $this->issues->save($issue);
            foreach ($issueErrors as $message) {
                $errorMessages[$message][] = '#'.$orig->id;
            }
            if ([] === $issueErrors) {
                if ($copy) {
                    $this->issues->afterCopy($issue);
                }
                $hook('controller_issues_bulk_edit_after_save', ['params' => $request->request->all(), 'issue' => $issue, 'journal' => $issue->journals->count() > $journalCount ? ($issue->journals->last() ?: null) : null, 'project' => $issue->project]);
                $saved[] = $issue;
            } else {
                $unsaved[] = $orig;
                if (!$copy) {
                    // a rejected issue must not be written by the save of the next one: Doctrine flushes every
                    // changed entity, where ActiveRecord only saves the record at hand
                    $this->em->refresh($orig);
                }
            }
        }
        $messages = [];
        foreach ($errorMessages as $message => $ids) {
            $messages[] = $message.': '.implode(', ', $ids);
        }

        return new IssueBulkUpdate($issues, $projects, $saved, $unsaved, $messages);
    }

    private function authorizeBulk(Project $p, bool $copy): void
    {
        if ($copy) {
            return;
        }
        if (!$this->auth->allowedTo('edit_issues', $p) && !$this->auth->allowedTo('edit_own_issues', $p)) {
            $this->guard->deny();
        }
    }
}

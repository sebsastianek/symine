<?php

declare(strict_types=1);

namespace App\Service\Tracking\ContextMenu;

use App\Entity\IssuePriority;
use App\Entity\IssueStatus;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Version;
use App\I18n\Localizer;
use App\Security\CurrentUser;
use App\Service\Settings;
use App\Service\WatcherService;
use App\Twig\AppExtension;
use App\Twig\IssuesExtension;
use Symfony\Component\HttpFoundation\Request;

/**
 * The menu shown by a right click on the issue list: context_menus/issues.html.erb, the items being the links
 * that edit, bulk edit, watch, copy or delete the selected issues.
 */
final class IssueContextMenu
{
    public function __construct(
        private readonly IssueBulkChoices $choices,
        private readonly ContextMenuLinks $links,
        private readonly ContextMenuBackUrl $backUrl,
        private readonly CurrentUser $currentUser,
        private readonly Localizer $i18n,
        private readonly Settings $settings,
        private readonly WatcherService $watchers,
        private readonly AppExtension $app,
        private readonly IssuesExtension $issueHelpers,
    ) {
    }

    /** @return array{items: list<array>, folders: list<array>, tail: list<array>, actions: list<string>, delete: ?string, angle: string} */
    public function view(IssueSelection $selection, Request $request): array
    {
        $params = array_replace($request->query->all(), $request->request->all());
        $issues = $selection->issues;
        $issue = $selection->issue;
        $project = $selection->project;
        $user = $this->currentUser->get();

        $allowedStatuses = $this->choices->statuses($selection);
        $can = $this->choices->can($selection);
        $assignables = $this->choices->assignables($selection);
        $trackers = $this->choices->trackers($selection);
        $versions = $this->choices->versions($selection);
        $priorities = $this->choices->priorities();

        $back = $this->backUrl->of($request);
        $includeDelete = $this->backUrl->isIssueList($back);
        $columns = $params['c'] ?? null;
        $safe = $this->choices->safeAttributes($selection);

        // ---- build the menu ------------------------------------------------------------------
        $edit = $can['edit'];
        $bulkLink = fn (string $text, array $issueAttrs, array $options): string => $this->bulkLink($selection, $back, $text, $issueAttrs, $options);
        $items = [];
        if (null !== $issue) {
            $items[] = ['link' => $this->links->link($this->links->icon('edit', $this->l('button_edit')), $this->links->route('issue_edit', ['id' => $issue->id]), ['class' => 'icon icon-edit', 'disabled' => !$edit])];
        } else {
            $items[] = ['link' => $this->links->link($this->links->icon('edit', $this->l('label_bulk_edit')), $this->links->route('issues_bulk_edit', [], ['ids' => $selection->ids]), ['class' => 'icon icon-edit', 'disabled' => !$edit])];
        }
        $folders = [];
        if ([] !== $allowedStatuses) {
            $folders[] = ['name' => $this->l('field_status'), 'css' => '', 'status' => true, 'links' => array_map(fn (IssueStatus $s) => $bulkLink($s->name, ['status_id' => $s->id], ['selected' => $issue?->status->id === $s->id, 'disabled' => !$edit]), $allowedStatuses)];
        }
        if ([] !== $trackers) {
            $folders[] = ['name' => $this->l('field_tracker'), 'links' => array_map(fn (Tracker $t) => $bulkLink($t->name, ['tracker_id' => $t->id], ['selected' => $issue?->tracker->id === $t->id, 'disabled' => !$edit]), $trackers)];
        }
        if (\in_array('priority_id', $safe, true) && [] !== $priorities) {
            $derived = $this->choices->isPriorityDerived($selection);
            $folders[] = ['name' => $this->l('field_priority'), 'links' => array_map(fn (IssuePriority $p) => $bulkLink($p->name, ['priority_id' => $p->id], ['selected' => $issue?->priority->id === $p->id, 'disabled' => !$edit || $derived]), $priorities)];
        }
        if (\in_array('fixed_version_id', $safe, true) && [] !== $versions) {
            $links = array_map(fn (Version $v) => $bulkLink($v->project === $project ? (string) $v->name : $v->project->name.' - '.$v->name, ['fixed_version_id' => $v->id], ['selected' => $issue?->fixedVersion?->id === $v->id && null !== $v->id, 'disabled' => !$edit]), $versions);
            $links[] = $bulkLink($this->l('label_none'), ['fixed_version_id' => 'none'], ['selected' => null !== $issue && null === $issue->fixedVersion, 'disabled' => !$edit]);
            $folders[] = ['name' => $this->l('field_fixed_version'), 'links' => $links];
        }
        if (\in_array('assigned_to_id', $safe, true) && [] !== $assignables) {
            $links = [];
            foreach ($assignables as $u) {
                if ($u->id === $user->id) {
                    $links[] = $bulkLink('<< '.$this->l('label_me').' >>', ['assigned_to_id' => $user->id], ['disabled' => !$edit]);
                    break;
                }
            }
            foreach ($assignables as $u) {
                $links[] = $bulkLink($u->getName(), ['assigned_to_id' => $u->id], ['selected' => null !== $issue && $issue->assignedTo?->id === $u->id, 'disabled' => !$edit]);
            }
            $links[] = $bulkLink($this->l('label_nobody'), ['assigned_to_id' => 'none'], ['selected' => null !== $issue && null === $issue->assignedTo, 'disabled' => !$edit]);
            $folders[] = ['name' => $this->l('field_assigned_to'), 'links' => $links];
        }
        if (\in_array('category_id', $safe, true) && null !== $project && $project->issueCategories->count() > 0) {
            $links = [];
            foreach ($project->issueCategories as $c) {
                $links[] = $bulkLink($c->name, ['category_id' => $c->id], ['selected' => null !== $issue && $issue->category?->id === $c->id, 'disabled' => !$edit]);
            }
            $links[] = $bulkLink($this->l('label_none'), ['category_id' => 'none'], ['selected' => null !== $issue && null === $issue->category, 'disabled' => !$edit]);
            $folders[] = ['name' => $this->l('field_category'), 'links' => $links];
        }
        if (\in_array('done_ratio', $safe, true) && 'issue_field' === ($this->settings->string('issue_done_ratio') ?: 'issue_field')) {
            $derived = $this->choices->isDoneRatioDerived($selection);
            $step = max(1, $this->settings->int('issue_done_ratio_interval') ?: 10);
            $links = [];
            for ($p = 0; $p <= 100; $p += $step) {
                $links[] = $bulkLink($p.'%', ['done_ratio' => $p], ['selected' => null !== $issue && $issue->doneRatio === $p, 'disabled' => !$edit || $derived]);
            }
            $folders[] = ['name' => $this->l('field_done_ratio'), 'links' => $links];
        }
        if ($edit) {
            foreach ($this->choices->customFieldOptions($selection) as [$field, $options]) {
                $links = [];
                $current = null !== $issue ? $this->choices->customFieldValue($issue, $field) : null;
                foreach ($options as [$value, $text]) {
                    $links[] = $bulkLink($text, ['custom_field_values' => [$field->id => $value]], ['selected' => null !== $issue && (string) $current === (string) $value && null !== $current]);
                }
                if (!$field->isRequired) {
                    $links[] = $bulkLink($this->l('label_none'), ['custom_field_values' => [$field->id => '__none__']], ['selected' => false]);
                }
                $folders[] = ['name' => $field->name, 'css' => ' '.$field->fieldFormat.'_cf cf_'.$field->id, 'cf' => true, 'links' => $links];
            }
        }

        $tail = [];
        if ($can['add_watchers'] && $this->links->hasRoute('watchers_new')) {
            $tail[] = ['folder' => ['name' => $this->l('label_issue_watchers'), 'links' => [
                $this->links->link($this->links->icon('add', $this->l('button_add')), $this->links->route('watchers_new', [], ['object_id' => $selection->ids, 'object_type' => 'issue']), ['remote' => true, 'class' => 'icon icon-add']),
            ]]];
        }
        if ($user->isLogged() && $this->links->hasRoute('watch')) {
            $tail[] = ['link' => $this->watcherLink($selection, $user)];
        }
        $listRoute = null !== $project ? ['project_issues', ['project_id' => $project->identifier]] : ['issues_index', []];
        $filterParams = ['issue_id' => implode(',', $selection->ids), 'set_filter' => 1, 'status_id' => '*'];
        if (null === $issue) {
            $tail[] = ['link' => $this->links->link($this->links->icon('list', $this->l('button_filter')), $this->links->route($listRoute[0], $listRoute[1], $filterParams + (null !== $columns ? ['c' => array_values((array) $columns)] : [])), ['class' => 'icon icon-list'])];
        }
        $actions = [];
        if (null !== $issue) {
            if ($can['log_time'] && $this->links->hasRoute('issue_time_entry_new')) {
                $actions[] = $this->links->link($this->links->icon('time-add', $this->l('button_log_time')), $this->links->route('issue_time_entry_new', ['issue_id' => $issue->id]), ['class' => 'icon icon-time-add']);
            }
            if ($can['add_subtask'] && $this->links->hasRoute('issue_new')) {
                $actions[] = $this->links->link($this->links->icon('add', $this->l('button_add_subtask')), $this->issueHelpers->newSubtaskUrl($issue, false), ['class' => 'icon icon-add']);
            }
            $actions[] = $this->links->copyLink($request->getSchemeAndHttpHost().$this->links->route('issue_show', ['id' => $issue->id]));
            $actions[] = $this->links->link($this->links->icon('copy', $this->l('button_copy')), $this->links->route('issue_copy', ['project_id' => $issue->project->identifier, 'copy_from' => $issue->id]), ['class' => 'icon icon-copy', 'disabled' => !$can['copy']]);
        } else {
            $actions[] = $this->links->copyLink($request->getSchemeAndHttpHost().$this->links->route($listRoute[0], $listRoute[1], $filterParams));
            $actions[] = $this->links->link($this->links->icon('copy', $this->l('button_copy')), $this->links->route('issues_bulk_edit', [], ['copy' => '1', 'ids' => $selection->ids]), ['class' => 'icon icon-copy', 'disabled' => !$can['copy']]);
        }
        $delete = null;
        if ($includeDelete) {
            $label = mb_strtoupper(mb_substr($s = $this->l('button_delete_object', ['object_name' => $this->l(\count($selection->ids) > 1 ? 'label_issue_plural' : 'label_issue')]), 0, 1)).mb_strtolower(mb_substr($s, 1));
            $delete = $this->links->link($this->links->icon('del', $label), $this->links->route('issues_destroy', [], ['back_url' => $back, 'ids' => $selection->ids]),
                ['method' => 'delete', 'confirm' => $this->issueHelpers->destroyConfirmationMessage($issues), 'class' => 'icon icon-del', 'disabled' => !$can['delete']]);
        }

        return [
            'items' => $items,
            'folders' => $folders,
            'tail' => $tail,
            'actions' => $actions,
            'delete' => $delete,
            'angle' => $this->app->spriteIcon('angle-right', null, 18, false, 'icon-rtl'),
        ];
    }

    /** _bulk_update_issues_path(@issue, ids:, issue:, back_url:) link with method patch */
    private function bulkLink(IssueSelection $selection, ?string $back, string $text, array $issueAttrs, array $options): string
    {
        $query = ['back_url' => $back, 'ids' => $selection->ids, 'issue' => $issueAttrs];
        $url = null !== $selection->issue ? $this->links->route('issue_update', ['id' => $selection->issue->id], $query) : $this->links->route('issues_bulk_update', [], $query);

        return $this->links->link(htmlspecialchars($text), $url, $options + ['method' => 'patch']);
    }

    /** WatchersHelper#watcher_link */
    private function watcherLink(IssueSelection $selection, User $user): string
    {
        $watched = false;
        foreach ($selection->issues as $i) {
            if ($this->watchers->isWatching('Issue', (int) $i->id, $user)) {
                $watched = true;
                break;
            }
        }
        $css = 'issue-'.(1 === \count($selection->ids) ? $selection->ids[0] : 'bulk').'-watcher '.($watched ? 'icon icon-fav' : 'icon icon-fav-off');
        $url = $this->links->route('watch', [], ['object_id' => 1 === \count($selection->ids) ? $selection->ids[0] : $selection->ids, 'object_type' => 'issue']);

        return $this->links->link($this->links->icon($watched ? 'unwatch' : 'watch', $this->l($watched ? 'button_unwatch' : 'button_watch')), $url, ['class' => $css, 'remote' => true, 'method' => $watched ? 'delete' : 'post']);
    }

    /** @param array<string, mixed> $params */
    private function l(string $key, array $params = []): string
    {
        return $this->i18n->l($key, $params);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CustomField;
use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\IssueStatus;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Version;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Twig\AppExtension;
use App\Twig\IssuesExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Port of ContextMenus::IssuesController + context_menus/issues.html.erb + ContextMenusHelper.
 * Returns the HTML fragment displayed by context_menu.js on the issue list.
 */
final class ContextMenusController extends AbstractController
{
    private Request $request;
    private ?Issue $issue = null;
    /** @var list<int> */
    private array $issueIds = [];
    private ?string $back = null;

    public function __construct(
        private readonly IssueService $issueService,
        private readonly CustomFieldService $customFields,
        private readonly AppExtension $app,
        private readonly IssuesExtension $issueHelpers,
        private readonly RouterInterface $router,
        private readonly \App\Service\WatcherService $watchers,
    ) {
    }

    #[Route('/issues/context_menu', name: 'issues_context_menu', methods: ['GET', 'POST'], priority: 10)]
    public function issues(Request $request): Response
    {
        $this->request = $request;
        $params = array_replace($request->query->all(), $request->request->all());
        $ids = $params['ids'] ?? $params['id'] ?? [];
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        $issues = [] === $ids ? [] : $this->em->getRepository(Issue::class)->findBy(['id' => $ids]);
        if ([] === $issues) {
            throw $this->createNotFoundException();
        }
        foreach ($issues as $i) {
            if (!$this->issueService->isVisible($i)) {
                $this->deny();
            }
        }
        $projects = [];
        foreach ($issues as $i) {
            $projects[$i->project->id] = $i->project;
        }
        $projects = array_values($projects);
        $project = 1 === \count($projects) ? $projects[0] : null;
        $this->issue = 1 === \count($issues) ? $issues[0] : null;
        $this->issueIds = array_map(static fn (Issue $i) => (int) $i->id, $issues);
        sort($this->issueIds);
        $user = $this->user();

        $allowedStatuses = $this->intersect(array_map(fn (Issue $i) => $this->issueService->newStatusesAllowedTo($i), $issues));
        $allProjects = fn (string $perm) => array_reduce($projects, fn (bool $c, Project $p) => $c && $this->auth->allowedTo($perm, $p), true);
        $can = [
            'edit' => array_reduce($issues, fn (bool $c, Issue $i) => $c && $this->issueService->isAttributesEditable($i), true),
            'log_time' => null !== $this->issue && $this->issueService->isTimeLoggable($this->issue),
            'copy' => $allProjects('copy_issues') && [] !== $this->projects->allowedTargetProjects(),
            'add_watchers' => $allProjects('add_issue_watchers'),
            'delete' => array_reduce($issues, fn (bool $c, Issue $i) => $c && $this->issueService->isDeletable($i), true),
            'add_subtask' => null !== $this->issue && !$this->issue->isClosed() && $this->auth->allowedTo('manage_subtasks', $project),
        ];
        $assignables = $this->intersect(array_map(fn (Issue $i) => $this->issueService->assignableUsers($i), $issues));
        $trackers = $this->intersect(array_map(fn (Project $p) => $this->projects->allowedTargetTrackers($p), $projects));
        $versions = $this->intersect(array_map(fn (Project $p) => array_values(array_filter($this->projects->sharedVersions($p), static fn (Version $v) => $v->isOpen())), $projects));
        $versions = $this->projects->sortVersions($versions);
        $priorities = $this->em->getRepository(IssuePriority::class)->findBy(['active' => true], ['position' => 'DESC']);

        $this->back = $this->backUrl($params);
        $includeDelete = $this->includeDelete($this->back);
        $columns = $params['c'] ?? null;

        $safe = null;
        foreach ($issues as $i) {
            $names = $this->issueService->safeAttributeNames($i);
            $safe = null === $safe ? $names : array_values(array_intersect($safe, $names));
        }
        $safe ??= [];

        // ---- build the menu ------------------------------------------------------------------
        $edit = $can['edit'];
        $items = [];
        if (null !== $this->issue) {
            $items[] = ['link' => $this->link($this->icon('edit', $this->l('button_edit')), $this->route('issue_edit', ['id' => $this->issue->id]), ['class' => 'icon icon-edit', 'disabled' => !$edit])];
        } else {
            $items[] = ['link' => $this->link($this->icon('edit', $this->l('label_bulk_edit')), $this->route('issues_bulk_edit', [], ['ids' => $this->issueIds]), ['class' => 'icon icon-edit', 'disabled' => !$edit])];
        }
        $folders = [];
        if ([] !== $allowedStatuses) {
            $folders[] = ['name' => $this->l('field_status'), 'css' => '', 'status' => true, 'links' => array_map(fn (IssueStatus $s) => $this->bulkLink($s->name, ['status_id' => $s->id], ['selected' => $this->issue?->status->id === $s->id, 'disabled' => !$edit]), $allowedStatuses)];
        }
        if ([] !== $trackers) {
            $folders[] = ['name' => $this->l('field_tracker'), 'links' => array_map(fn (Tracker $t) => $this->bulkLink($t->name, ['tracker_id' => $t->id], ['selected' => $this->issue?->tracker->id === $t->id, 'disabled' => !$edit]), $trackers)];
        }
        if (\in_array('priority_id', $safe, true) && [] !== $priorities) {
            $derived = array_reduce($issues, fn (bool $c, Issue $i) => $c || $this->issueService->isPriorityDerived($i), false);
            $folders[] = ['name' => $this->l('field_priority'), 'links' => array_map(fn (IssuePriority $p) => $this->bulkLink($p->name, ['priority_id' => $p->id], ['selected' => $this->issue?->priority->id === $p->id, 'disabled' => !$edit || $derived]), $priorities)];
        }
        if (\in_array('fixed_version_id', $safe, true) && [] !== $versions) {
            $links = array_map(fn (Version $v) => $this->bulkLink($v->project === $project ? (string) $v->name : $v->project->name.' - '.$v->name, ['fixed_version_id' => $v->id], ['selected' => $this->issue?->fixedVersion?->id === $v->id && null !== $v->id, 'disabled' => !$edit]), $versions);
            $links[] = $this->bulkLink($this->l('label_none'), ['fixed_version_id' => 'none'], ['selected' => null !== $this->issue && null === $this->issue->fixedVersion, 'disabled' => !$edit]);
            $folders[] = ['name' => $this->l('field_fixed_version'), 'links' => $links];
        }
        if (\in_array('assigned_to_id', $safe, true) && [] !== $assignables) {
            $links = [];
            foreach ($assignables as $u) {
                if ($u->id === $user->id) {
                    $links[] = $this->bulkLink('<< '.$this->l('label_me').' >>', ['assigned_to_id' => $user->id], ['disabled' => !$edit]);
                    break;
                }
            }
            foreach ($assignables as $u) {
                $links[] = $this->bulkLink($u->getName(), ['assigned_to_id' => $u->id], ['selected' => null !== $this->issue && $this->issue->assignedTo?->id === $u->id, 'disabled' => !$edit]);
            }
            $links[] = $this->bulkLink($this->l('label_nobody'), ['assigned_to_id' => 'none'], ['selected' => null !== $this->issue && null === $this->issue->assignedTo, 'disabled' => !$edit]);
            $folders[] = ['name' => $this->l('field_assigned_to'), 'links' => $links];
        }
        if (\in_array('category_id', $safe, true) && null !== $project && $project->issueCategories->count() > 0) {
            $links = [];
            foreach ($project->issueCategories as $c) {
                $links[] = $this->bulkLink($c->name, ['category_id' => $c->id], ['selected' => null !== $this->issue && $this->issue->category?->id === $c->id, 'disabled' => !$edit]);
            }
            $links[] = $this->bulkLink($this->l('label_none'), ['category_id' => 'none'], ['selected' => null !== $this->issue && null === $this->issue->category, 'disabled' => !$edit]);
            $folders[] = ['name' => $this->l('field_category'), 'links' => $links];
        }
        if (\in_array('done_ratio', $safe, true) && 'issue_field' === ($this->settings->string('issue_done_ratio') ?: 'issue_field')) {
            $derived = array_reduce($issues, fn (bool $c, Issue $i) => $c || $this->issueService->isDoneRatioDerived($i), false);
            $step = max(1, $this->settings->int('issue_done_ratio_interval') ?: 10);
            $links = [];
            for ($p = 0; $p <= 100; $p += $step) {
                $links[] = $this->bulkLink($p.'%', ['done_ratio' => $p], ['selected' => null !== $this->issue && $this->issue->doneRatio === $p, 'disabled' => !$edit || $derived]);
            }
            $folders[] = ['name' => $this->l('field_done_ratio'), 'links' => $links];
        }
        if ($edit) {
            foreach ($this->customFieldOptions($issues, $projects) as [$field, $options]) {
                $links = [];
                $current = null !== $this->issue ? $this->customFields->values('Issue', (int) $this->issue->id)[$field->id] ?? null : null;
                foreach ($options as [$value, $text]) {
                    $links[] = $this->bulkLink($text, ['custom_field_values' => [$field->id => $value]], ['selected' => null !== $this->issue && (string) $current === (string) $value && null !== $current]);
                }
                if (!$field->isRequired) {
                    $links[] = $this->bulkLink($this->l('label_none'), ['custom_field_values' => [$field->id => '__none__']], ['selected' => false]);
                }
                $folders[] = ['name' => $field->name, 'css' => ' '.$field->fieldFormat.'_cf cf_'.$field->id, 'cf' => true, 'links' => $links];
            }
        }

        $tail = [];
        if ($can['add_watchers'] && $this->router->getRouteCollection()->get('watchers_new')) {
            $tail[] = ['folder' => ['name' => $this->l('label_issue_watchers'), 'links' => [
                $this->link($this->icon('add', $this->l('button_add')), $this->route('watchers_new', [], ['object_id' => $this->issueIds, 'object_type' => 'issue']), ['remote' => true, 'class' => 'icon icon-add']),
            ]]];
        }
        if ($user->isLogged() && $this->router->getRouteCollection()->get('watch')) {
            $tail[] = ['link' => $this->watcherLink($issues, $user)];
        }
        $listRoute = null !== $project ? ['project_issues', ['project_id' => $project->identifier]] : ['issues_index', []];
        $filterParams = ['issue_id' => implode(',', $this->issueIds), 'set_filter' => 1, 'status_id' => '*'];
        if (null === $this->issue) {
            $tail[] = ['link' => $this->link($this->icon('list', $this->l('button_filter')), $this->route($listRoute[0], $listRoute[1], $filterParams + (null !== $columns ? ['c' => array_values((array) $columns)] : [])), ['class' => 'icon icon-list'])];
        }
        $actions = [];
        if (null !== $this->issue) {
            if ($can['log_time'] && $this->router->getRouteCollection()->get('issue_time_entry_new')) {
                $actions[] = $this->link($this->icon('time-add', $this->l('button_log_time')), $this->route('issue_time_entry_new', ['issue_id' => $this->issue->id]), ['class' => 'icon icon-time-add']);
            }
            if ($can['add_subtask'] && $this->router->getRouteCollection()->get('issue_new')) {
                $actions[] = $this->link($this->icon('add', $this->l('button_add_subtask')), $this->issueHelpers->newSubtaskUrl($this->issue, false), ['class' => 'icon icon-add']);
            }
            $actions[] = $this->copyLink($request->getSchemeAndHttpHost().$this->route('issue_show', ['id' => $this->issue->id]));
            $actions[] = $this->link($this->icon('copy', $this->l('button_copy')), $this->route('issue_copy', ['project_id' => $this->issue->project->identifier, 'copy_from' => $this->issue->id]), ['class' => 'icon icon-copy', 'disabled' => !$can['copy']]);
        } else {
            $actions[] = $this->copyLink($request->getSchemeAndHttpHost().$this->route($listRoute[0], $listRoute[1], $filterParams));
            $actions[] = $this->link($this->icon('copy', $this->l('button_copy')), $this->route('issues_bulk_edit', [], ['copy' => '1', 'ids' => $this->issueIds]), ['class' => 'icon icon-copy', 'disabled' => !$can['copy']]);
        }
        $delete = null;
        if ($includeDelete) {
            $label = mb_strtoupper(mb_substr($s = $this->l('button_delete_object', ['object_name' => $this->l(\count($this->issueIds) > 1 ? 'label_issue_plural' : 'label_issue')]), 0, 1)).mb_strtolower(mb_substr($s, 1));
            $delete = $this->link($this->icon('del', $label), $this->route('issues_destroy', [], ['back_url' => $this->back, 'ids' => $this->issueIds]),
                ['method' => 'delete', 'confirm' => $this->issueHelpers->destroyConfirmationMessage($issues), 'class' => 'icon icon-del', 'disabled' => !$can['delete']]);
        }

        return $this->render('context_menus/issues.html.twig', [
            'items' => $items,
            'folders' => $folders,
            'tail' => $tail,
            'actions' => $actions,
            'delete' => $delete,
            'angle' => $this->app->spriteIcon('angle-right', null, 18, false, 'icon-rtl'),
        ]);
    }

    // ------------------------------------------------------------------------------------------

    /**
     * Intersection of lists of entities (by id), keeping the order of the first list.
     *
     * @param list<list<object>> $lists
     */
    private function intersect(array $lists): array
    {
        if ([] === $lists) {
            return [];
        }
        $result = array_shift($lists);
        foreach ($lists as $list) {
            $ids = array_map(static fn ($o) => $o->id, $list);
            $result = array_values(array_filter($result, static fn ($o) => \in_array($o->id, $ids, true)));
        }

        return $result;
    }

    /**
     * Custom fields editable on all the issues, with their possible values ([value, text]).
     *
     * @return list<array{0: CustomField, 1: list<array{0: string, 1: string}>}>
     */
    private function customFieldOptions(array $issues, array $projects): array
    {
        $fields = $this->intersect(array_map(fn (Issue $i) => $this->issueService->editableCustomFields($i), $issues));
        $out = [];
        foreach ($fields as $field) {
            if ($field->isMultiple() || !\in_array($field->fieldFormat, ['list', 'bool', 'enumeration', 'user', 'version'], true)) {
                continue;
            }
            $values = null;
            foreach ($projects as $p) {
                $opts = $this->customFields->possibleValuesOptions($field, $p);
                if (null === $values) {
                    $values = $opts;
                } else {
                    $keys = array_map(static fn ($o) => (string) $o[0], $opts);
                    $values = array_values(array_filter($values, static fn ($o) => \in_array((string) $o[0], $keys, true)));
                }
            }
            if ([] !== ($values ?? [])) {
                $out[] = [$field, array_map(static fn ($o) => [(string) $o[0], (string) $o[1]], $values)];
            }
        }

        return $out;
    }

    private function backUrl(array $params): ?string
    {
        $url = isset($params['back_url']) && \is_string($params['back_url']) ? $params['back_url'] : null;
        if (null === $url && ($ref = $this->request->headers->get('referer'))) {
            $url = rawurldecode($ref);
        }
        if (null === $url || '' === $url) {
            return null;
        }
        // keep local urls only (Redmine#validate_back_url)
        $parts = parse_url($url);
        if (isset($parts['host']) && $parts['host'] !== $this->request->getHost()) {
            return null;
        }
        if (isset($parts['host'])) {
            $url = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }

        return str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : null;
    }

    private function includeDelete(?string $back): bool
    {
        if (null === $back) {
            return false;
        }
        $path = (string) parse_url($back, \PHP_URL_PATH);
        try {
            $context = $this->router->getContext();
            $method = $context->getMethod();
            $context->setMethod('GET');
            try {
                $match = $this->router->match($path);
            } finally {
                $context->setMethod($method);
            }
        } catch (RoutingException) {
            return false;
        }

        return \in_array($match['_route'] ?? null, ['issues_index', 'project_issues', 'gantt', 'project_gantt', 'calendar', 'project_calendar'], true);
    }

    private function icon(string $name, string $label): string
    {
        return $this->app->spriteIcon($name, $label);
    }

    /** URL with Rails-style (sorted, "a[]=") query string; '#' when the route does not exist */
    private function route(string $name, array $params = [], array $query = []): string
    {
        try {
            $url = $this->router->generate($name, $params);
        } catch (RoutingException) {
            return '#';
        }
        $qs = self::toQuery($query);

        return $url.('' !== $qs ? (str_contains($url, '?') ? '&' : '?').$qs : '');
    }

    /** Hash#to_query */
    public static function toQuery(array $params, ?string $prefix = null): string
    {
        $parts = [];
        if (!array_is_list($params) || null === $prefix) {
            ksort($params, \SORT_STRING);
        }
        foreach ($params as $k => $v) {
            if (null === $v) {
                continue;
            }
            $key = null === $prefix ? (string) $k : (array_is_list($params) ? $prefix.'[]' : $prefix.'['.$k.']');
            if (\is_array($v)) {
                $sub = self::toQuery($v, $key);
                if ('' !== $sub) {
                    $parts[] = $sub;
                }
            } else {
                $parts[] = rawurlencode($key).'='.rawurlencode(\is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
            }
        }

        return implode('&', $parts);
    }

    /** _bulk_update_issues_path(@issue, ids:, issue:, back_url:) link with method patch */
    private function bulkLink(string $text, array $issueAttrs, array $options): string
    {
        $query = ['back_url' => $this->back, 'ids' => $this->issueIds, 'issue' => $issueAttrs];
        $url = null !== $this->issue ? $this->route('issue_update', ['id' => $this->issue->id], $query) : $this->route('issues_bulk_update', [], $query);

        return $this->link(htmlspecialchars($text), $url, $options + ['method' => 'patch']);
    }

    /** WatchersHelper#watcher_link */
    private function watcherLink(array $issues, User $user): string
    {
        $watched = false;
        foreach ($issues as $i) {
            if ($this->watchers->isWatching('Issue', (int) $i->id, $user)) {
                $watched = true;
                break;
            }
        }
        $css = 'issue-'.(1 === \count($this->issueIds) ? $this->issueIds[0] : 'bulk').'-watcher '.($watched ? 'icon icon-fav' : 'icon icon-fav-off');
        $url = $this->route('watch', [], ['object_id' => 1 === \count($this->issueIds) ? $this->issueIds[0] : $this->issueIds, 'object_type' => 'issue']);

        return $this->link($this->icon($watched ? 'unwatch' : 'watch', $this->l($watched ? 'button_unwatch' : 'button_watch')), $url, ['class' => $css, 'remote' => true, 'method' => $watched ? 'delete' : 'post']);
    }

    private function copyLink(string $url): string
    {
        return '<a class="icon icon-copy-link" data-clipboard-text="'.htmlspecialchars($url).'" data-controller="clipboard" data-action="clipboard#copyText" href="#">'.$this->icon('copy-link', $this->l('button_copy_link')).'</a>';
    }

    /**
     * ContextMenusHelper#context_menu_link. $label is HTML.
     *
     * @param array{class?: string, selected?: bool, disabled?: bool, method?: string, confirm?: string, remote?: bool} $options
     */
    private function link(string $label, string $url, array $options = []): string
    {
        $classes = [$options['class'] ?? ''];
        $disabled = (bool) ($options['disabled'] ?? false);
        if ($options['selected'] ?? false) {
            $classes[] = 'icon disabled';
            $disabled = true;
            $label = $this->app->spriteIcon('checked', html_entity_decode($label, \ENT_QUOTES));
        }
        $method = $options['method'] ?? null;
        $confirm = $options['confirm'] ?? null;
        $onclick = null;
        if ($disabled || '#' === $url) {
            $method = null;
            $confirm = null;
            $onclick = 'return false;';
            $classes[] = 'disabled';
            $url = '#';
        }
        $tokens = [];
        foreach ($classes as $c) {
            foreach (preg_split('/\s+/', trim($c)) ?: [] as $t) {
                if ('' !== $t && !\in_array($t, $tokens, true)) {
                    $tokens[] = $t;
                }
            }
        }
        $a = '<a';
        if (null !== $confirm) {
            $a .= ' data-confirm="'.htmlspecialchars($confirm).'"';
        }
        // Rails keeps the hash insertion order: a given :class comes before the added :onclick
        $classFirst = isset($options['class']) && '' !== $options['class'];
        if ($classFirst) {
            $a .= ' class="'.implode(' ', $tokens).'"';
        }
        if (null !== $onclick) {
            $a .= ' onclick="'.$onclick.'"';
        }
        if (!$classFirst) {
            $a .= ' class="'.implode(' ', $tokens).'"';
        }
        if ($options['remote'] ?? false) {
            $a .= ' data-remote="true"';
        }
        if (null !== $method) {
            $a .= ' rel="nofollow" data-method="'.$method.'"';
        }

        return $a.' href="'.htmlspecialchars($url).'">'.$label.'</a>';
    }
}

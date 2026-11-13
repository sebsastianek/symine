<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\TimeEntry;
use App\EventSubscriber\TimeEntryQueryRegistrationSubscriber;
use App\Query\QueryColumn;
use App\Query\QueryContext;
use App\Query\QueryFactory;
use App\Query\StatementInvalid;
use App\Query\TimeEntry\TimeEntryQuery;
use App\Query\TimeEntry\TimeReport;
use App\Service\AccountService;
use App\Service\AtomFeed;
use App\Service\CustomFieldService;
use App\Service\TimeEntryService;
use App\Twig\TimelogExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's TimelogController and ContextMenus::TimeEntriesController. */
final class TimelogController extends AbstractController
{
    public function __construct(
        private readonly TimeEntryService $timeEntries,
        private readonly QueryFactory $queries,
        private readonly QueryContext $queryContext,
        private readonly ApiRenderer $api,
        private readonly CustomFieldService $customFields,
        private readonly AccountService $accounts,
        private readonly TimelogExtension $timelogHelper,
        private readonly AtomFeed $atom,
        private readonly \App\Service\IssueService $issues,
    ) {
        TimeEntryQueryRegistrationSubscriber::register($queries);
    }

    // --- List ---------------------------------------------------------------------

    #[Route('/time_entries', name: 'time_entries_index', methods: ['GET'])]
    #[Route('/time_entries.{_format}', name: 'time_entries_index_format', requirements: ['_format' => 'json|xml|csv|atom'], methods: ['GET'])]
    #[Route('/projects/{project_id}/time_entries', name: 'project_time_entries', requirements: ['project_id' => '[^/.]+'], methods: ['GET'])]
    #[Route('/projects/{project_id}/time_entries.{_format}', name: 'project_time_entries_format', requirements: ['project_id' => '[^/.]+', '_format' => 'json|xml|csv|atom'], methods: ['GET'])]
    public function index(Request $request, ?string $project_id = null, string $_format = 'html'): Response
    {
        $project = $this->findOptionalProject($project_id, 'view_time_entries');
        $query = $this->retrieveQuery($request, $project);
        if ('csv' === $_format && !$this->exportAllowed($query, $project)) {
            $this->deny();
        }
        try {
            $count = $query->isValid() ? $query->entryCount() : 0;
        } catch (StatementInvalid) {
            return $this->queryError($request);
        }

        if (\in_array($_format, ['json', 'xml'], true)) {
            $limit = (int) $request->query->get('limit', 0);
            $limit = $limit < 1 ? 25 : min(100, $limit);
            $offset = $request->query->has('offset') ? max(0, (int) $request->query->get('offset')) : null;
            if (null === $offset && $request->query->has('page')) {
                $offset = max(0, ((int) $request->query->get('page') - 1) * $limit);
            }
            $offset ??= 0;
            $entries = $query->isValid() ? $query->entries($offset, $limit) : [];

            return $this->api->render($request, [
                'time_entries' => array_map(fn (TimeEntry $e) => $this->timeEntries->toApi($e), $entries),
                'total_count' => $count,
                'offset' => $offset,
                'limit' => $limit,
            ]);
        }
        if ('atom' === $_format) {
            $entries = $query->entries(0, $this->settings->int('feeds_limit') ?: 15, ['time_entries.created_on DESC', 'time_entries.id DESC']);
            $items = array_map(function (TimeEntry $e) {
                $related = null !== $e->issue && $this->isIssueVisible($e->issue)
                    ? $e->issue->tracker->name.' #'.$e->issue->id.' ('.$e->issue->status->name.'): '.$e->issue->subject
                    : $e->project->name;
                $params = ['project_id' => $e->project->identifier] + (null !== $e->issue ? ['issue_id' => $e->issue->id] : []);

                return [
                    'title' => $this->l($e->hours < 2.0 ? 'label_f_hour' : 'label_f_hour_plural', ['value' => $this->i18n->formatHours($e->hours)]).' ('.$related.')',
                    'url' => $this->generateUrl('project_time_entries', $params),
                    'updated' => $e->createdOn,
                    'author' => $e->user,
                    'content' => (string) $e->comments,
                    'project' => $e->project,
                ];
            }, $entries);

            return $this->atom->render($request, $this->l('label_spent_time'), $items, $project);
        }
        if ('csv' === $_format) {
            $entries = $query->isValid() ? $query->entries() : [];

            return $this->csvResponse($request, $this->queryToCsv($request, $query, $entries), $this->exportFilename($request, $query, 'timelog'));
        }

        $pager = $this->paginate($request, $count);
        $entries = $query->isValid() ? $query->entries($pager['offset'], $pager['limit']) : [];
        $groups = $this->groupRows($query, $entries);

        return $this->render($request->isXmlHttpRequest() ? 'timelog/_list_xhr.html.twig' : 'timelog/index.html.twig', [
            'project' => $project,
            'query' => $query,
            'entries' => $entries,
            'rows' => $groups,
            'pager' => $pager,
            'totals' => $query->isValid() ? $query->totals() : [],
            'export_allowed' => $this->exportAllowed($query, $project),
            'atom_key' => $this->user()->isLogged() ? $this->accounts->atomKey($this->user()) : null,
            'main_menu_selected' => 'time_entries',
            'body_css' => 'controller-timelog action-index',
            'page_title' => $query->isNew() ? $this->l('label_spent_time') : $query->name(),
            'list_route' => $project ? 'project_time_entries' : 'time_entries_index',
            'route_params' => $project ? ['project_id' => $project->identifier] : [],
        ] + $this->queryFormVars($request, $query, $project));
    }

    /** @return list<array{entry: TimeEntry, group_name: ?string, group_count: ?int, group_totals: ?string}> */
    private function groupRows(TimeEntryQuery $query, array $entries): array
    {
        $rows = [];
        $counts = $query->resultCountByGroup();
        $totalsByGroup = [];
        if ($query->isGrouped()) {
            foreach ($query->totalableColumns() as $c) {
                $totalsByGroup[] = [$c, $query->totalByGroupFor($c)];
            }
        }
        $previous = null;
        foreach ($entries as $e) {
            $row = ['entry' => $e, 'group_name' => null, 'group_count' => null, 'group_totals' => null];
            if (null !== $counts) {
                $key = $query->groupKey($e);
                if ($key !== $previous) {
                    $row['group_name'] = $this->timelogHelper->groupLabel($query, $e);
                    $row['group_count'] = $counts[$key] ?? null;
                    $totals = [];
                    foreach ($totalsByGroup as [$c, $byGroup]) {
                        $totals[] = $this->totalTag($c, $byGroup[$key] ?? 0);
                    }
                    $row['group_totals'] = implode(' ', $totals);
                    $previous = $key;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function totalTag(QueryColumn $c, float|int $value): string
    {
        $v = 'hours' === $c->name ? $this->i18n->formatHours((float) $value) : (\is_int($value) ? (string) $value : number_format($value, 2, '.', ''));

        return '<span class="total-for-'.str_replace('_', '-', $c->name).'"><span>'.htmlspecialchars($c->caption).':</span> <span class="value">'.htmlspecialchars($v).'</span></span>';
    }

    /** @return array<string, mixed> variables used by queries/_query_form */
    private function queryFormVars(Request $request, TimeEntryQuery $query, ?Project $project): array
    {
        $listRoute = $project ? 'project_time_entries' : 'time_entries_index';
        $base = $project ? ['project_id' => $project->identifier] : [];

        return [
            'clear_url' => $this->generateUrl($listRoute, $base + ['set_filter' => 1, 'sort' => '']),
            'new_query_url' => $this->routeExistsSafe($project ? 'project_query_new' : 'query_new') ? $this->generateUrl($project ? 'project_query_new' : 'query_new', $base) : '#',
            'can_save_queries' => $this->auth->allowedTo('save_queries', $project, null, true),
            'extra_query_params' => [],
        ];
    }

    private function routeExistsSafe(string $name): bool
    {
        return null !== $this->container->get('router')->getRouteCollection()->get($name);
    }

    private function retrieveQuery(Request $request, ?Project $project): TimeEntryQuery
    {
        try {
            $query = $this->queries->retrieve($request, 'TimeEntryQuery', $project, false);
        } catch (\App\Query\QueryError) {
            $query = $this->queries->create('TimeEntryQuery', $project);
        }
        \assert($query instanceof TimeEntryQuery);

        return $query;
    }

    private function queryError(Request $request): Response
    {
        $this->flashError($this->l('error_query_statement_invalid'));

        return $this->redirectToRoute('home');
    }

    private function exportAllowed(TimeEntryQuery $query, ?Project $project): bool
    {
        $user = $this->user();
        if ($user->admin) {
            return true;
        }
        if (null !== $project) {
            if (!$this->auth->allowedTo('export_time_entries', $project)) {
                return false;
            }
            if (($project->rgt - $project->lft) <= 1) {
                return true;
            }
        } elseif (!$this->auth->allowedToGlobally('export_time_entries')) {
            return false;
        }
        $cond = $this->auth->allowedToCondition('export_time_entries', 'p.id');
        $allowed = array_map('intval', $this->em->createQuery("SELECT p.id FROM App\Entity\Project p WHERE $cond")->getSingleColumnResult());
        $projects = array_map('intval', array_column($this->queryContext->conn()->fetchAllAssociative(
            'SELECT DISTINCT time_entries.project_id AS pid FROM '.$query->baseFromSql().' WHERE '.$query->whereSql()
        ), 'pid'));

        return [] === array_diff($projects, $allowed);
    }

    // --- Report -------------------------------------------------------------------

    #[Route('/time_entries/report', name: 'time_entries_report', methods: ['GET'])]
    #[Route('/time_entries/report.{_format}', name: 'time_entries_report_format', requirements: ['_format' => 'csv'], methods: ['GET'])]
    #[Route('/projects/{project_id}/time_entries/report', name: 'project_time_entries_report', requirements: ['project_id' => '[^/.]+'], methods: ['GET'])]
    #[Route('/projects/{project_id}/time_entries/report.{_format}', name: 'project_time_entries_report_format', requirements: ['project_id' => '[^/.]+', '_format' => 'csv'], methods: ['GET'])]
    public function report(Request $request, ?string $project_id = null, string $_format = 'html'): Response
    {
        $project = $this->findOptionalProject($project_id, 'view_time_entries');
        $query = $this->retrieveQuery($request, $project);
        if ('csv' === $_format && !$this->exportAllowed($query, $project)) {
            $this->deny();
        }
        $criteria = $request->query->all()['criteria'] ?? [];
        $report = new TimeReport($this->queryContext, $query, $project, array_values(array_filter((array) $criteria, static fn ($c) => '' !== (string) $c)), $request->query->get('columns') ? (string) $request->query->get('columns') : null);

        if ('csv' === $_format) {
            return $this->csvResponse($request, $this->reportToCsv($request, $report), 'timelog.csv');
        }

        return $this->render($request->isXmlHttpRequest() ? 'timelog/_report_xhr.html.twig' : 'timelog/report.html.twig', [
            'project' => $project,
            'query' => $query,
            'report' => $report,
            'rows' => $report->criteria ? $report->rows() : [],
            'export_allowed' => $this->exportAllowed($query, $project),
            'main_menu_selected' => 'time_entries',
            'body_css' => 'controller-timelog action-report',
            'page_title' => ($query->isNew() ? $this->l('label_spent_time') : $query->name()),
            'list_route' => $project ? 'project_time_entries' : 'time_entries_index',
            'route_params' => $project ? ['project_id' => $project->identifier] : [],
        ] + $this->queryFormVars($request, $query, $project));
    }

    // --- CSV ----------------------------------------------------------------------

    /** @param list<list<string|float|int>> $rows */
    private function csvResponse(Request $request, array $rows, string $filename): Response
    {
        $sep = (string) ($request->query->get('field_separator') ?: $this->l('general_csv_separator'));
        $encoding = (string) ($request->query->get('encoding') ?: $this->l('general_csv_encoding'));
        // Ruby CSV: fields quoted only when needed, empty strings as ""
        $csv = '';
        foreach ($rows as $row) {
            $csv .= implode($sep, array_map(static function ($v) use ($sep): string {
                $v = (string) $v;
                if ('' === $v || str_contains($v, $sep) || str_contains($v, '"') || str_contains($v, "\n") || str_contains($v, "\r")) {
                    return '"'.str_replace('"', '""', $v).'"';
                }

                return $v;
            }, $row))."\n";
        }
        if (0 === strcasecmp($encoding, 'UTF-8')) {
            $csv = "\xEF\xBB\xBF".$csv;
        } else {
            $converted = @mb_convert_encoding($csv, $encoding, 'UTF-8');
            $csv = false !== $converted ? $converted : $csv;
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; header=present',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function exportFilename(Request $request, TimeEntryQuery $query, string $default): string
    {
        $name = (string) ($request->query->get('query_name') ?: $query->name());
        if ('_' === $name || '' === trim($name)) {
            $name = $default;
        }
        $name = mb_strtolower(preg_replace('/[^\w\-]+/u', '_', $name) ?? $default);

        return $name.'.csv';
    }

    /** @param list<TimeEntry> $entries */
    private function queryToCsv(Request $request, TimeEntryQuery $query, array $entries): array
    {
        $c = (array) ($request->query->all()['c'] ?? []);
        if (\in_array('all_inline', $c, true)) {
            $query->setColumnNames(['all_inline']);
        }
        $columns = $query->columns();
        $rows = [array_map(static fn (QueryColumn $c) => $c->caption, $columns)];
        foreach ($entries as $e) {
            $rows[] = array_map(fn (QueryColumn $c) => $this->timelogHelper->csvValue($query, $c, $e), $columns);
        }

        return $rows;
    }

    private function reportToCsv(Request $request, TimeReport $report): array
    {
        $fmt = fn (float $h) => str_replace('.', $this->l('general_csv_decimal_separator'), \sprintf('%.2f', $h));
        $available = $report->availableCriteria();
        $headers = array_map(static fn ($c) => $available[$c]['label'], $report->criteria);
        $headers = array_merge($headers, $report->periods, [$this->l('label_total_time')]);
        $rows = [$headers];
        foreach ($report->criteria ? $report->rows() : [] as $r) {
            $row = array_fill(0, $r['level'], '');
            $row[] = html_entity_decode(strip_tags($this->timelogHelper->criteriaValue($report, $r['criteria'], $r['value'], false)));
            $row = array_merge($row, array_fill(0, \count($report->criteria) - $r['level'] - 1, ''));
            foreach ($r['periods'] as $p) {
                $row[] = $p > 0 ? $fmt($p) : '';
            }
            $row[] = $fmt($r['total']);
            $rows[] = $row;
        }
        $total = 0.0;
        $row = array_merge([$this->l('label_total_time')], array_fill(0, max(0, \count($report->criteria) - 1), ''));
        foreach ($report->periodTotals() as $p) {
            $total += $p;
            $row[] = $p > 0 ? $fmt($p) : '';
        }
        $row[] = $fmt($total);
        $rows[] = $row;

        return $rows;
    }

    // --- Show (API) ---------------------------------------------------------------

    #[Route('/time_entries/{id}', name: 'time_entry_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[Route('/time_entries/{id}.{_format}', name: 'time_entry_show_format', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['GET'])]
    public function show(Request $request, int $id, string $_format = 'html'): Response
    {
        $entry = $this->findTimeEntry($id);
        $this->authorize('view_time_entries', $entry->project);
        if ('html' === $_format) {
            return new Response('', 406);
        }

        return $this->api->render($request, ['time_entry' => $this->timeEntries->toApi($entry)]);
    }

    // --- New / create -------------------------------------------------------------

    #[Route('/time_entries/new', name: 'time_entry_new_global', methods: ['GET', 'POST'])]
    #[Route('/projects/{project_id}/time_entries/new', name: 'time_entry_new', requirements: ['project_id' => '[^/.]+'], methods: ['GET', 'POST'])]
    #[Route('/issues/{issue_id}/time_entries/new', name: 'issue_time_entry_new', requirements: ['issue_id' => '\d+'], methods: ['GET', 'POST'])]
    #[Route('/time_entries/new.js', name: 'time_entry_new_js', methods: ['GET', 'POST'], defaults: ['_js' => true])]
    public function new(Request $request, ?string $project_id = null, ?int $issue_id = null): Response
    {
        if (null === $project_id && null === $issue_id && $request->attributes->get('_js')) {
            if ($request->request->get('project_id')) {
                $project_id = (string) $request->request->get('project_id');
            } elseif ($request->request->get('issue_id')) {
                $issue_id = (int) $request->request->get('issue_id');
            }
        }
        [$project, $issue] = $this->findOptionalIssueOrProject($project_id, $issue_id, $request);
        $entry = $this->timeEntries->newEntry($project, $issue);
        $attrs = $request->request->all('time_entry') ?: $request->query->all('time_entry');
        if ([] !== $attrs) {
            $this->timeEntries->setAttributes($entry, $attrs);
        }
        if ($request->attributes->get('_js') || 'js' === $request->query->get('format')) {
            return $this->formRefreshJs($entry, $project);
        }

        return $this->renderForm($request, $entry, $project, $issue, []);
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
        $attrs = $isApi ? (array) (ApiRenderer::payload($request)['time_entry'] ?? []) : $request->request->all('time_entry');
        // the project/issue may also be given in the params (form hidden fields / API)
        if (null === $project_id && null === $issue_id) {
            if ($request->request->has('project_id')) {
                $project_id = (string) $request->request->get('project_id');
            } elseif ($request->request->has('issue_id')) {
                $issue_id = (int) $request->request->get('issue_id');
            } elseif (isset($attrs['issue_id']) && '' !== (string) $attrs['issue_id'] && !isset($attrs['project_id'])) {
                $issue_id = (int) $attrs['issue_id'];
            }
        }
        if (null !== $issue_id && null === $this->em->find(Issue::class, $issue_id) && null === $project_id) {
            if ($isApi) {
                // same errors as Redmine (the project of the unknown issue is blank, hence invalid)
                return $this->api->errors($request, [
                    $this->l('field_project').' '.$this->l('activerecord.errors.messages.blank'),
                    $this->l('field_project').' '.$this->l('activerecord.errors.messages.invalid'),
                    $this->l('field_issue').' '.$this->l('activerecord.errors.messages.invalid'),
                ]);
            }
            throw $this->createNotFoundException();
        }
        [$project, $issue] = $this->findOptionalIssueOrProject($project_id, $issue_id, $request);
        $user = $this->user();
        $entry = $this->timeEntries->newEntry($project, $issue);
        $entry->user = $user;
        $this->timeEntries->setAttributes($entry, $attrs);
        if (isset($entry->project) && !$this->auth->allowedTo('log_time', $entry->project)) {
            $this->deny();
        }
        $this->callHook('controller_timelog_edit_before_save', ['params' => $request->request->all(), 'time_entry' => $entry, 'project' => $entry->project ?? null]);
        $errors = $this->timeEntries->save($entry);
        if ([] === $errors) {
            if ($isApi) {
                $response = $this->api->render($request, ['time_entry' => $this->timeEntries->toApi($entry)], 201);
                $response->headers->set('Location', $this->generateUrl('time_entry_show_format', ['id' => $entry->id, '_format' => $_format], 0));

                return $response;
            }
            $this->flashNotice('notice_successful_create');
            if ($request->request->has('continue')) {
                $options = ['time_entry' => array_filter([
                    'project_id' => $attrs['project_id'] ?? null,
                    'issue_id' => $entry->issue?->id,
                    'spent_on' => $entry->spentOn->format('Y-m-d'),
                    'activity_id' => $entry->activity->id,
                ], static fn ($v) => null !== $v && '' !== $v)];
                if ($request->request->get('back_url')) {
                    $options['back_url'] = (string) $request->request->get('back_url');
                }
                if (null !== $project_id && isset($entry->project)) {
                    $options['time_entry']['project_id'] ??= $entry->project->id;

                    return $this->redirectToRoute('time_entry_new', ['project_id' => $entry->project->identifier] + $options);
                }
                if (null !== $issue_id && null !== $entry->issue) {
                    return $this->redirectToRoute('issue_time_entry_new', ['issue_id' => $entry->issue->id] + $options);
                }

                return $this->redirectToRoute('time_entry_new_global', $options);
            }

            return $this->redirectBackOr($request, $this->generateUrl('project_time_entries', ['project_id' => $entry->project->identifier]));
        }
        if ($isApi) {
            return $this->api->errors($request, $errors);
        }

        return $this->renderForm($request, $entry, $project, $issue, $errors);
    }

    // --- Edit / update ------------------------------------------------------------

    #[Route('/time_entries/{id}/edit', name: 'time_entry_edit', requirements: ['id' => '\d+'], methods: ['GET', 'PATCH', 'POST'])]
    #[Route('/time_entries/{id}/edit.js', name: 'time_entry_edit_js', requirements: ['id' => '\d+'], methods: ['GET', 'PATCH', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $entry = $this->findTimeEntry($id);
        $this->authorizeEdit($entry);
        $this->timeEntries->snapshot($entry);
        $attrs = $request->request->all('time_entry') ?: $request->query->all('time_entry');
        if ([] !== $attrs) {
            $this->timeEntries->setAttributes($entry, $attrs);
        }
        if (!$request->isMethod('GET') || 'js' === $request->query->get('format')) {
            return $this->formRefreshJs($entry, $entry->project);
        }

        return $this->renderForm($request, $entry, $entry->project, null, []);
    }

    #[Route('/time_entries/{id}', name: 'time_entry_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    #[Route('/time_entries/{id}.{_format}', name: 'time_entry_update_format', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id, string $_format = 'html'): Response
    {
        $isApi = \in_array($_format, ['json', 'xml'], true);
        $entry = $this->findTimeEntry($id);
        $this->authorizeEdit($entry);
        $this->timeEntries->snapshot($entry);
        $attrs = $isApi ? (array) (ApiRenderer::payload($request)['time_entry'] ?? []) : $request->request->all('time_entry');
        $this->timeEntries->setAttributes($entry, $attrs);
        $this->callHook('controller_timelog_edit_before_save', ['params' => $request->request->all(), 'time_entry' => $entry, 'project' => $entry->project ?? null]);
        $errors = $this->timeEntries->save($entry);
        if ([] === $errors) {
            if ($isApi) {
                return $this->api->noContent();
            }
            $this->flashNotice('notice_successful_update');

            return $this->redirectBackOr($request, $this->generateUrl('project_time_entries', ['project_id' => $entry->project->identifier]));
        }
        if ($isApi) {
            $this->em->refresh($entry);

            return $this->api->errors($request, $errors);
        }

        return $this->renderForm($request, $entry, $entry->project, null, $errors);
    }

    // --- Destroy ------------------------------------------------------------------

    #[Route('/time_entries/{id}', name: 'time_entry_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[Route('/time_entries/{id}.{_format}', name: 'time_entry_destroy_format', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['DELETE'])]
    #[Route('/time_entries/destroy', name: 'time_entries_destroy', methods: ['DELETE', 'POST'])]
    public function destroy(Request $request, ?int $id = null, string $_format = 'html'): Response
    {
        $isApi = \in_array($_format, ['json', 'xml'], true);
        $entries = $this->findTimeEntries($request, $id);
        foreach ($entries as $e) {
            $this->timeEntries->destroy($e);
        }
        if ($isApi) {
            return $this->api->noContent();
        }
        $this->flashNotice('notice_successful_delete');
        $default = $this->generateUrl('project_time_entries', ['project_id' => $entries[0]->project->identifier]);
        $back = (string) ($request->request->get('back_url') ?? $request->query->get('back_url') ?? '');
        if ('' === $back && ($ref = $request->headers->get('referer'))) {
            $path = parse_url($ref, \PHP_URL_PATH);
            $q = parse_url($ref, \PHP_URL_QUERY);
            if (\is_string($path) && !preg_match('#/time_entries/\d+#', $path)) {
                return $this->redirect($path.($q ? '?'.$q : ''));
            }
        }

        return $this->redirectBackOr($request, $default);
    }

    // --- Bulk edit ------------------------------------------------------------------

    #[Route('/time_entries/bulk_edit', name: 'time_entries_bulk_edit', methods: ['GET', 'POST'])]
    public function bulkEdit(Request $request): Response
    {
        $entries = $this->findTimeEntries($request, null);

        return $this->renderBulkEdit($request, $entries, [], [], $request->request->all('time_entry') ?: $request->query->all('time_entry'));
    }

    #[Route('/time_entries/bulk_update', name: 'time_entries_bulk_update', methods: ['POST'])]
    public function bulkUpdate(Request $request): Response
    {
        $entries = $this->findTimeEntries($request, null);
        $attrs = $request->request->all('time_entry') ?: $request->query->all('time_entry');
        // parse_params_for_bulk_update: drop blank values, "none" clears
        $clean = [];
        foreach ($attrs as $k => $v) {
            if ('custom_field_values' === $k) {
                foreach ((array) $v as $cfId => $cfv) {
                    if (\is_array($cfv)) {
                        $cfv = array_values(array_filter($cfv, static fn ($x) => '' !== (string) $x));
                        if ([] === $cfv) {
                            continue;
                        }
                        if (\in_array('__none__', $cfv, true)) {
                            $cfv = [];
                        }
                    } elseif ('' === (string) $cfv) {
                        continue;
                    } elseif ('__none__' === $cfv) {
                        $cfv = '';
                    }
                    $clean['custom_field_values'][$cfId] = $cfv;
                }
                continue;
            }
            if (null === $v || '' === $v) {
                continue;
            }
            $clean[$k] = 'none' === $v ? '' : $v;
        }
        $unsaved = [];
        $saved = [];
        $errors = [];
        foreach ($entries as $e) {
            $this->em->refresh($e);
            $this->timeEntries->snapshot($e);
            $this->timeEntries->setAttributes($e, $clean);
            $this->callHook('controller_time_entries_bulk_edit_before_save', ['params' => $request->request->all(), 'time_entry' => $e, 'project' => $e->project ?? null]);
            $err = $this->timeEntries->save($e);
            if ([] === $err) {
                $saved[] = $e;
            } else {
                $unsaved[] = $e;
                foreach ($err as $m) {
                    $errors[$m] = $m;
                }
                $this->em->refresh($e);
            }
        }
        if ([] === $unsaved) {
            if ([] !== $saved) {
                $this->flashNotice('notice_successful_update');
            }

            return $this->redirectBackOr($request, $this->generateUrl('project_time_entries', ['project_id' => $entries[0]->project->identifier]));
        }

        return $this->renderBulkEdit($request, $unsaved, $entries, array_values($errors), $attrs);
    }

    private function renderBulkEdit(Request $request, array $entries, array $savedEntries, array $errors, array $params): Response
    {
        $targetProjects = $this->logTimeProjects();
        $projects = $this->projectsOf($entries);
        $target = null;
        if (isset($params['project_id']) && '' !== (string) $params['project_id']) {
            foreach ($targetProjects as $p) {
                if ((string) $p->id === (string) $params['project_id']) {
                    $target = $p;
                }
            }
        }
        if (null !== $target) {
            $activities = $this->timeEntries->activities($target);
        } else {
            $activities = null;
            foreach ($projects as $p) {
                $acts = $this->timeEntries->activities($p);
                $activities = null === $activities ? $acts : array_values(array_filter($activities, static fn ($a) => \in_array($a->id, array_map(static fn ($b) => $b->id, $acts), true)));
            }
            $activities ??= [];
        }
        $fields = array_values(array_filter($this->customFields->fieldsOf(\App\Entity\TimeEntryCustomField::class), static fn ($f) => !\in_array($f->fieldFormat, ['attachment'], true)));
        $vars = [
            'entries' => $entries,
            'saved_entries' => $savedEntries,
            'unsaved_entries' => [] !== $errors ? $entries : [],
            'errors' => $errors,
            'target_projects' => $targetProjects,
            'target_project' => $target,
            'activities' => $activities,
            'custom_fields' => $fields,
            'params' => $params,
            'project' => 1 === \count($projects) ? $projects[0] : null,
            'main_menu_selected' => 'time_entries',
            'body_css' => 'controller-timelog action-bulk_edit',
            'page_title' => $this->l('label_bulk_edit_selected_time_entries'),
        ];
        if ($request->isXmlHttpRequest()) {
            $html = $this->renderView('timelog/_bulk_edit_content.html.twig', $vars);

            return new Response("\$('#content').html('".self::escapeJs($html)."');\n", 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->render('timelog/bulk_edit.html.twig', $vars);
    }

    // --- Context menu ---------------------------------------------------------------

    #[Route('/time_entries/context_menu', name: 'time_entries_context_menu', methods: ['GET', 'POST'])]
    public function contextMenu(Request $request): Response
    {
        $ids = (array) ($request->request->all()['ids'] ?? $request->query->all()['ids'] ?? []);
        $entries = $this->timeEntries->findByIds($ids);
        if ([] === $entries) {
            throw $this->createNotFoundException();
        }
        foreach ($entries as $e) {
            if (!$this->timeEntries->isVisible($e)) {
                throw $this->createNotFoundException();
            }
        }
        $projects = $this->projectsOf($entries);
        $activities = null;
        foreach ($projects as $p) {
            $acts = $this->timeEntries->activities($p);
            $activities = null === $activities ? $acts : array_values(array_filter($activities, static fn ($a) => \in_array($a->id, array_map(static fn ($b) => $b->id, $acts), true)));
        }
        $editAllowed = true;
        foreach ($entries as $e) {
            $editAllowed = $editAllowed && $this->timeEntries->isEditable($e);
        }
        $optionsByField = [];
        if ($editAllowed) {
            $fieldSets = array_map(fn ($e) => array_map(static fn ($f) => $f->id, $this->timeEntries->editableCustomFields($e)), $entries);
            $common = [] !== $fieldSets ? array_intersect(...$fieldSets) : [];
            foreach ($this->customFields->fieldsOf(\App\Entity\TimeEntryCustomField::class) as $f) {
                if (!\in_array($f->id, $common, true) || $f->isMultiple() || !\in_array($f->fieldFormat, ['list', 'bool', 'date', 'enumeration', 'user', 'version'], true)) {
                    continue;
                }
                $values = $this->customFields->possibleValuesOptions($f, 1 === \count($projects) ? $projects[0] : null);
                if ([] !== $values) {
                    $optionsByField[] = ['field' => $f, 'options' => $values];
                }
            }
        }
        $back = (string) ($request->request->get('back_url') ?? $request->query->get('back_url') ?? '');
        if ('' === $back || !str_starts_with($back, '/')) {
            $ref = $request->headers->get('referer');
            $back = $ref ? (string) (parse_url($ref, \PHP_URL_PATH).((string) parse_url($ref, \PHP_URL_QUERY) ? '?'.parse_url($ref, \PHP_URL_QUERY) : '')) : '';
        }

        return $this->render('context_menus/time_entries.html.twig', [
            'entries' => $entries,
            'entry' => 1 === \count($entries) ? $entries[0] : null,
            'ids' => array_map(static fn ($e) => $e->id, $entries),
            'activities' => $activities ?? [],
            'can_edit' => $editAllowed,
            'options_by_field' => $optionsByField,
            'back' => $back,
        ]);
    }

    // --- Helpers ------------------------------------------------------------------

    private function findOptionalProject(?string $projectId, string $permission): ?Project
    {
        $project = null !== $projectId && '' !== $projectId ? $this->findProject($projectId) : null;
        if (!$this->auth->allowedTo($permission, $project, null, null === $project)) {
            $this->deny();
        }

        return $project;
    }

    /** find_optional_issue (log_time on the issue's project) or find_optional_project */
    private function findOptionalIssueOrProject(?string $projectId, ?int $issueId, Request $request): array
    {
        if (null !== $issueId) {
            $issue = $this->em->find(Issue::class, $issueId);
            if (null === $issue) {
                throw $this->createNotFoundException();
            }
            $this->authorize('log_time', $issue->project);

            return [$issue->project, $issue];
        }

        return [$this->findOptionalProject($projectId, 'log_time'), null];
    }

    private function isIssueVisible(Issue $issue): bool
    {
        return $this->issues->isVisible($issue);
    }

    private function findTimeEntry(int $id): TimeEntry
    {
        $entry = $this->em->find(TimeEntry::class, $id);
        if (null === $entry) {
            throw $this->createNotFoundException();
        }
        if (!$this->timeEntries->isVisible($entry)) {
            $this->deny();
        }

        return $entry;
    }

    private function authorizeEdit(TimeEntry $entry): void
    {
        if (!$this->auth->allowedTo('edit_time_entries', $entry->project) && !$this->auth->allowedTo('edit_own_time_entries', $entry->project)) {
            $this->deny();
        }
        if (!$this->timeEntries->isEditable($entry)) {
            throw new HttpException(403, $this->l('notice_not_authorized'));
        }
    }

    /** find_time_entries: all must be editable */
    private function findTimeEntries(Request $request, ?int $id): array
    {
        $ids = null !== $id ? [$id] : (array) ($request->request->all()['ids'] ?? $request->query->all()['ids'] ?? $request->request->all()['id'] ?? $request->query->all()['id'] ?? []);
        $entries = $this->timeEntries->findByIds($ids);
        if ([] === $entries) {
            throw $this->createNotFoundException();
        }
        foreach ($entries as $e) {
            if (!$this->timeEntries->isEditable($e)) {
                $this->deny();
            }
        }

        return $entries;
    }

    /** @param list<TimeEntry> $entries @return list<Project> */
    private function projectsOf(array $entries): array
    {
        $out = [];
        foreach ($entries as $e) {
            $out[$e->project->id] = $e->project;
        }

        return array_values($out);
    }

    /** @return list<Project> Project.allowed_to(:log_time) */
    private function logTimeProjects(): array
    {
        $cond = $this->auth->allowedToCondition('log_time', 'p.id');

        return $this->em->createQuery("SELECT p FROM App\Entity\Project p WHERE $cond ORDER BY p.lft")->getResult();
    }

    private function renderForm(Request $request, TimeEntry $entry, ?Project $project, ?Issue $issue, array $errors): Response
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
        $fields = isset($entry->project) || null !== $project ? $this->editableFields($entry, $project) : [];

        return $this->render(null === $entry->id ? 'timelog/new.html.twig' : 'timelog/edit.html.twig', [
            'entry' => $entry,
            'project' => $project,
            'issue' => $issue,
            'errors' => $errors,
            'activities' => $activities,
            'blank_activity' => (isset($entry->activity) && !$entry->activity->active) || !$hasDefault,
            'users' => $users,
            'log_for_others' => $this->auth->allowedTo('log_time_for_other_users', $formProject),
            'log_time_projects' => $this->logTimeProjects(),
            'custom_fields' => $fields,
            'custom_values' => $this->timeEntries->customValues($entry),
            'hours_value' => $this->hoursValue($entry, $request),
            'required_fields' => $this->settings->array('timelog_required_fields'),
            'route_project_id' => $request->attributes->get('project_id') ?? $request->request->get('project_id'),
            'route_issue_id' => $request->attributes->get('issue_id') ?? $request->request->get('issue_id'),
            'back_url' => (string) ($request->request->get('back_url') ?? $request->query->get('back_url') ?? ''),
            'main_menu_selected' => 'time_entries',
            'body_css' => 'controller-timelog action-'.(null === $entry->id ? 'new' : 'edit'),
            'page_title' => $this->l('label_spent_time'),
        ], new Response('', [] !== $errors ? 422 : 200));
    }

    private function editableFields(TimeEntry $entry, ?Project $project): array
    {
        if (!isset($entry->project) && null !== $project) {
            $entry->project = $project;
        }

        return isset($entry->project) ? $this->timeEntries->editableCustomFields($entry) : [];
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

    /** new.js.erb / edit.js.erb: refreshes activities and the issue link */
    private function formRefreshJs(TimeEntry $entry, ?Project $project): Response
    {
        $activities = $this->timeEntries->activities(isset($entry->project) ? $entry->project : $project);
        $hasDefault = false;
        foreach ($activities as $a) {
            $hasDefault = $hasDefault || $a->isDefault;
        }
        $selected = isset($entry->activity) ? $entry->activity->id : null;
        if (null === $entry->id) {
            $selected = $this->timeEntries->defaultActivityId($this->user(), isset($entry->project) ? $entry->project : null) ?? $selected;
            if (isset($entry->activity) && \in_array($entry->activity->id, array_map(static fn ($a) => $a->id, $activities), true)) {
                $selected = $entry->activity->id;
            }
        }
        $options = '';
        if ((isset($entry->activity) && !$entry->activity->active) || !$hasDefault) {
            $options .= '<option value="">--- '.htmlspecialchars($this->l('actionview_instancetag_blank_option')).' ---</option>';
        }
        foreach ($activities as $a) {
            $options .= '<option'.($a->id === $selected ? ' selected="selected"' : '').' value="'.$a->id.'">'.htmlspecialchars($a->name).'</option>';
        }
        $issueLink = null !== $entry->issue && $this->isIssueVisible($entry->issue) ? $this->timelogHelper->formatObject($entry->issue, true) : '';
        $js = "\$('#time_entry_activity_id').html('".self::escapeJs($options)."');\n\$('#time_entry_issue').html('".self::escapeJs($issueLink)."');\n";

        return new Response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }

    private static function escapeJs(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '</' => '<\/', "\r\n" => '\n', "\n" => '\n', "\r" => '\n', '"' => '\"', "'" => "\\'"]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Entity\Board;
use App\Entity\IssueCustomField;
use App\Entity\IssueQuery;
use App\Entity\News;
use App\Entity\Project;
use App\Entity\ProjectCustomField;
use App\Entity\Repository;
use App\Entity\Tracker;
use App\Entity\User;
use App\Query\ProjectQuery as EngineProjectQuery;
use App\Query\QueryError;
use App\Query\QueryFactory;
use App\Security\Authorization;
use App\Service\Activity\ProjectLastActivity;
use App\Service\CsvExport;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Service\MembersManager;
use App\Service\ProjectManager;
use App\Service\ProjectQueryService;
use App\Twig\ProjectQueryExtension;
use App\Twig\ProjectsExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's ProjectsController. */
final class ProjectsController extends AbstractController
{
    public function __construct(
        private readonly ProjectManager $manager,
        private readonly ProjectQueryService $queries,
        private readonly CustomFieldService $customFields,
        private readonly IssueService $issues,
        private readonly MembersManager $members,
        private readonly ApiRenderer $api,
        private readonly \App\Service\AtomFeed $atom,
        private readonly QueryFactory $queryFactory,
        private readonly ProjectLastActivity $lastActivity,
        private readonly ProjectQueryExtension $projectQueryHelper,
        private readonly CsvExport $csv,
    ) {
    }

    // --- index --------------------------------------------------------------------------------

    #[Route('/projects', name: 'projects_index', methods: ['GET'])]
    #[Route('/projects.{_format}', name: 'projects_index_format', requirements: ['_format' => 'json|xml|atom|csv'], methods: ['GET'])]
    public function index(Request $request, string $_format = 'html'): Response
    {
        if ($request->query->has('jump') && '' !== (string) $request->query->get('jump')) {
            if ($r = $this->redirectToMenuItem((string) $request->query->get('jump'))) {
                return $r;
            }
        }
        if ('csv' === $_format) {
            return $this->indexCsv($request);
        }
        $api = \in_array($_format, ['json', 'xml'], true);
        $state = $this->queries->fromRequest($request, $api);
        if ($api) {
            $count = $this->queries->count($state);
            $pager = $this->paginate($request, $count);
            $projects = $this->queries->results($state, $pager['offset'], $pager['limit']);
            $includes = $this->includes($request);

            return $this->api->render($request, [
                'projects' => array_map(fn (Project $p) => $this->projectPayload($p, $includes, false), $projects),
                'total_count' => $count, 'offset' => $pager['offset'], 'limit' => $pager['limit'],
            ]);
        }
        if ('atom' === $_format) {
            $projects = $this->queries->results($state, 0, max(1, $this->settings->int('feeds_limit') ?: 15), 'p.createdOn DESC');

            $items = array_map(fn (Project $p) => [
                'title' => $p->name,
                'url' => $this->generateUrl('project_show', ['id' => $p->identifier]),
                'updated' => $p->createdOn ?? new \DateTimeImmutable(),
                'author' => null,
                'content' => $p->description,
                'project' => null,
            ], $projects);

            return $this->atom->render($request, $this->settings->appTitle().': '.$this->l('label_project_latest'), $items);
        }
        $vars = [
            'query' => $state,
            'available_filters' => $this->queries->availableFilters(),
            'available_columns' => $this->queries->availableColumns(),
            'groupable_columns' => $this->queries->groupableColumns(),
            'sidebar_queries' => $this->queries->visibleQueries(),
            'operators_by_type' => ProjectQueryService::OPERATORS_BY_TYPE,
            'operator_labels' => $this->queries->operatorLabels(),
            'main_menu_selected' => 'projects',
            'page_title' => $this->l('label_project_plural'),
            'body_css' => 'controller-projects action-index',
            'atom_key' => $this->atomKey(),
        ];
        if ($state['valid']) {
            $vars['csv_hidden_fields'] = $this->csvHiddenFields($state);
            if ('board' === $state['display_type']) {
                $vars['entries'] = $this->queries->results($state);
            } else {
                $count = $this->queries->count($state);
                $vars['pager'] = $this->paginate($request, $count);
                $vars['entries'] = $this->queries->results($state, $vars['pager']['offset'], $vars['pager']['limit']);
                $vars['custom_values'] = $this->customFields->valuesFor('Project', array_map(static fn (Project $p) => (int) $p->id, $vars['entries']));
                $vars['custom_fields'] = $this->queries->visibleCustomFields();
                if (\in_array('last_activity_date', $state['columns'], true) && [] !== $vars['entries']) {
                    $tz = $this->i18n->userTimezone();
                    $vars['last_activity_dates'] = array_map(static fn (\DateTimeImmutable $t) => ['time' => $t, 'date' => $t->setTimezone($tz)->format('Y-m-d')], $this->lastActivity->lastActivityDates($this->user(), array_map(static fn (Project $p) => (int) $p->id, $vars['entries'])));
                }
            }
        }

        return $this->render('projects/index.html.twig', $vars);
    }

    /** format.csv of ProjectsController#index: retrieve_default_query + retrieve_query(ProjectQuery) + query_to_csv */
    private function indexCsv(Request $request): Response
    {
        $q = $request->query;
        if ('' === (string) $q->get('query_id', '') && !$q->has('set_filter')) {
            if ('' !== (string) $q->get('without_default', '')) {
                $q->set('set_filter', '1');
            } elseif (null !== ($defaultId = $this->queries->defaultQueryId())) {
                $q->set('query_id', (string) $defaultId);
            }
        }
        try {
            $query = $this->queryFactory->retrieve($request, 'ProjectQuery', null, false);
        } catch (QueryError) {
            $query = $this->queryFactory->create('ProjectQuery');
        }
        \assert($query instanceof EngineProjectQuery);
        if (!$query->isValid()) {
            return $this->redirectToRoute('projects_index', $query->asParams());
        }
        $entries = $query->results();
        if ($query->hasColumn('last_activity_date') && [] !== $entries) {
            $query->setLastActivityDates($this->lastActivity->lastActivityDates($this->user(), array_map(static fn (Project $p) => (int) $p->id, $entries)));
        }
        $columns = $query->columns();
        $rows = [array_map(static fn ($c) => $c->caption, $columns)];
        foreach ($entries as $project) {
            $rows[] = array_map(fn ($c) => $this->projectQueryHelper->csvValue($query, $c, $project), $columns);
        }

        return $this->csv->response($request, $rows, 'projects.csv');
    }

    /** query_as_hidden_field_tags(@query) for the CSV export form of the projects list */
    private function csvHiddenFields(array $state): string
    {
        $h = static fn (string $name, string $value): string => '<input type="hidden" name="'.htmlspecialchars($name).'" value="'.htmlspecialchars($value).'" autocomplete="off" />';
        $tags = $h('set_filter', '1');
        if ([] === $state['filters']) {
            $tags .= $h('f[]', '');
        }
        foreach ($state['filters'] as $field => $f) {
            $tags .= $h('f[]', (string) $field).$h('op['.$field.']', (string) $f['operator']);
            foreach ($f['values'] as $v) {
                $tags .= $h('v['.$field.'][]', (string) $v);
            }
        }
        foreach ($state['columns'] as $c) {
            $tags .= $h('c[]', (string) $c);
        }
        if ($state['group_by']) {
            $tags .= $h('group_by', (string) $state['group_by']);
        }
        if ([] !== $state['sort']) {
            $tags .= $h('sort', implode(',', array_map(static fn ($s) => $s[0].('desc' === $s[1] ? ':desc' : ''), $state['sort'])));
        }

        return $tags;
    }

    private function redirectToMenuItem(string $jump): ?Response
    {
        $routes = ['issues' => 'issues_index', 'activity' => 'activity', 'time_entries' => 'time_entries_index', 'gantt' => 'gantt', 'calendar' => 'calendar', 'news' => 'news_index', 'projects' => null];
        $route = $routes[$jump] ?? null;
        if (null === $route || null === $this->container->get('router')->getRouteCollection()->get($route)) {
            return null;
        }

        return $this->redirectToRoute($route);
    }

    private function atomKey(): ?string
    {
        $user = $this->user();
        if (!$user->isLogged()) {
            return null;
        }
        $conn = $this->em->getConnection();
        $key = $conn->fetchOne("SELECT value FROM tokens WHERE user_id = ? AND action = 'feeds'", [$user->id]);
        if (!$key) {
            $key = bin2hex(random_bytes(20));
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $conn->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) VALUES (?, 'feeds', ?, ?, ?)", [$user->id, $key, $now, $now]);
        }

        return (string) $key;
    }

    #[Route('/projects/autocomplete.js', name: 'projects_autocomplete', methods: ['GET'], priority: 10)]
    #[Route('/projects/autocomplete', name: 'projects_autocomplete_noformat', methods: ['GET'], priority: 10)]
    public function autocomplete(Request $request): Response
    {
        // respond_to { format.js }: without the .js suffix the format comes from the Accept header
        if ('projects_autocomplete_noformat' === $request->attributes->get('_route') && !self::acceptsJs($request)) {
            return new Response('', 406, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        $q = trim((string) $request->query->get('q', ''));
        $user = $this->user();
        if ('' !== $q) {
            $like = '%'.mb_strtolower(addcslashes($q, '%_\\')).'%';
            $projects = $this->em->createQuery('SELECT p FROM App\Entity\Project p WHERE '.Authorization::in('p.id', $this->auth->visibleProjectIds()).' AND (LOWER(p.identifier) LIKE :q OR LOWER(p.name) LIKE :q) ORDER BY p.lft')
                ->setParameter('q', $like)->getResult();
        } else {
            $ids = array_keys($this->auth->membershipsOf($user));
            $projects = [] === $ids ? [] : $this->em->createQuery('SELECT p FROM App\Entity\Project p WHERE p.id IN (:ids) AND p.status <> 9 AND p.status <> 10 ORDER BY p.lft')->setParameter('ids', $ids)->getResult();
        }
        $html = '';
        if ([] !== $projects) {
            $html = $this->container->get('twig')->getFunction('render_projects_for_jump_box')->getCallable()($projects, $q, (string) $request->query->get('jump', '') ?: 'overview'); // params[:jump].presence || current_menu_item
        } elseif ('' !== $q) {
            $html = '<span>'.htmlspecialchars($this->l('label_no_data')).'</span>';
        }

        // projects/autocomplete.js.erb
        return new Response("$('#project-jump .drdn-items.projects').html('".self::escapeJavascript($html)."');\n", 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }

    /** ActionView::Helpers::JavaScriptHelper#escape_javascript */
    private static function escapeJavascript(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '</' => '<\/', "\r\n" => '\n', "\n" => '\n', "\r" => '\n', '"' => '\\"', "'" => "\\'", '`' => '\\`', '$' => '\\$', "\u{2028}" => '&#x2028;', "\u{2029}" => '&#x2029;']);
    }

    // --- new / create -------------------------------------------------------------------------

    #[Route('/projects/new', name: 'project_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->authorize('add_project', null, true);
        $project = $this->manager->newProject();
        $attrs = (array) ($request->query->all()['project'] ?? []);
        if ($request->query->has('parent_id')) {
            $parent = $this->projects->findByIdentifierOrId((string) $request->query->get('parent_id'));
            $attrs['parent_id'] = $parent?->id;
        }
        $this->manager->setSafeAttributes($project, $attrs);

        return $this->renderForm('projects/new.html.twig', $project, []);
    }

    #[Route('/projects', name: 'project_create', methods: ['POST'])]
    #[Route('/projects.{_format}', name: 'project_create_format', requirements: ['_format' => 'json|xml'], methods: ['POST'])]
    public function create(Request $request): Response
    {
        $api = $this->isApi($request);
        if (!$this->auth->allowedToGlobally('add_project')) {
            $this->deny();
        }
        $attrs = $api ? (array) (ApiRenderer::payload($request)['project'] ?? []) : (array) ($request->request->all()['project'] ?? []);
        $project = $this->manager->newProject();
        $this->manager->setSafeAttributes($project, $attrs);
        $errors = $this->manager->save($project);
        if ([] !== $errors) {
            if ($api) {
                return $this->api->errors($request, $errors);
            }

            return $this->renderForm('projects/new.html.twig', $project, $errors);
        }
        if (!$this->user()->admin) {
            $this->manager->addDefaultMember($project, $this->user());
        }
        if ($api) {
            $response = $this->api->render($request, ['project' => $this->projectPayload($project, [], true)], 201);
            $response->headers->set('Location', $this->generateUrl('project_show', ['id' => $project->id], 0));

            return $response;
        }
        $this->flashNotice('notice_successful_create');
        if ($request->request->has('continue')) {
            return $this->redirectToRoute('project_new', array_filter(['parent_id' => $project->parent?->id]));
        }

        return $this->redirectToRoute('project_settings', ['id' => $project->identifier]);
    }

    /** @param list<string> $errors */
    private function renderForm(string $template, Project $project, array $errors, array $extra = []): Response
    {
        return $this->render($template, $extra + $this->formVars($project) + [
            'errors' => $errors,
            'main_menu_selected' => 'projects',
            'page_title' => $this->l('label_project_new'),
            'body_css' => 'controller-projects action-'.('projects/copy.html.twig' === $template ? 'copy' : 'new'),
        ]);
    }

    /** Variables needed by projects/_form.html.twig */
    private function formVars(Project $project): array
    {
        $parents = $this->manager->allowedParents($project);

        return [
            'form_project' => $project,
            'allowed_parents' => $parents,
            'allow_root' => \in_array(null, $parents, true),
            'parent_options' => array_values(array_filter($parents)),
            'custom_fields' => $this->manager->visibleCustomFields($project),
            'custom_values' => $this->manager->customValues($project),
            'identifier_frozen' => $this->manager->isIdentifierFrozen($project),
            'safe_is_public' => $this->manager->isSafeAttribute($project, 'is_public'),
            'safe_modules' => $this->manager->isSafeAttribute($project, 'enabled_module_names'),
            'safe_inherit_members' => $this->manager->isSafeAttribute($project, 'inherit_members'),
            'available_modules' => \App\Security\AccessControl::modules(),
            'inherit_confirm' => !$this->user()->admin && $project->inheritMembers && null !== $project->parent && $this->auth->isMemberOf($this->user(), $project->parent),
        ];
    }

    // --- copy ---------------------------------------------------------------------------------

    #[Route('/projects/{id}/copy', name: 'project_copy', methods: ['GET', 'POST'])]
    public function copy(Request $request, string $id): Response
    {
        $this->requireAdmin();
        $source = $this->projects->findByIdentifierOrId($id) ?? throw $this->createNotFoundException();
        if ($request->isMethod('GET')) {
            $project = $this->manager->copyFrom($source);
            $errors = [];
        } else {
            $project = $this->manager->newProject();
            $this->manager->setSafeAttributes($project, (array) ($request->request->all()['project'] ?? []));
            $only = $request->request->all()['only'] ?? null;
            $only = \is_array($only) ? array_values(array_filter(array_map('strval', $only))) : null;
            $errors = $this->manager->copy($source, $project, $only);
            if ([] === $errors) {
                $this->flashNotice('notice_successful_create');

                return $this->redirectToRoute('project_settings', ['id' => $project->identifier]);
            }
        }

        return $this->renderForm('projects/copy.html.twig', $project, $errors, [
            'source_project' => $source,
            'copy_counts' => $this->manager->copyCounts($source),
        ]);
    }

    // --- show ---------------------------------------------------------------------------------

    #[Route('/projects/{id}', name: 'project_show', requirements: ['id' => '[^/.]+'], methods: ['GET'])]
    #[Route('/projects/{id}.{_format}', name: 'project_show_format', requirements: ['id' => '[^/.]+', '_format' => 'json|xml'], methods: ['GET'])]
    public function show(Request $request, string $id, string $_format = 'html'): Response
    {
        $project = $this->findProject($id);
        $this->authorize('view_project', $project);
        if (\in_array($_format, ['json', 'xml'], true)) {
            return $this->api->render($request, ['project' => $this->projectPayload($project, $this->includes($request), true)]);
        }
        if ($request->query->has('jump') && '' !== (string) $request->query->get('jump')) {
            foreach ($this->container->get('twig')->getFunction('project_menu')->getCallable()($project) as $item) {
                if ($item['name'] === $request->query->get('jump')) {
                    return $this->redirect($item['url']);
                }
            }
        }
        $user = $this->user();
        $this->manager->projectUsed($user, $project);
        $withSubprojects = $this->settings->displaySubprojectsIssues();
        $projectCond = $withSubprojects
            ? 'pp.lft >= '.(int) $project->lft.' AND pp.rgt <= '.(int) $project->rgt
            : 'pp.id = '.(int) $project->id;

        // trackers (rolled_up_trackers.visible)
        $trackerDql = $withSubprojects
            ? 'SELECT DISTINCT t FROM App\Entity\Tracker t JOIN t.projects pp WHERE pp.lft >= :l AND pp.rgt <= :r AND pp.status <> 9 AND pp.status <> 10 ORDER BY t.position'
            : 'SELECT t FROM App\Entity\Tracker t JOIN t.projects pp WHERE pp.id = :id ORDER BY t.position';
        $tq = $this->em->createQuery($trackerDql);
        $withSubprojects ? $tq->setParameters(['l' => $project->lft, 'r' => $project->rgt]) : $tq->setParameter('id', $project->id);
        $trackers = array_values(array_filter($tq->getResult(), fn (Tracker $t) => $this->trackerVisible($t)));

        $visible = $this->issues->visibleCondition($user, 'i');
        $open = [];
        $total = [];
        foreach ($this->em->createQuery("SELECT IDENTITY(i.tracker) AS t, COUNT(i.id) AS c FROM App\Entity\Issue i JOIN i.project pp JOIN i.status s WHERE $visible AND $projectCond AND s.isClosed = false GROUP BY i.tracker")->getResult() as $row) {
            $open[(int) $row['t']] = (int) $row['c'];
        }
        foreach ($this->em->createQuery("SELECT IDENTITY(i.tracker) AS t, COUNT(i.id) AS c FROM App\Entity\Issue i JOIN i.project pp WHERE $visible AND $projectCond GROUP BY i.tracker")->getResult() as $row) {
            $total[(int) $row['t']] = (int) $row['c'];
        }
        $totalHours = null;
        $totalEstimated = null;
        if ($this->allowedToViewAllTimeEntries($project)) {
            $teCond = $this->auth->allowedToCondition('view_time_entries', 'IDENTITY(te.project)', $user, function ($role, User $u) {
                return 'all' === $role->timeEntriesVisibility ? null : 'IDENTITY(te.user) = '.(int) $u->id;
            });
            $totalHours = (float) $this->em->createQuery("SELECT COALESCE(SUM(te.hours), 0) FROM App\Entity\TimeEntry te JOIN te.project pp WHERE $teCond AND $projectCond")->getSingleScalarResult();
            $totalEstimated = (float) $this->em->createQuery("SELECT COALESCE(SUM(i.estimatedHours), 0) FROM App\Entity\Issue i JOIN i.project pp WHERE $visible AND $projectCond")->getSingleScalarResult();
        }
        $news = $this->em->createQuery('SELECT n FROM App\Entity\News n WHERE n.project = :p ORDER BY n.createdOn DESC')->setParameter('p', $project)->setMaxResults(5)->getResult();
        $subprojects = array_values(array_filter($project->children->toArray(), fn (Project $c) => !$c->isArchived() && $this->auth->isProjectVisible($c)));
        $customValues = $this->manager->customValues($project);
        $cfDisplay = [];
        foreach ($this->manager->visibleCustomFields($project) as $f) {
            $formatted = $this->customFields->formatValue($f, $customValues[$f->id] ?? null);
            if ('' !== $formatted) {
                $cfDisplay[] = ['field' => $f, 'value' => $formatted];
            }
        }

        return $this->render('projects/show.html.twig', [
            'project' => $project,
            'main_menu_selected' => 'overview',
            'page_title' => $this->l('label_overview'),
            'body_css' => 'controller-projects action-show',
            'principals_by_role' => $this->manager->principalsByRole($project),
            'subprojects' => $subprojects,
            'news' => $news,
            'trackers' => $trackers,
            'open_by_tracker' => $open,
            'total_by_tracker' => $total,
            'total_hours' => $totalHours,
            'total_estimated_hours' => $totalEstimated,
            'custom_field_values' => $cfDisplay,
            'deletable' => $this->manager->isDeletable($project),
            'bookmarked' => \in_array($project->id, $this->manager->bookmarkedProjectIds($user), true),
            'atom_key' => $this->atomKey(),
        ]);
    }

    private function trackerVisible(Tracker $tracker): bool
    {
        $user = $this->user();
        if ($user->admin) {
            return true;
        }
        foreach ($this->auth->allRoles($user) as $role) {
            if ($this->auth->roleAllowedTo($role, 'view_issues') && ($role->permissionsAllTrackers('view_issues') || \in_array($tracker->id, $role->permissionsTrackerIds('view_issues'), true))) {
                return true;
            }
        }

        return false;
    }

    /** User#allowed_to_view_all_time_entries? */
    private function allowedToViewAllTimeEntries(Project $project): bool
    {
        return $this->auth->allowedTo('view_time_entries', $project, null, false, static fn ($role) => 'all' === $role->timeEntriesVisibility);
    }

    // --- settings / update --------------------------------------------------------------------

    /** @return list<array{name: string, label: string, partial: string}> */
    private function settingsTabs(Project $project): array
    {
        $defs = [
            ['info', 'edit_project', null, 'label_project'],
            ['members', 'manage_members', null, 'label_member_plural'],
            ['issues', 'edit_project', 'issue_tracking', 'label_issue_tracking'],
            ['versions', 'manage_versions', null, 'label_version_plural'],
            ['categories', 'manage_categories', null, 'label_issue_category_plural'],
            ['repositories', 'manage_repository', null, 'label_repository_plural'],
            ['boards', 'manage_boards', null, 'label_board_plural'],
            ['activities', 'manage_project_activities', null, 'label_time_tracking'],
        ];
        $tabs = [];
        foreach ($defs as [$name, $perm, $module, $label]) {
            if ($this->auth->allowedTo($perm, $project) && (null === $module || $project->isModuleEnabled($module))) {
                $tabs[] = ['name' => $name, 'label' => $this->l($label)];
            }
        }

        return $tabs;
    }

    #[Route('/projects/{id}/settings/{tab}', name: 'project_settings', requirements: ['id' => '[^/.]+'], defaults: ['tab' => null], methods: ['GET'])]
    public function settings(Request $request, string $id, ?string $tab = null): Response
    {
        $project = $this->findProject($id);
        if (!$this->projects->canSeeSettings($project)) {
            $this->deny();
        }

        return $this->renderSettings($request, $project, $tab, []);
    }

    /** @param list<string> $errors */
    private function renderSettings(Request $request, Project $project, ?string $tab, array $errors): Response
    {
        $tabs = $this->settingsTabs($project);
        if ([] === $tabs) {
            $this->deny();
        }
        $names = array_column($tabs, 'name');
        $selected = \in_array($tab, $names, true) ? $tab : $names[0];

        $versionStatus = $request->query->has('version_status') ? (string) $request->query->get('version_status') : 'open';
        $versionName = trim((string) $request->query->get('version_name', ''));
        $versions = array_values(array_filter($this->projects->sharedVersions($project), static function ($v) use ($versionStatus, $versionName) {
            if ('' !== $versionStatus && $v->status !== $versionStatus) {
                return false;
            }

            return '' === $versionName || str_contains(mb_strtolower((string) $v->name), mb_strtolower($versionName));
        }));
        $issueQueries = $this->em->createQuery('SELECT q FROM App\Entity\IssueQuery q WHERE q.visibility = 2 AND (q.project IS NULL OR q.project = :p) ORDER BY q.name')->setParameter('p', $project)->getResult();
        $openShared = array_values(array_filter($this->projects->sharedVersions($project), static fn ($v) => $v->isOpen()));
        if (null !== $project->defaultVersion && !\in_array($project->defaultVersion, $openShared, true)) {
            $openShared[] = $project->defaultVersion;
        }
        $assignable = $this->projects->assignableUsers($project);
        if (null !== $project->defaultAssignedTo && !\in_array($project->defaultAssignedTo, $assignable, true)) {
            $assignable[] = $project->defaultAssignedTo;
        }
        $allIssueFieldIds = array_map(static fn ($f) => $f->id, $project->issueCustomFields->toArray());

        return $this->render('projects/settings.html.twig', $this->formVars($project) + [
            'project' => $project,
            'errors' => $errors,
            'tabs' => $tabs,
            'selected_tab' => $selected,
            'main_menu_selected' => 'settings',
            'page_title' => $this->l('label_settings'),
            'body_css' => 'controller-projects action-settings',
            // members
            'members' => $this->members->projectMembers($project),
            'members_manager' => $this->members,
            // issues tab
            'trackers' => $this->em->getRepository(Tracker::class)->findBy([], ['position' => 'ASC']),
            'issue_custom_fields' => $this->em->getRepository(IssueCustomField::class)->findBy([], ['position' => 'ASC']),
            'all_issue_custom_field_ids' => $allIssueFieldIds,
            'default_version_options' => $openShared,
            'default_assignee_options' => $assignable,
            'issue_queries_global' => array_values(array_filter($issueQueries, static fn ($q) => null === $q->project)),
            'issue_queries_project' => array_values(array_filter($issueQueries, static fn ($q) => null !== $q->project)),
            // versions / categories (partials provided by the versions module)
            'versions' => $versions,
            'version_status' => $versionStatus,
            'version_name' => $versionName,
            'categories' => $project->issueCategories->toArray(),
            // repositories / boards / activities
            'repositories' => $this->em->getRepository(Repository::class)->findBy(['project' => $project], ['isDefault' => 'DESC', 'identifier' => 'ASC']),
            'boards' => $this->boardsTree($project),
            'activities' => $this->manager->activities($project, true),
            'activity_custom_fields' => $this->customFields->fieldsOf(\App\Entity\TimeEntryActivityCustomField::class),
        ]);
    }

    /** @return list<array{0: Board, 1: int}> */
    private function boardsTree(Project $project): array
    {
        $boards = $this->em->getRepository(Board::class)->findBy(['project' => $project], ['position' => 'ASC']);
        $out = [];
        $walk = function (?Board $parent, int $level) use (&$walk, &$out, $boards): void {
            foreach ($boards as $b) {
                if ($b->parent?->id === $parent?->id) {
                    $out[] = [$b, $level];
                    $walk($b, $level + 1);
                }
            }
        };
        $walk(null, 0);

        return $out;
    }

    #[Route('/projects/{id}', name: 'project_update', requirements: ['id' => '[^/.]+'], methods: ['PUT', 'PATCH'])]
    #[Route('/projects/{id}.{_format}', name: 'project_update_format', requirements: ['id' => '[^/.]+', '_format' => 'json|xml'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, string $id): Response
    {
        $api = $this->isApi($request);
        $project = $this->findProject($id);
        $this->authorize('edit_project', $project);
        $attrs = $api ? (array) (ApiRenderer::payload($request)['project'] ?? []) : (array) ($request->request->all()['project'] ?? []);
        $this->manager->setSafeAttributes($project, $attrs);
        $errors = $this->manager->save($project);
        if ([] !== $errors) {
            if ($api) {
                return $this->api->errors($request, $errors);
            }
            return $this->renderSettings($request, $project, (string) $request->request->get('tab', 'info'), $errors);
        }
        if ($api) {
            return $this->api->noContent();
        }
        $this->flashNotice();
        $tab = (string) $request->request->get('tab', '');

        return $this->redirectToRoute('project_settings', array_filter(['id' => $project->identifier, 'tab' => '' !== $tab ? $tab : null]));
    }

    // --- archive / close ----------------------------------------------------------------------

    private function findAny(string $id): Project
    {
        $project = $this->projects->findByIdentifierOrId($id) ?? throw $this->createNotFoundException();

        return $project;
    }

    #[Route('/projects/{id}/archive', name: 'project_archive', requirements: ['id' => '[^/.]+'], methods: ['POST', 'PUT'])]
    #[Route('/projects/{id}/archive.{_format}', name: 'project_archive_format', requirements: ['id' => '[^/.]+', '_format' => 'json|xml'], methods: ['POST', 'PUT'])]
    public function archive(Request $request, string $id): Response
    {
        $this->requireAdmin();
        $project = $this->findAny($id);
        $ok = $this->manager->archive($project);
        if ($this->isApi($request)) {
            return $ok ? $this->api->noContent() : $this->api->errors($request, [$this->l('error_can_not_archive_project')]);
        }
        if (!$ok) {
            $this->flashError($this->l('error_can_not_archive_project'));
        }

        return $this->redirectToRefererOrAdmin($request);
    }

    #[Route('/projects/{id}/unarchive', name: 'project_unarchive', requirements: ['id' => '[^/.]+'], methods: ['POST', 'PUT'])]
    #[Route('/projects/{id}/unarchive.{_format}', name: 'project_unarchive_format', requirements: ['id' => '[^/.]+', '_format' => 'json|xml'], methods: ['POST', 'PUT'])]
    public function unarchive(Request $request, string $id): Response
    {
        $this->requireAdmin();
        $project = $this->findAny($id);
        if (!$project->isActive()) {
            $this->manager->unarchive($project);
        }
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }

        return $this->redirectToRefererOrAdmin($request);
    }

    private function redirectToRefererOrAdmin(Request $request): Response
    {
        $referer = (string) $request->headers->get('referer', '');
        if ('' !== $referer && str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return $this->redirect($referer);
        }
        if (null !== $this->container->get('router')->getRouteCollection()->get('admin_projects')) {
            return $this->redirectToRoute('admin_projects', array_filter(['status' => $request->query->get('status')]));
        }

        return $this->redirectToRoute('projects_index');
    }

    #[Route('/projects/{id}/close', name: 'project_close', requirements: ['id' => '[^/.]+'], methods: ['POST', 'PUT'])]
    #[Route('/projects/{id}/close.{_format}', name: 'project_close_format', requirements: ['id' => '[^/.]+', '_format' => 'json|xml'], methods: ['POST', 'PUT'])]
    public function close(Request $request, string $id): Response
    {
        $project = $this->findProject($id);
        $this->authorize('close_project', $project);
        $this->manager->close($project);
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }

        return $this->redirectToRoute('project_show', ['id' => $project->identifier]);
    }

    #[Route('/projects/{id}/reopen', name: 'project_reopen', requirements: ['id' => '[^/.]+'], methods: ['POST', 'PUT'])]
    #[Route('/projects/{id}/reopen.{_format}', name: 'project_reopen_format', requirements: ['id' => '[^/.]+', '_format' => 'json|xml'], methods: ['POST', 'PUT'])]
    public function reopen(Request $request, string $id): Response
    {
        $project = $this->findProject($id);
        $this->authorize('close_project', $project);
        $this->manager->reopen($project);
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }

        return $this->redirectToRoute('project_show', ['id' => $project->identifier]);
    }

    #[Route('/projects/{id}/bookmark', name: 'project_bookmark', requirements: ['id' => '[^/.]+'], methods: ['POST', 'DELETE'])]
    public function bookmark(Request $request, string $id): Response
    {
        $project = $this->findProject($id);
        $this->authorize('view_project', $project);
        $this->requireLogin();
        $this->manager->bookmark($this->user(), $project, $request->isMethod('POST'));
        if ($request->isXmlHttpRequest()) {
            $twig = $this->container->get('twig');
            $items = $twig->getFunction('project_jump_box_items')->getCallable()($project);
            $link = $twig->render('projects/_bookmark_link.html.twig', ['project' => $project, 'bookmarked' => $request->isMethod('POST')]);

            return new Response(
                "$('#project-jump div.drdn-items.projects').html(".json_encode($items, \JSON_HEX_TAG).");\n$('.contextual a.icon.bookmark').replaceWith(".json_encode(trim($link), \JSON_HEX_TAG).');',
                200,
                ['Content-Type' => 'text/javascript; charset=utf-8'],
            );
        }

        return $this->redirectToRoute('project_show', ['id' => $project->identifier]);
    }

    /**
     * ProjectsController#edit: an empty action without template (authorize :edit_project). Rails renders
     * 406 Not Acceptable for interactive browser (HTML) requests and 204 No Content otherwise.
     */
    #[Route('/projects/{id}/edit', name: 'project_edit', requirements: ['id' => '[^/.]+'], methods: ['GET'])]
    public function edit(Request $request, string $id): Response
    {
        $project = $this->findProject($id);
        $this->authorize('edit_project', $project);
        if (!$request->isXmlHttpRequest() && 'html' === self::negotiatedFormat($request)) {
            return new Response('', 406, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        return new Response('', 204);
    }

    /** Format negotiated by Rails from the Accept header (ActionDispatch::Http::MimeNegotiation#formats) */
    private static function negotiatedFormat(Request $request): string
    {
        $accept = trim((string) $request->headers->get('Accept', ''));
        $browserLike = (bool) preg_match('#,\s*\*/\*|\*/\*\s*,#', $accept);
        if ('' === $accept || ($browserLike && !$request->isXmlHttpRequest())) {
            return 'html';
        }
        $types = [];
        foreach (explode(',', $accept) as $i => $part) {
            $segments = array_map('trim', explode(';', $part));
            $q = 1.0;
            foreach (\array_slice($segments, 1) as $param) {
                if (str_starts_with($param, 'q=')) {
                    $q = (float) substr($param, 2);
                }
            }
            $types[] = [strtolower($segments[0]), $q, $i];
        }
        usort($types, static fn ($a, $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);
        $first = $types[0][0] ?? '*/*';

        return match ($first) {
            'text/html', 'application/xhtml+xml' => 'html',
            'text/javascript', 'application/javascript', 'application/x-javascript' => 'js',
            '*/*' => 'all',
            default => 'other',
        };
    }

    /** respond_to { format.js } accepts the request */
    private static function acceptsJs(Request $request): bool
    {
        $accept = trim((string) $request->headers->get('Accept', ''));
        $browserLike = (bool) preg_match('#,\s*\*/\*|\*/\*\s*,#', $accept);
        if ('' === $accept || ($browserLike && !$request->isXmlHttpRequest())) {
            return false; // html
        }
        foreach (explode(',', $accept) as $part) {
            $type = strtolower(trim(explode(';', $part)[0]));
            if (\in_array($type, ['text/javascript', 'application/javascript', 'application/x-javascript', '*/*'], true)) {
                return true;
            }
        }

        return false;
    }

    // --- destroy ------------------------------------------------------------------------------
    /** ProjectsController#bulk_destroy (admin only): confirmation page, then deletion of the selected projects */
    #[Route('/projects/bulk_destroy', name: 'projects_bulk_destroy', methods: ['DELETE'], priority: 10)]
    public function bulkDestroy(Request $request): Response
    {
        $this->requireAdmin();
        $params = array_replace($request->query->all(), $request->request->all());
        $ids = array_values(array_unique(array_filter(array_map('intval', \is_array($params['ids'] ?? null) ? $params['ids'] : (isset($params['ids']) ? [$params['ids']] : [])))));
        $projects = [] === $ids ? [] : $this->em->createQuery('SELECT p FROM App\Entity\Project p WHERE p.id IN (:ids) AND p.status <> :deleted ORDER BY p.id')
            ->setParameter('ids', $ids)->setParameter('deleted', Project::STATUS_SCHEDULED_FOR_DELETION)->getResult();
        if ([] === $projects) {
            throw $this->createNotFoundException();
        }
        if (isset($params['confirm']) && $params['confirm'] === $this->l('general_text_Yes')) {
            // DestroyProjectsJob.schedule: deletion is done synchronously, like the single destroy
            foreach (array_map(static fn (Project $p) => (int) $p->id, $projects) as $projectId) {
                $project = $this->em->find(Project::class, $projectId);
                if (null !== $project) {
                    $this->manager->destroy($project);
                }
            }
            $this->flashNotice('notice_successful_delete');

            return $this->redirectToRoute('admin_projects');
        }

        return $this->render('projects/bulk_destroy.html.twig', [
            'projects_to_destroy' => array_map(static fn (Project $p) => ['project' => $p, 'descendants' => \array_slice($p->getSelfAndDescendants(), 1)], $projects),
            'ids' => array_map(static fn (Project $p) => $p->id, $projects),
            'head_html' => self::simpleFormat($this->l('text_projects_bulk_destroy_head')),
            'page_title' => $this->l('label_confirmation'),
            'body_css' => 'controller-projects action-bulk_destroy',
        ]);
    }


    #[Route('/projects/{id}', name: 'project_destroy', requirements: ['id' => '[^/.]+'], methods: ['DELETE'])]
    #[Route('/projects/{id}.{_format}', name: 'project_destroy_format', requirements: ['id' => '[^/.]+', '_format' => 'json|xml'], methods: ['DELETE'])]
    public function destroy(Request $request, string $id): Response
    {
        $project = $this->findProject($id);
        if (!$this->manager->isDeletable($project)) {
            $this->deny();
        }
        $api = $this->isApi($request);
        if ($api || $request->request->get('confirm') === $project->identifier) {
            $this->manager->destroy($project);
            if ($api) {
                return $this->api->noContent();
            }
            $this->flashNotice('notice_successful_delete');
            $admin = $this->user()->admin && null !== $this->container->get('router')->getRouteCollection()->get('admin_projects');

            return $this->redirectToRoute($admin ? 'admin_projects' : 'projects_index');
        }

        return $this->render('projects/destroy.html.twig', [
            'project_to_destroy' => $project,
            'descendants' => array_slice($project->getSelfAndDescendants(), 1),
            'page_title' => $this->l('label_confirmation'),
            'body_css' => 'controller-projects action-destroy',
            'cancel_url' => $this->user()->admin && null !== $this->container->get('router')->getRouteCollection()->get('admin_projects') ? $this->generateUrl('admin_projects') : $this->generateUrl('projects_index'),
        ]);
    }

    /** ActionView simple_format: paragraphs in <p>, single newlines followed by text get a <br /> */
    private static function simpleFormat(string $text): string
    {
        $paragraphs = preg_split('/\n\n+/', str_replace(["\r\n", "\r"], "\n", $text)) ?: [];

        return implode("\n\n", array_map(static fn (string $t) => '<p>'.preg_replace('/([^\n]\n)(?=[^\n])/', '$1<br />', htmlspecialchars($t)).'</p>', $paragraphs));
    }

    // --- API helpers --------------------------------------------------------------------------

    /** @return list<string> */
    private function includes(Request $request): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $request->query->get('include', '')))));
    }

    /** @param list<string> $includes */
    private function projectPayload(Project $p, array $includes, bool $show): array
    {
        $data = [
            'id' => $p->id,
            'name' => $p->name,
            'identifier' => $p->identifier,
            'description' => $p->description,
            'homepage' => $p->homepage,
        ];
        if (null !== $p->parent && $this->auth->isProjectVisible($p->parent)) {
            $data['parent'] = ['id' => $p->parent->id, 'name' => $p->parent->name];
        }
        $data['status'] = $p->status;
        $data['is_public'] = $p->isPublic;
        $data['inherit_members'] = $p->inheritMembers;
        if ($show) {
            if (null !== $p->defaultVersion) {
                $data['default_version'] = ['id' => $p->defaultVersion->id, 'name' => $p->defaultVersion->name];
            }
            if (null !== $p->defaultAssignedTo) {
                $data['default_assignee'] = ['id' => $p->defaultAssignedTo->id, 'name' => $p->defaultAssignedTo->getName()];
            }
        }
        $fields = $this->manager->visibleCustomFields($p);
        if ([] !== $fields) {
            $values = $this->customFields->values('Project', $p->id);
            $data['custom_fields'] = array_map(static function ($f) use ($values) {
                $v = $values[$f->id] ?? ($f->isMultiple() ? [] : null);
                $row = ['id' => $f->id, 'name' => $f->name];
                if ($f->isMultiple()) {
                    $row['multiple'] = true;
                }
                $row['value'] = $v;

                return $row;
            }, $fields);
        }
        // render_api_includes: trackers, categories and issue custom fields need view_issues,
        // activities need view_time_entries
        $viewIssues = $this->auth->allowedTo('view_issues', $p);
        $viewTime = $this->auth->allowedTo('view_time_entries', $p);
        if ($viewIssues && \in_array('trackers', $includes, true)) {
            $data['trackers'] = array_map(static fn (Tracker $t) => ['id' => $t->id, 'name' => $t->name], $p->trackers->toArray());
        }
        if ($viewIssues && \in_array('issue_categories', $includes, true)) {
            $data['issue_categories'] = array_map(static fn ($c) => ['id' => $c->id, 'name' => $c->name], array_values($p->issueCategories->toArray()));
        }
        if ($viewTime && \in_array('time_entry_activities', $includes, true)) {
            $data['time_entry_activities'] = array_map(static fn ($a) => ['id' => $a->id, 'name' => $a->name], $this->manager->activities($p));
        }
        if (\in_array('enabled_modules', $includes, true)) {
            $mods = $p->enabledModules->toArray();
            usort($mods, static fn ($a, $b) => $a->id <=> $b->id);
            $data['enabled_modules'] = array_map(static fn ($m) => ['id' => $m->id, 'name' => $m->name], $mods);
        }
        if ($viewIssues && \in_array('issue_custom_fields', $includes, true)) {
            $ids = array_map(static fn ($f) => $f->id, $p->issueCustomFields->toArray());
            $all = array_filter($this->em->getRepository(IssueCustomField::class)->findBy([], ['position' => 'ASC']), static fn ($f) => $f->isForAll || \in_array($f->id, $ids, true));
            $data['issue_custom_fields'] = array_values(array_map(static fn ($f) => ['id' => $f->id, 'name' => $f->name], $all));
        }
        $data['created_on'] = ApiRenderer::time($p->createdOn);
        $data['updated_on'] = ApiRenderer::time($p->updatedOn);

        return $data;
    }
}

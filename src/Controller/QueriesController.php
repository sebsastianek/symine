<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Entity\Project;
use App\Entity\Query as QueryRecord;
use App\Entity\Role;
use App\Query\AbstractQuery;
use App\Query\IssueQuery;
use App\Query\QueryFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of QueriesController: saved queries CRUD, queries API index and filter values endpoint. */
final class QueriesController extends AbstractController
{
    public function __construct(
        private readonly QueryFactory $queries,
        private readonly ApiRenderer $api,
    ) {
    }

    #[Route('/queries.{_format}', name: 'queries_index', requirements: ['_format' => 'json|xml|html'], defaults: ['_format' => 'html'], methods: ['GET'], priority: 10)]
    public function index(Request $request, string $_format = 'html'): Response
    {
        if ('html' === $_format) {
            return new Response('', 406);
        }
        $type = (string) $request->query->get('type', 'IssueQuery');
        if (!$this->queries->hasType($type)) {
            throw $this->createNotFoundException();
        }
        $limit = max(1, min(100, (int) ($request->query->get('limit') ?: 25)));
        $offset = max(0, (int) $request->query->get('offset', 0));
        if ($request->query->has('page') && !$request->query->has('offset')) {
            $offset = max(0, ((int) $request->query->get('page') - 1) * $limit);
        }
        $all = $this->queries->visibleQueries($type, null, null, false);
        usort($all, static fn (QueryRecord $a, QueryRecord $b) => strcmp($a->name, $b->name));
        $list = array_map(static fn (QueryRecord $q) => [
            'id' => $q->id,
            'name' => $q->name,
            'is_public' => QueryRecord::VISIBILITY_PRIVATE !== (int) $q->visibility,
            'project_id' => $q->project?->id,
        ], \array_slice($all, $offset, $limit));

        return $this->api->render($request, ['queries' => $list, 'total_count' => \count($all), 'offset' => $offset, 'limit' => $limit]);
    }

    #[Route('/queries/filter', name: 'queries_filter', methods: ['GET'], priority: 10)]
    public function filter(Request $request): JsonResponse
    {
        $type = (string) $request->query->get('type', 'IssueQuery');
        $project = null;
        if ('' !== (string) $request->query->get('project_id', '')) {
            $project = $this->projects->findByIdentifierOrId((string) $request->query->get('project_id'));
            if (null === $project) {
                throw $this->createNotFoundException();
            }
        }
        $query = $this->queries->create($type, $project);
        if (!$this->auth->allowedTo($query::viewPermission(), $project, null, true)) {
            $this->deny();
        }
        $filter = $query->availableFilters()[(string) $request->query->get('name')] ?? null;

        return new JsonResponse(null !== $filter ? ($filter->values() ?? []) : []);
    }

    #[Route('/queries/new', name: 'query_new', methods: ['GET'], priority: 10)]
    public function new(Request $request): Response
    {
        return $this->doNew($request, null);
    }

    #[Route('/projects/{project_id}/queries/new', name: 'project_query_new', methods: ['GET'], priority: 10)]
    public function projectNew(Request $request, string $project_id): Response
    {
        return $this->doNew($request, $this->findProject($project_id));
    }

    private function doNew(Request $request, ?Project $project): Response
    {
        $type = $this->queryType($request);
        $this->requireSaveQueries($project);
        $query = $this->queries->create($type, $project, '');
        $query->buildFromParams($this->params($request));

        return $this->renderForm($request, $query, $project, true);
    }

    #[Route('/queries', name: 'query_create', methods: ['POST'], priority: 10)]
    public function create(Request $request): Response
    {
        return $this->doCreate($request, null);
    }

    #[Route('/projects/{project_id}/queries', name: 'project_query_create', methods: ['POST'], priority: 10)]
    public function projectCreate(Request $request, string $project_id): Response
    {
        return $this->doCreate($request, $this->findProject($project_id));
    }

    private function doCreate(Request $request, ?Project $project): Response
    {
        $type = $this->queryType($request);
        $this->requireSaveQueries($project);
        $query = $this->queries->create($type, $project, '');
        $query->record->userId = (int) $this->user()->id;
        $this->updateFromParams($request, $query, $project);
        if ($query->isValid(true)) {
            $this->em->persist($query->record);
            $this->em->flush();
            $this->flashNotice('notice_successful_create');

            return $this->redirectToItems($request, $query, $project, ['query_id' => $query->record->id]);
        }

        return $this->renderForm($request, $query, $project, true);
    }

    #[Route('/queries/{id}/edit', name: 'query_edit', requirements: ['id' => '\d+'], methods: ['GET'], priority: 10)]
    public function edit(Request $request, int $id): Response
    {
        [$query, $project] = $this->findQuery($id);

        return $this->renderForm($request, $query, $project, false);
    }

    #[Route('/queries/{id}', name: 'query_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'], priority: 10)]
    public function update(Request $request, int $id): Response
    {
        [$query, $project] = $this->findQuery($id);
        $this->updateFromParams($request, $query, $project);
        if ($query->isValid(true)) {
            $this->em->flush();
            $this->flashNotice('notice_successful_update');

            return $this->redirectToItems($request, $query, $query->record->project, ['query_id' => $query->record->id]);
        }

        return $this->renderForm($request, $query, $project, false);
    }

    #[Route('/queries/{id}', name: 'query_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'], priority: 10)]
    public function destroy(Request $request, int $id): Response
    {
        [$query, $project] = $this->findQuery($id);
        // Project#default_issue_query is nullified like Redmine (dependent: :nullify)
        $this->em->getConnection()->executeStatement('UPDATE projects SET default_issue_query_id = NULL WHERE default_issue_query_id = ?', [$id]);
        $this->em->remove($query->record);
        $this->em->flush();

        return $this->redirectToItems($request, $query, $project, ['set_filter' => 1]);
    }

    // ------------------------------------------------------------------------------------------

    private function queryType(Request $request): string
    {
        $type = (string) ($request->request->get('type') ?? $request->query->get('type') ?? 'IssueQuery');
        if ('' === $type) {
            $type = 'IssueQuery';
        }
        if (!$this->queries->hasType($type)) {
            throw $this->createNotFoundException();
        }

        return $type;
    }

    private function requireSaveQueries(?Project $project): void
    {
        if (!$this->auth->allowedTo('save_queries', $project, null, true)) {
            $this->deny();
        }
    }

    /** @return array{0: AbstractQuery, 1: ?Project} */
    private function findQuery(int $id): array
    {
        $record = $this->queries->find($id);
        if (null === $record || !$this->queries->hasType($record::typeName())) {
            throw $this->createNotFoundException();
        }
        $project = $record->project;
        $query = $this->queries->wrap($record, $project);
        if (!$query->isEditableBy($this->user())) {
            $this->deny();
        }

        return [$query, $project];
    }

    /** All request params (query string + body), like Rails params */
    private function params(Request $request): array
    {
        return array_replace($request->query->all(), $request->request->all());
    }

    /** QueriesController#update_query_from_params */
    private function updateFromParams(Request $request, AbstractQuery $query, ?Project $project): void
    {
        $params = $this->params($request);
        $q = \is_array($params['query'] ?? null) ? $params['query'] : [];
        $query->record->project = !empty($params['query_is_for_all']) ? null : $project;
        $query->project = $query->record->project;
        $query->buildFromParams($params);
        if (!empty($params['default_columns'])) {
            $query->setColumnNames(null);
        }
        if (isset($q['sort_criteria']) && \is_array($q['sort_criteria'])) {
            $query->setSortCriteria(array_values($q['sort_criteria']));
        }
        $query->record->name = trim((string) ($q['name'] ?? ''));
        $desc = (string) ($q['description'] ?? '');
        $query->record->description = '' === $desc ? null : $desc;
        $user = $this->user();
        if ($user->admin || $this->auth->allowedTo('manage_public_queries', $query->record->project)) {
            $visibility = (int) ($q['visibility'] ?? QueryRecord::VISIBILITY_PRIVATE);
            $query->record->visibility = \in_array($visibility, [0, 1, 2], true) ? $visibility : QueryRecord::VISIBILITY_PRIVATE;
            $query->record->roles->clear();
            $roleIds = array_filter(array_map('intval', (array) ($q['role_ids'] ?? [])));
            if ([] !== $roleIds) {
                foreach ($this->em->getRepository(Role::class)->findBy(['id' => $roleIds]) as $role) {
                    $query->record->roles->add($role);
                }
            }
        } else {
            $query->record->visibility = QueryRecord::VISIBILITY_PRIVATE;
        }
        if (QueryRecord::VISIBILITY_ROLES !== (int) $query->record->visibility) {
            $query->record->roles->clear();
        }
    }

    private function redirectToItems(Request $request, AbstractQuery $query, ?Project $project, array $options): Response
    {
        $params = $this->params($request);
        if ($query instanceof IssueQuery) {
            if (!empty($params['gantt'])) {
                return $this->redirectToRoute(null !== $project ? 'project_gantt' : 'gantt', (null !== $project ? ['project_id' => $project->identifier] : []) + $options);
            }
            if (!empty($params['calendar'])) {
                return $this->redirectToRoute(null !== $project ? 'project_calendar' : 'calendar', (null !== $project ? ['project_id' => $project->identifier] : []) + $options);
            }

            return $this->redirectToRoute(null !== $project ? 'project_issues' : 'issues_index', (null !== $project ? ['project_id' => $project->identifier] : []) + $options);
        }
        $routes = ['TimeEntryQuery' => ['project_time_entries', 'time_entries_index'], 'ProjectQuery' => [null, 'projects_index'], 'UserQuery' => [null, 'users_index'], 'ProjectAdminQuery' => [null, 'admin_projects']];
        [$projectRoute, $globalRoute] = $routes[$query->type()] ?? [null, 'home'];
        if (null !== $project && null !== $projectRoute) {
            return $this->redirectToRoute($projectRoute, ['project_id' => $project->identifier] + $options);
        }

        return $this->redirectToRoute($globalRoute, $options);
    }

    private function renderForm(Request $request, AbstractQuery $query, ?Project $project, bool $isNew): Response
    {
        $params = $this->params($request);
        $user = $this->user();
        $roles = $this->em->createQuery('SELECT r FROM App\Entity\Role r WHERE r.builtin = 0 ORDER BY r.position')->getResult();
        $menu = match ($query->type()) {
            'TimeEntryQuery' => 'time_entries',
            'ProjectQuery' => 'projects',
            default => 'issues',
        };

        return $this->render($isNew ? 'queries/new.html.twig' : 'queries/edit.html.twig', [
            'query' => $query,
            'project' => $project,
            'roles' => $roles,
            'can_manage_public' => $user->admin || $this->auth->allowedTo('manage_public_queries', $query->record->project),
            'gantt' => !empty($params['gantt']),
            'calendar' => !empty($params['calendar']),
            'main_menu_selected' => $menu,
            'body_css' => 'controller-queries action-'.($isNew ? 'new' : 'edit'),
            'page_title' => $this->l($isNew ? 'label_query_new' : 'label_query'),
        ]);
    }
}

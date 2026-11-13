<?php

declare(strict_types=1);

namespace App\Query;

use App\Entity\IssueQuery as IssueQueryRecord;
use App\Entity\Project;
use App\Entity\Query as QueryRecord;
use App\Entity\Role;
use App\Entity\User;
use App\Security\ApiRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Creates query engines and retrieves the current query of a list page
 * (port of QueriesHelper#retrieve_query, IssuesController#retrieve_default_query, Query.visible).
 *
 * Other query types (TimeEntryQuery, ProjectQuery, UserQuery) can be added with register():
 *   $factory->register('TimeEntryQuery', TimeEntryQueryRecord::class, TimeEntryQuery::class);
 */
final class QueryFactory
{
    /** @var array<string, array{0: class-string<QueryRecord>, 1: class-string<AbstractQuery>}> */
    private array $types = [
        'IssueQuery' => [IssueQueryRecord::class, IssueQuery::class],
    ];

    public function __construct(public readonly QueryContext $ctx)
    {
        // engines named App\Query\<Type> (e.g. App\Query\TimeEntryQuery) are registered automatically
        foreach (['TimeEntryQuery', 'ProjectQuery', 'UserQuery', 'ProjectAdminQuery'] as $type) {
            $engine = __NAMESPACE__.'\\'.$type;
            $record = 'App\\Entity\\'.$type;
            if (class_exists($engine) && is_subclass_of($engine, AbstractQuery::class) && class_exists($record)) {
                $this->types[$type] = [$record, $engine];
            }
        }
    }

    /**
     * @param class-string<QueryRecord>   $recordClass
     * @param class-string<AbstractQuery> $engineClass
     */
    public function register(string $type, string $recordClass, string $engineClass): void
    {
        $this->types[$type] = [$recordClass, $engineClass];
    }

    public function hasType(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /** @return class-string<AbstractQuery> */
    public function engineClass(string $type): string
    {
        return $this->types[$type][1] ?? throw new NotFoundHttpException('Unknown query type '.$type);
    }

    /** A new, unsaved query (Query.new) with the type defaults */
    public function create(string $type = 'IssueQuery', ?Project $project = null, string $name = '_'): AbstractQuery
    {
        [$recordClass, $engineClass] = $this->types[$type] ?? throw new NotFoundHttpException('Unknown query type '.$type);
        $record = new $recordClass();
        $record->name = $name;
        $record->project = $project;
        $record->userId = (int) ($this->ctx->user()->id ?? 0);
        $record->visibility = QueryRecord::VISIBILITY_PRIVATE;
        if (IssueQueryRecord::class === $recordClass || is_subclass_of($recordClass, IssueQueryRecord::class)) {
            $record->filters = ['status_id' => ['operator' => 'o', 'values' => ['']]];
        }

        return new $engineClass($this->ctx, $record, $project);
    }

    public function issueQuery(?Project $project = null): IssueQuery
    {
        /** @var IssueQuery */
        return $this->create('IssueQuery', $project);
    }

    /** Wraps a saved query record; $project is the project the query is used in */
    public function wrap(QueryRecord $record, ?Project $project = null): AbstractQuery
    {
        $type = $record::typeName();
        $engineClass = $this->engineClass($type);

        return new $engineClass($this->ctx, $record, $project);
    }

    public function find(int $id): ?QueryRecord
    {
        return $this->ctx->em->find(QueryRecord::class, $id);
    }

    /**
     * Port of retrieve_query (+ IssuesController#retrieve_default_query when $applyDefault).
     * Uses the session key "<type>" (e.g. issue_query) like Redmine.
     */
    public function retrieve(Request $request, string $type = 'IssueQuery', ?Project $project = null, bool $useSession = true, bool $applyDefault = false, array $defaults = []): AbstractQuery
    {
        $params = $request->query->all();
        $isApi = ApiRequestMatcher::isApiRequest($request);
        $sessionKey = self::sessionKey($type);
        $session = $request->hasSession() ? $request->getSession() : null;
        $useSession = $useSession && null !== $session;
        $stored = $useSession ? $session->get($sessionKey) : null;
        $projectId = $project?->id;

        if ($applyDefault && empty($params['query_id']) && !$isApi && !isset($params['set_filter'])) {
            if (!empty($params['without_default'])) {
                $params['set_filter'] = 1;
            } else {
                $skip = false;
                if ($useSession && \is_array($stored) && !empty($stored['id']) && ($stored['project_id'] ?? null) === $projectId && null !== $this->find((int) $stored['id'])) {
                    $skip = true;
                }
                if (!$skip && null !== ($default = $this->defaultIssueQuery($project))) {
                    $params['query_id'] = $default->id;
                }
            }
        }

        if (!empty($params['query_id'])) {
            $record = $this->find((int) $params['query_id']);
            if (null === $record || $record::typeName() !== $type || (null !== $record->project && $record->project->id !== $projectId)) {
                throw new NotFoundHttpException();
            }
            $query = $this->wrap($record, $project);
            if (!$query->isVisibleBy()) {
                throw new AccessDeniedHttpException();
            }
            if ($useSession) {
                $session->set($sessionKey, ['id' => $record->id, 'project_id' => $record->project?->id]);
            }
        } elseif ($isApi || isset($params['set_filter']) || !$useSession || !\is_array($stored) || ($stored['project_id'] ?? null) !== $projectId) {
            $query = $this->create($type, $project);
            $query->buildFromParams($params, $defaults);
            if ($useSession) {
                $session->set($sessionKey, [
                    'project_id' => $projectId,
                    'filters' => $query->record->filters,
                    'group_by' => $query->getGroupBy(),
                    'column_names' => $query->getColumnNames(),
                    'totalable_names' => $query->totalableNames(),
                    'sort' => $query->sortCriteria()->toArray(),
                ]);
            }
        } else {
            $query = null;
            if (!empty($stored['id']) && null !== ($record = $this->find((int) $stored['id']))) {
                $query = $this->wrap($record, $project);
            }
            if (null === $query) {
                $query = $this->create($type, $project);
                $query->record->filters = $stored['filters'] ?? null;
                $query->setGroupBy($stored['group_by'] ?? null);
                $query->record->columnNames = $stored['column_names'] ?? null;
                $query->setTotalableNames($stored['totalable_names'] ?? null);
                $query->record->sortCriteria = $stored['sort'] ?? null;
            }
        }
        if (isset($params['sort']) && '' !== $params['sort'] && \is_string($params['sort'])) {
            $query->setSortCriteria($params['sort']);
            if ($useSession) {
                $s = $session->get($sessionKey, []);
                $s['sort'] = $query->sortCriteria()->toArray();
                $session->set($sessionKey, $s);
            }
        }

        return $query;
    }

    public static function sessionKey(string $type): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $type));
    }

    /** IssueQuery.default(project:, user:) */
    public function defaultIssueQuery(?Project $project, ?User $user = null): ?QueryRecord
    {
        $user ??= $this->ctx->user();
        if ($user->isLogged() && '' !== (string) ($qid = (string) $user->getPref()->get('default_issue_query', ''))) {
            $q = $this->find((int) $qid);
            if ($q instanceof IssueQueryRecord && $this->wrap($q, $project)->isVisibleBy($user)) {
                return $q;
            }
        }
        if (null !== $project && $project->defaultIssueQueryId) {
            $q = $this->find($project->defaultIssueQueryId);
            if ($q instanceof IssueQueryRecord && QueryRecord::VISIBILITY_PUBLIC === (int) $q->visibility) {
                return $q;
            }
        }
        if ('' !== ($qid = $this->ctx->settings->string('default_issue_query'))) {
            $q = $this->find((int) $qid);
            if ($q instanceof IssueQueryRecord && QueryRecord::VISIBILITY_PUBLIC === (int) $q->visibility) {
                return $q;
            }
        }

        return null;
    }

    /**
     * Query.visible(user).global_or_on_project(project).sorted
     *
     * @return list<QueryRecord>
     */
    public function visibleQueries(string $type = 'IssueQuery', ?Project $project = null, ?User $user = null, bool $globalOrOnProject = true): array
    {
        [$recordClass, $engineClass] = $this->types[$type] ?? throw new NotFoundHttpException();
        $user ??= $this->ctx->user();
        $qb = $this->ctx->em->createQueryBuilder()->select('q')->from($recordClass, 'q')->leftJoin('q.project', 'p')->orderBy('q.name')->addOrderBy('q.id');
        if ($globalOrOnProject) {
            if (null === $project) {
                $qb->andWhere('q.project IS NULL');
            } else {
                $qb->andWhere('q.project IS NULL OR q.project = :project')->setParameter('project', $project->id);
            }
        }
        $records = $qb->getQuery()->getResult();

        return array_values(array_filter($records, fn (QueryRecord $r) => $this->isVisibleInScope($r, $user, $engineClass)));
    }

    /** Query.visible scope semantics (base allowed_to_condition on the query project + visibility) */
    private function isVisibleInScope(QueryRecord $r, User $user, string $engineClass): bool
    {
        // engines with their own Query.visible scope (e.g. ProjectAdminQuery) define recordVisibleInScope()
        if (method_exists($engineClass, 'recordVisibleInScope')) {
            return $engineClass::recordVisibleInScope($r, $user);
        }
        if (null !== $r->project) {
            $perm = $engineClass::viewPermission();
            if (!$this->ctx->auth->allowedTo($perm, $r->project, $user)) {
                return false;
            }
        }
        if ($user->admin) {
            return QueryRecord::VISIBILITY_PRIVATE !== (int) $r->visibility || $r->userId === $user->id;
        }
        if (QueryRecord::VISIBILITY_PUBLIC === (int) $r->visibility || ($user->isLogged() && $r->userId === $user->id)) {
            return true;
        }
        if (QueryRecord::VISIBILITY_ROLES === (int) $r->visibility && $user->isLogged()) {
            $roleIds = array_map(static fn (Role $role) => $role->id, $r->roles->toArray());
            foreach ($this->ctx->auth->membershipsOf($user) as $projectId => $m) {
                if ($m->project->isArchived() || (null !== $r->project && $r->project->id !== $projectId)) {
                    continue;
                }
                foreach ($m->getRoles() as $role) {
                    if (\in_array($role->id, $roleIds, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}

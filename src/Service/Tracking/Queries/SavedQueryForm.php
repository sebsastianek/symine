<?php

declare(strict_types=1);

namespace App\Service\Tracking\Queries;

use App\Entity\Project;
use App\Entity\Query as QueryRecord;
use App\Entity\Role;
use App\Query\AbstractQuery;
use App\Query\QueryFactory;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The form of a saved query: its name, description, visibility and project, with the filters, columns and
 * sort order of the query.
 *
 * Fields: type, query[name], query[description], query[visibility], query[role_ids][], query[sort_criteria],
 * query_is_for_all, default_columns, and the parameters of the query itself (f[], op[], v[], c[], group_by...).
 */
final class SavedQueryForm
{
    public function __construct(
        private readonly QueryFactory $queries,
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly AccessGuard $guard,
        private readonly CurrentUser $currentUser,
    ) {
    }

    /**
     * A new query of the requested type, for a user who may save queries (in the project).
     *
     * @throws NotFoundHttpException when the type is unknown
     */
    public function blank(Request $request, ?Project $project): AbstractQuery
    {
        $type = (string) ($request->request->get('type') ?? $request->query->get('type') ?? 'IssueQuery');
        if ('' === $type) {
            $type = 'IssueQuery';
        }
        if (!$this->queries->hasType($type)) {
            throw new NotFoundHttpException();
        }
        if (!$this->auth->allowedTo('save_queries', $project, null, true)) {
            $this->guard->deny();
        }

        return $this->queries->create($type, $project, '');
    }

    /** Gives the query the filters, columns and options of the request: the list the user was looking at */
    public function prefill(AbstractQuery $query, Request $request): void
    {
        $query->buildFromParams(self::params($request));
    }

    /**
     * Assigns the submitted form (QueriesController#update_query_from_params) and saves the query when it is valid.
     *
     * @param ?Project $project the project the form was opened in
     *
     * @return bool false when the query is not valid: its errors are those of $query
     */
    public function submit(AbstractQuery $query, Request $request, ?Project $project): bool
    {
        $user = $this->currentUser->get();
        $isNew = null === $query->record->id;
        if ($isNew) {
            $query->record->userId = (int) $user->id;
        }
        $params = self::params($request);
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

        if (!$query->isValid(true)) {
            return false;
        }
        if ($isNew) {
            $this->em->persist($query->record);
        }
        $this->em->flush();

        return true;
    }

    /**
     * @param ?Project $project the project the form was opened in
     *
     * @return array<string, mixed> what the form shows
     */
    public function view(AbstractQuery $query, ?Project $project, Request $request): array
    {
        $params = self::params($request);
        $user = $this->currentUser->get();
        $roles = $this->em->createQuery('SELECT r FROM App\Entity\Role r WHERE r.builtin = 0 ORDER BY r.position')->getResult();
        $menu = match ($query->type()) {
            'TimeEntryQuery' => 'time_entries',
            'ProjectQuery' => 'projects',
            default => 'issues',
        };

        return [
            'query' => $query,
            'project' => $project,
            'roles' => $roles,
            'can_manage_public' => $user->admin || $this->auth->allowedTo('manage_public_queries', $query->record->project),
            'gantt' => !empty($params['gantt']),
            'calendar' => !empty($params['calendar']),
            'main_menu_selected' => $menu,
        ];
    }

    /** All request params (query string + body), like Rails params */
    private static function params(Request $request): array
    {
        return array_replace($request->query->all(), $request->request->all());
    }
}

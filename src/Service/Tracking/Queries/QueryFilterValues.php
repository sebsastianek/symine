<?php

declare(strict_types=1);

namespace App\Service\Tracking\Queries;

use App\Query\QueryFactory;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Service\ProjectService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The values a filter offers, loaded when the filter is added to the form (QueriesController#filter): filters
 * with many values (users, versions...) do not come with the page.
 */
final class QueryFilterValues
{
    public function __construct(
        private readonly QueryFactory $queries,
        private readonly ProjectService $projects,
        private readonly Authorization $auth,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * Parameters: type, project_id, name (of the filter).
     *
     * @return array<mixed> [] for an unknown filter
     *
     * @throws NotFoundHttpException when the project does not exist
     */
    public function of(Request $request): array
    {
        $type = (string) $request->query->get('type', 'IssueQuery');
        $project = null;
        if ('' !== (string) $request->query->get('project_id', '')) {
            $project = $this->projects->findByIdentifierOrId((string) $request->query->get('project_id'));
            if (null === $project) {
                throw new NotFoundHttpException();
            }
        }
        $query = $this->queries->create($type, $project);
        if (!$this->auth->allowedTo($query::viewPermission(), $project, null, true)) {
            $this->guard->deny();
        }
        $filter = $query->availableFilters()[(string) $request->query->get('name')] ?? null;

        return null !== $filter ? ($filter->values() ?? []) : [];
    }
}

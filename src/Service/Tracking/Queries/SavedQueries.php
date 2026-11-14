<?php

declare(strict_types=1);

namespace App\Service\Tracking\Queries;

use App\Entity\Query as QueryRecord;
use App\Query\AbstractQuery;
use App\Query\QueryFactory;
use App\Security\AccessGuard;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The saved queries (custom queries): finding the one a user edits, listing those a user sees, deleting one.
 */
final class SavedQueries
{
    public function __construct(
        private readonly QueryFactory $queries,
        private readonly EntityManagerInterface $em,
        private readonly AccessGuard $guard,
        private readonly CurrentUser $currentUser,
    ) {
    }

    /**
     * QueriesController#find_query: the saved query, which the user must be allowed to edit. It is used in its
     * own project ($query->project).
     *
     * @throws NotFoundHttpException when there is no such query
     */
    public function find(int $id): AbstractQuery
    {
        $record = $this->queries->find($id);
        if (null === $record || !$this->queries->hasType($record::typeName())) {
            throw new NotFoundHttpException();
        }
        $query = $this->queries->wrap($record, $record->project);
        if (!$query->isEditableBy($this->currentUser->get())) {
            $this->guard->deny();
        }

        return $query;
    }

    /**
     * The page of the queries of the requested type that the user sees, sorted by name (queries#index of the API).
     *
     * @return array{queries: list<QueryRecord>, total_count: int, offset: int, limit: int}
     *
     * @throws NotFoundHttpException when the type is unknown
     */
    public function page(Request $request): array
    {
        $type = (string) $request->query->get('type', 'IssueQuery');
        if (!$this->queries->hasType($type)) {
            throw new NotFoundHttpException();
        }
        $limit = max(1, min(100, (int) ($request->query->get('limit') ?: 25)));
        $offset = max(0, (int) $request->query->get('offset', 0));
        if ($request->query->has('page') && !$request->query->has('offset')) {
            $offset = max(0, ((int) $request->query->get('page') - 1) * $limit);
        }
        $all = $this->queries->visibleQueries($type, null, null, false);
        usort($all, static fn (QueryRecord $a, QueryRecord $b) => strcmp($a->name, $b->name));

        return ['queries' => \array_slice($all, $offset, $limit), 'total_count' => \count($all), 'offset' => $offset, 'limit' => $limit];
    }

    public function delete(AbstractQuery $query): void
    {
        // Project#default_issue_query is nullified like Redmine (dependent: :nullify)
        $this->em->getConnection()->executeStatement('UPDATE projects SET default_issue_query_id = NULL WHERE default_issue_query_id = ?', [$query->record->id]);
        $this->em->remove($query->record);
        $this->em->flush();
    }
}

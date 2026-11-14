<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Query\ProjectQuery;
use App\Query\QueryError;
use App\Query\QueryFactory;
use App\Security\CurrentUser;
use App\Service\Activity\ProjectLastActivity;
use App\Service\ProjectQueryService;
use App\Twig\ProjectQueryExtension;
use Symfony\Component\HttpFoundation\Request;

/**
 * format.csv of ProjectsController#index: retrieve_default_query + retrieve_query(ProjectQuery) + query_to_csv.
 */
final class ProjectCsvExport
{
    public function __construct(
        private readonly ProjectQueryService $queries,
        private readonly QueryFactory $queryFactory,
        private readonly ProjectLastActivity $lastActivity,
        private readonly ProjectQueryExtension $helper,
        private readonly CurrentUser $currentUser,
    ) {
    }

    /** The query of the request, the default projects query when the request does not describe one */
    public function query(Request $request): ProjectQuery
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
        \assert($query instanceof ProjectQuery);

        return $query;
    }

    /**
     * The header and one line per project, with the columns of the query.
     *
     * @return list<list<?string>>
     */
    public function rows(ProjectQuery $query): array
    {
        $entries = $query->results();
        if ($query->hasColumn('last_activity_date') && [] !== $entries) {
            $query->setLastActivityDates($this->lastActivity->lastActivityDates($this->currentUser->get(), array_map(static fn (Project $p) => (int) $p->id, $entries)));
        }
        $columns = $query->columns();
        $rows = [array_map(static fn ($c) => $c->caption, $columns)];
        foreach ($entries as $project) {
            $rows[] = array_map(fn ($c) => $this->helper->csvValue($query, $c, $project), $columns);
        }

        return $rows;
    }
}

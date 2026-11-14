<?php

declare(strict_types=1);

namespace App\Service\Timelog;

use App\Entity\Project;
use App\EventSubscriber\TimeEntryQueryRegistrationSubscriber;
use App\I18n\Localizer;
use App\Query\QueryContext;
use App\Query\QueryError;
use App\Query\QueryFactory;
use App\Query\StatementInvalid;
use App\Query\TimeEntry\TimeEntryQuery;
use App\Security\Authorization;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * The query behind the spent time list and report: retrieved from the request (retrieve_time_entry_query),
 * with the rule deciding whether its result may be exported and what the pages built on it have in common.
 */
final class TimelogQueries
{
    public function __construct(
        private readonly QueryFactory $queries,
        private readonly QueryContext $queryContext,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly EntityManagerInterface $em,
        private readonly RouterInterface $router,
        private readonly Localizer $i18n,
    ) {
        TimeEntryQueryRegistrationSubscriber::register($queries);
    }

    public function retrieve(Request $request, ?Project $project): TimeEntryQuery
    {
        try {
            $query = $this->queries->retrieve($request, 'TimeEntryQuery', $project, false);
        } catch (QueryError) {
            $query = $this->queries->create('TimeEntryQuery', $project);
        }
        \assert($query instanceof TimeEntryQuery);

        return $query;
    }

    /**
     * Number of entries of the query (none when its filters are invalid)
     *
     * @throws StatementInvalid
     */
    public function count(TimeEntryQuery $query): int
    {
        return $query->isValid() ? $query->entryCount() : 0;
    }

    public function exportAllowed(TimeEntryQuery $query, ?Project $project): bool
    {
        $user = $this->currentUser->get();
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

    /**
     * What the list and the report show around their results: the project, the query and its form
     * (queries/_query_form), the page title and the routes of the tabs.
     *
     * @return array<string, mixed>
     */
    public function view(TimeEntryQuery $query, ?Project $project): array
    {
        $listRoute = $project ? 'project_time_entries' : 'time_entries_index';
        $base = $project ? ['project_id' => $project->identifier] : [];
        $newQueryRoute = $project ? 'project_query_new' : 'query_new';

        return [
            'project' => $project,
            'query' => $query,
            'export_allowed' => $this->exportAllowed($query, $project),
            'main_menu_selected' => 'time_entries',
            'page_title' => $query->isNew() ? $this->i18n->l('label_spent_time') : $query->name(),
            'list_route' => $listRoute,
            'route_params' => $base,
            'clear_url' => $this->router->generate($listRoute, $base + ['set_filter' => 1, 'sort' => '']),
            'new_query_url' => null !== $this->router->getRouteCollection()->get($newQueryRoute) ? $this->router->generate($newQueryRoute, $base) : '#',
            'can_save_queries' => $this->auth->allowedTo('save_queries', $project, null, true),
            'extra_query_params' => [],
        ];
    }
}

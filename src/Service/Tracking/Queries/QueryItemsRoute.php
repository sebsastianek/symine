<?php

declare(strict_types=1);

namespace App\Service\Tracking\Queries;

use App\Entity\Project;
use App\Query\AbstractQuery;
use App\Query\IssueQuery;
use Symfony\Component\HttpFoundation\Request;

/**
 * Where a saved query is used (QueriesController#redirect_to_items): the list its type filters, which for
 * issues can also be the Gantt chart or the calendar the form was opened from.
 */
final class QueryItemsRoute
{
    /**
     * @param array<string, mixed> $options parameters added to the URL (query_id, set_filter)
     *
     * @return array{0: string, 1: array<string, mixed>} route name and parameters
     */
    public function of(AbstractQuery $query, ?Project $project, Request $request, array $options): array
    {
        $params = array_replace($request->query->all(), $request->request->all());
        if ($query instanceof IssueQuery) {
            if (!empty($params['gantt'])) {
                return [null !== $project ? 'project_gantt' : 'gantt', (null !== $project ? ['project_id' => $project->identifier] : []) + $options];
            }
            if (!empty($params['calendar'])) {
                return [null !== $project ? 'project_calendar' : 'calendar', (null !== $project ? ['project_id' => $project->identifier] : []) + $options];
            }

            return [null !== $project ? 'project_issues' : 'issues_index', (null !== $project ? ['project_id' => $project->identifier] : []) + $options];
        }
        $routes = ['TimeEntryQuery' => ['project_time_entries', 'time_entries_index'], 'ProjectQuery' => [null, 'projects_index'], 'UserQuery' => [null, 'users_index'], 'ProjectAdminQuery' => [null, 'admin_projects']];
        [$projectRoute, $globalRoute] = $routes[$query->type()] ?? [null, 'home'];
        if (null !== $project && null !== $projectRoute) {
            return [$projectRoute, ['project_id' => $project->identifier] + $options];
        }

        return [$globalRoute, $options];
    }
}

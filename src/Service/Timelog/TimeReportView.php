<?php

declare(strict_types=1);

namespace App\Service\Timelog;

use App\Entity\Project;
use App\Query\QueryContext;
use App\Query\TimeEntry\TimeEntryQuery;
use App\Query\TimeEntry\TimeReport;
use Symfony\Component\HttpFoundation\Request;

/**
 * The spent time report (timelog/report): built from the criteria and the period asked in the request, and
 * what its page shows.
 */
final class TimeReportView
{
    public function __construct(
        private readonly TimelogQueries $queries,
        private readonly QueryContext $queryContext,
    ) {
    }

    /** The report of params[:criteria] (blank ones ignored) by params[:columns] */
    public function report(Request $request, TimeEntryQuery $query, ?Project $project): TimeReport
    {
        $criteria = $request->query->all()['criteria'] ?? [];

        return new TimeReport($this->queryContext, $query, $project, array_values(array_filter((array) $criteria, static fn ($c) => '' !== (string) $c)), $request->query->get('columns') ? (string) $request->query->get('columns') : null);
    }

    /** @return array<string, mixed> */
    public function view(TimeReport $report, TimeEntryQuery $query, ?Project $project): array
    {
        return [
            'report' => $report,
            'rows' => $report->criteria ? $report->rows() : [],
            'body_css' => 'controller-timelog action-report',
        ] + $this->queries->view($query, $project);
    }
}

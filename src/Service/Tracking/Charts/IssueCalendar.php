<?php

declare(strict_types=1);

namespace App\Service\Tracking\Charts;

use App\Entity\Project;
use App\Helper\Calendar;
use App\I18n\Localizer;
use App\Query\IssueQuery;
use App\Query\QueryFactory;
use App\Security\Authorization;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The calendar of a month (CalendarsController#show): the issues of a query that start or are due in the weeks
 * shown, and the versions due then.
 */
final class IssueCalendar
{
    public function __construct(
        private readonly QueryFactory $queries,
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * Parameters: year, month (the current month by default), and those of the issue query.
     *
     * @return array{project: ?Project, query: IssueQuery, calendar: Calendar, year: int, month: int, can_save_queries: bool}
     */
    public function view(Request $request, ?Project $project): array
    {
        $today = $this->i18n->today();
        $year = (int) $request->query->get('year', 0);
        $month = null;
        if ($year > 1900) {
            $m = (int) $request->query->get('month', 0);
            if ($m > 0 && $m < 13) {
                $month = $m;
            }
        } else {
            $year = (int) $today->format('Y');
        }
        $month ??= (int) $today->format('n');

        $calendar = new Calendar(
            new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month)),
            Calendar::firstWdayFrom($this->settings->string('start_of_week'), $this->i18n->l('general_first_day_of_week')),
            array_map('intval', $this->settings->array('non_working_week_days')),
            $today,
        );
        /** @var IssueQuery $query */
        $query = $this->queries->retrieve($request, 'IssueQuery', $project);
        $query->setGroupBy(null);
        $query->record->sortCriteria = null;
        if ($query->isValid()) {
            $from = $this->em->getConnection()->quote($calendar->startdt->format('Y-m-d'));
            $to = $this->em->getConnection()->quote($calendar->enddt->format('Y-m-d'));
            $events = $query->issues(null, null, "((issues.start_date BETWEEN $from AND $to) OR (issues.due_date BETWEEN $from AND $to))");
            $events = array_merge($events, $query->versions("versions.effective_date BETWEEN $from AND $to"));
            $calendar->setEvents($events);
        }

        return [
            'project' => $project,
            'query' => $query,
            'calendar' => $calendar,
            'year' => $year,
            'month' => $month,
            'can_save_queries' => $this->auth->allowedTo('save_queries', $project, null, true),
        ];
    }
}

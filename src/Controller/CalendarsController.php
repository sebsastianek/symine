<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Helper\Calendar;
use App\Query\IssueQuery;
use App\Query\QueryFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of CalendarsController#show (issues and versions of a month). */
final class CalendarsController extends AbstractController
{
    public function __construct(private readonly QueryFactory $queries)
    {
    }

    #[Route('/issues/calendar', name: 'calendar', methods: ['GET'], priority: 10)]
    public function show(Request $request): Response
    {
        $projectId = $request->query->get('project_id');

        return $this->doShow($request, \is_string($projectId) && '' !== $projectId ? $this->findProject($projectId) : null);
    }

    #[Route('/projects/{project_id}/issues/calendar', name: 'project_calendar', methods: ['GET'], priority: 10)]
    public function projectShow(Request $request, string $project_id): Response
    {
        return $this->doShow($request, $this->findProject($project_id));
    }

    private function doShow(Request $request, ?Project $project): Response
    {
        $this->authorize('view_calendar', $project, null === $project);
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
            Calendar::firstWdayFrom($this->settings->string('start_of_week'), $this->l('general_first_day_of_week')),
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
        $vars = [
            'project' => $project,
            'query' => $query,
            'calendar' => $calendar,
            'year' => $year,
            'month' => $month,
            'main_menu_selected' => 'calendar',
            'body_css' => 'controller-calendars action-show',
            'page_title' => $this->l('label_calendar'),
            'can_save_queries' => $this->auth->allowedTo('save_queries', $project, null, true),
        ];

        return $this->render($request->isXmlHttpRequest() ? 'calendars/_show_content.html.twig' : 'calendars/show.html.twig', $vars);
    }
}

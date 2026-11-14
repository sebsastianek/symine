<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Tracking\Charts\IssueCalendar;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of CalendarsController#show (issues and versions of a month). */
final class CalendarsController extends AbstractController
{
    public function __construct(private readonly IssueCalendar $calendar)
    {
    }

    #[Route('/issues/calendar', name: 'calendar', methods: ['GET'], priority: 10)]
    #[Route('/projects/{project_id}/issues/calendar', name: 'project_calendar', methods: ['GET'], priority: 10)]
    public function show(Request $request, ?string $project_id = null): Response
    {
        // find_optional_project: /issues/calendar?project_id=x is the project's calendar
        $projectId = $project_id ?? $request->query->get('project_id');
        $project = \is_string($projectId) && '' !== $projectId ? $this->findProject($projectId) : null;
        $this->authorize('view_calendar', $project, null === $project);

        return $this->render($request->isXmlHttpRequest() ? 'calendars/_show_content.html.twig' : 'calendars/show.html.twig', $this->calendar->view($request, $project) + [
            'main_menu_selected' => 'calendar',
            'body_css' => 'controller-calendars action-show',
            'page_title' => $this->l('label_calendar'),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Activity\ActivityFeed;
use App\Service\Activity\ActivityFilter;
use App\Service\Activity\ActivityView;
use App\Service\AtomFeed;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Port of Redmine's ActivitiesController. The two actions are activities#index without and within a project
 * (find_optional_project): the same page and feed, of everything or of the project.
 */
final class ActivitiesController extends AbstractController
{
    public function __construct(
        private readonly ActivityFilter $filter,
        private readonly ActivityView $view,
        private readonly ActivityFeed $feed,
        private readonly AtomFeed $atom,
    ) {
    }

    #[Route('/activity.{_format}', name: 'activity', defaults: ['_format' => 'html'], requirements: ['_format' => 'html|atom'], methods: ['GET'])]
    public function index(Request $request): Response
    {
        // /search?id=... style optional project parameter (find_optional_project_by_id)
        $id = $request->query->get('id');
        $project = null !== $id && '' !== $id ? $this->findProject((string) $id) : null;
        $this->authorize('view_project', $project, null === $project);
        $criteria = $this->filter->submit($request, $project);
        if ('atom' === $request->getRequestFormat()) {
            return $this->atom->render($request, $this->feed->title($criteria, $project), $this->feed->items($criteria, $project), $project);
        }

        return $this->render('activities/index.html.twig', $this->view->view($criteria, $request, $project) + [
            'main_menu_selected' => 'activity',
            'body_css' => 'controller-activities action-index',
        ]);
    }

    #[Route('/projects/{id}/activity.{_format}', name: 'project_activity', defaults: ['_format' => 'html'], requirements: ['_format' => 'html|atom'], methods: ['GET'])]
    public function project(Request $request, string $id): Response
    {
        $project = $this->findProject($id);
        $this->authorize('view_project', $project);
        $criteria = $this->filter->submit($request, $project);
        if ('atom' === $request->getRequestFormat()) {
            return $this->atom->render($request, $this->feed->title($criteria, $project), $this->feed->items($criteria, $project), $project);
        }

        return $this->render('activities/index.html.twig', $this->view->view($criteria, $request, $project) + [
            'main_menu_selected' => 'activity',
            'body_css' => 'controller-activities action-index',
        ]);
    }
}

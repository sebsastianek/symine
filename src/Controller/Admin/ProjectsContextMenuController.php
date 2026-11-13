<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Controller\ContextMenusController;
use App\Entity\Project;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Port of ContextMenus::ProjectsController (context menu of the administration projects list). */
final class ProjectsContextMenuController extends AbstractController
{
    #[Route('/admin/projects_context_menu', name: 'projects_context_menu', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin();
        $params = array_replace($request->query->all(), $request->request->all());
        $ids = array_values(array_unique(array_filter(array_map('intval', \is_array($params['ids'] ?? null) ? $params['ids'] : (isset($params['ids']) ? [$params['ids']] : [])))));
        // Project.where(id: params[:ids])
        $projects = [] === $ids ? [] : $this->em->createQuery('SELECT p FROM App\Entity\Project p WHERE p.id IN (:ids) ORDER BY p.id')->setParameter('ids', $ids)->getResult();
        if ([] === $projects) {
            throw $this->createNotFoundException();
        }
        $project = 1 === \count($projects) ? $projects[0] : null;
        $back = $this->backUrl($request, $params);

        $vars = [
            'projects' => $projects,
            'project' => $project,
            'back' => $back,
            'scheduled_for_deletion' => null !== $project && Project::STATUS_SCHEDULED_FOR_DELETION === $project->status,
        ];
        if (null === $project || $vars['scheduled_for_deletion']) {
            // url_for(controller: 'projects', action: 'bulk_destroy', ids:, back_url:) is a full URL
            $query = ContextMenusController::toQuery(array_filter(['back_url' => $back, 'ids' => array_map(static fn (Project $p) => $p->id, $projects)], static fn ($v) => null !== $v));
            $vars['bulk_destroy_url'] = $this->generateUrl('projects_bulk_destroy', [], UrlGeneratorInterface::ABSOLUTE_URL).('' !== $query ? '?'.$query : '');
        }

        return $this->render('context_menus/projects.html.twig', $vars);
    }

    /** ApplicationController#back_url */
    private function backUrl(Request $request, array $params): ?string
    {
        $url = isset($params['back_url']) && \is_string($params['back_url']) ? $params['back_url'] : null;
        if (null === $url && ($referer = $request->headers->get('referer'))) {
            $url = (string) preg_replace('/(\?|&)utf8=\x{2713}&?/u', '$1', rawurldecode($referer));
        }

        return $url;
    }
}

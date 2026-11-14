<?php

declare(strict_types=1);

namespace App\Controller;

use App\Pdf\PdfExport;
use App\Service\Tracking\Charts\GanttChart;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of GantsController#show (HTML, PDF and PNG exports). */
final class GanttsController extends AbstractController
{
    public function __construct(
        private readonly GanttChart $chart,
        private readonly PdfExport $pdfExport,
    ) {
    }

    #[Route('/issues/gantt.{_format}', name: 'gantt_export', requirements: ['_format' => 'pdf|png'], methods: ['GET'], priority: 10)]
    #[Route('/issues/gantt', name: 'gantt', methods: ['GET'], priority: 10)]
    #[Route('/projects/{project_id}/issues/gantt.{_format}', name: 'project_gantt_export', requirements: ['_format' => 'pdf|png'], methods: ['GET'], priority: 10)]
    #[Route('/projects/{project_id}/issues/gantt', name: 'project_gantt', methods: ['GET'], priority: 10)]
    public function show(Request $request, ?string $project_id = null, string $_format = 'html'): Response
    {
        // find_optional_project: /issues/gantt?project_id=x is the project's Gantt chart
        $projectId = $project_id ?? $request->query->all()['project_id'] ?? null;
        $project = \is_scalar($projectId) && '' !== (string) $projectId ? $this->findProject((string) $projectId) : null;
        $this->authorize('view_gantt', $project, null === $project);
        $view = $this->chart->view($request, $project);
        if (\in_array($_format, ['pdf', 'png'], true)) {
            if (!$view['export_allowed']) {
                $this->deny();
            }

            return $this->pdfExport->gantt($view['gantt'], $project, $_format);
        }

        return $this->render($request->isXmlHttpRequest() ? 'gantts/_content.html.twig' : 'gantts/show.html.twig', $view + [
            'main_menu_selected' => 'gantt',
            'body_css' => 'controller-gantts action-show',
            'page_title' => $this->l('label_gantt'),
        ]);
    }
}

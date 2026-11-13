<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Helper\Gantt;
use App\Query\IssueQuery;
use App\Query\QueryContext;
use App\Query\QueryFactory;
use App\Service\VersionService;
use App\Twig\AppExtension;
use App\Twig\QueryExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of GantsController#show (HTML, PDF and PNG exports). */
final class GanttsController extends AbstractController
{
    public function __construct(
        private readonly QueryFactory $queries,
        private readonly QueryContext $ctx,
        private readonly AppExtension $app,
        private readonly QueryExtension $qx,
        private readonly VersionService $versions,
        private readonly \App\Pdf\PdfExport $pdfExport,
    ) {
    }

    #[Route('/issues/gantt.{_format}', name: 'gantt_export', requirements: ['_format' => 'pdf|png'], methods: ['GET'], priority: 10)]
    #[Route('/issues/gantt', name: 'gantt', methods: ['GET'], priority: 10)]
    public function show(Request $request, string $_format = 'html'): Response
    {
        return $this->doShow($request, null, $_format);
    }

    #[Route('/projects/{project_id}/issues/gantt.{_format}', name: 'project_gantt_export', requirements: ['_format' => 'pdf|png'], methods: ['GET'], priority: 10)]
    #[Route('/projects/{project_id}/issues/gantt', name: 'project_gantt', methods: ['GET'], priority: 10)]
    public function projectShow(Request $request, string $project_id, string $_format = 'html'): Response
    {
        return $this->doShow($request, $this->findProject($project_id), $_format);
    }

    private function doShow(Request $request, ?Project $project, string $format = 'html'): Response
    {
        $this->authorize('view_gantt', $project, null === $project);
        $user = $this->user();
        $pref = $user->isLogged() ? $user->getPref() : null;
        $prefs = null !== $pref ? ['gantt_zoom' => $pref->get('gantt_zoom'), 'gantt_months' => $pref->get('gantt_months')] : [];

        $gantt = new Gantt($request->query->all(), $prefs, $this->ctx, $this->app, $this->qx, $this->versions);
        $gantt->project = $project;
        // Save gantt parameters as user preference (zoom and months count)
        if (null !== $pref && ((int) $pref->get('gantt_zoom') !== $gantt->zoom || (int) $pref->get('gantt_months') !== $gantt->months)) {
            $pref->set('gantt_zoom', $gantt->zoom);
            $pref->set('gantt_months', $gantt->months);
            if (null === $pref->id) {
                $this->em->persist($pref);
            }
            $this->em->flush();
        }

        /** @var IssueQuery $query */
        $query = $this->queries->retrieve($request, 'IssueQuery', $project, true, false);
        $query->setGroupBy(null);
        $valid = $query->isValid();
        if ($valid) {
            $gantt->query = $query;
        }
        $exportAllowed = $this->pdfExport->exportAllowed($valid ? $query : null, $project);
        if (\in_array($format, ['pdf', 'png'], true)) {
            if (!$exportAllowed) {
                $this->deny();
            }

            return $this->pdfExport->gantt($gantt, $project, $format);
        }

        $vars = [
            'project' => $project,
            'query' => $query,
            'gantt' => $gantt,
            'query_valid' => $valid,
            'main_menu_selected' => 'gantt',
            'body_css' => 'controller-gantts action-show',
            'page_title' => $this->l('label_gantt'),
            'can_save_queries' => $this->auth->allowedTo('save_queries', $project, null, true),
            'gantt_months_limit' => $this->settings->int('gantt_months_limit'),
            'export_allowed' => $exportAllowed,
        ];
        if ($request->isXmlHttpRequest()) {
            return $this->render('gantts/_content.html.twig', $vars);
        }

        return $this->render('gantts/show.html.twig', $vars);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Tracking\Reports\IssueReport;
use App\Service\Tracking\Reports\IssueReportExport;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of ReportsController + ReportsHelper (issue summary report). */
final class ReportsController extends AbstractController
{
    public function __construct(
        private readonly IssueReport $report,
        private readonly IssueReportExport $export,
    ) {
    }

    #[Route('/projects/{id}/issues/report', name: 'issues_report', methods: ['GET'], priority: 10)]
    public function issueReport(string $id): Response
    {
        $project = $this->findProject($id);
        $this->authorize('view_issues', $project);

        return $this->render('reports/issue_report.html.twig', $this->report->summary($project) + [
            'project' => $project,
            'main_menu_selected' => 'issues',
            'body_css' => 'controller-reports action-issue_report',
            'page_title' => $this->l('label_report_plural'),
        ]);
    }

    #[Route('/projects/{id}/issues/report/{detail}.{_format}', name: 'issues_report_details', requirements: ['_format' => 'html|csv'], defaults: ['_format' => 'html'], methods: ['GET'], priority: 10)]
    public function issueReportDetails(Request $request, string $id, string $detail, string $_format = 'html'): Response
    {
        $project = $this->findProject($id);
        $this->authorize('view_issues', $project);
        if (!$this->report->hasDetail($detail)) {
            throw $this->createNotFoundException();
        }
        $exportAllowed = $this->export->isAllowed($project);
        if ('csv' === $_format && !$exportAllowed) {
            $this->deny();
        }
        $report = $this->report->details($project, $detail);
        if ('csv' === $_format) {
            return $this->export->csv($request, $report);
        }

        return $this->render('reports/issue_report_details.html.twig', $report + [
            'project' => $project,
            'export_allowed' => $exportAllowed,
            'main_menu_selected' => 'issues',
            'body_css' => 'controller-reports action-issue_report_details',
            'page_title' => $this->l('label_report_plural'),
        ]);
    }
}

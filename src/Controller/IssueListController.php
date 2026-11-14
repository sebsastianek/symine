<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\IssueApiPresenter;
use App\Pdf\PdfExport;
use App\Query\IssueQuery;
use App\Query\QueryFactory;
use App\Query\StatementInvalid;
use App\Service\AtomFeed;
use App\Service\Issue\IssueCsvExport;
use App\Service\Issue\IssueFeed;
use App\Service\Issue\IssueListView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Issue list (IssuesController#index): HTML list with filters/options, CSV export, Atom feed and
 * the issues REST API index.
 */
final class IssueListController extends AbstractController
{
    public function __construct(
        private readonly QueryFactory $queries,
        private readonly IssueListView $list,
        private readonly IssueCsvExport $csv,
        private readonly IssueFeed $issueFeed,
        private readonly ApiRenderer $api,
        private readonly IssueApiPresenter $presenter,
        private readonly AtomFeed $feed,
        private readonly PdfExport $pdfExport,
    ) {
    }

    #[Route('/issues.{_format}', name: 'issues_index', requirements: ['_format' => 'html|csv|atom|json|xml|pdf'], defaults: ['_format' => 'html'], methods: ['GET'], priority: 10)]
    public function index(Request $request, string $_format = 'html', ?string $project_id = null): Response
    {
        $project = $this->list->project($request, $project_id);
        $this->authorize('view_issues', $project, null === $project);
        /** @var IssueQuery $query */
        $query = $this->queries->retrieve($request, 'IssueQuery', $project, \in_array($_format, ['html', 'pdf'], true), true);
        $template = $request->isXmlHttpRequest() ? 'issues/_index_content.html.twig' : 'issues/index.html.twig';

        if (!$query->isValid()) {
            return match ($_format) {
                'json', 'xml' => $this->api->errors($request, $query->errors),
                'csv', 'atom', 'pdf' => new Response('', 422),
                default => $this->render($template, $this->list->vars($query, $project, [], 0, null)),
            };
        }
        try {
            switch ($_format) {
                case 'csv':
                    $this->list->authorizeExport($query, $project);

                    return $this->csv->response($request, $query);
                case 'pdf':
                    $this->list->authorizeExport($query, $project);

                    return $this->pdfExport->issues($request, $query, $project);
                case 'atom':
                    return $this->feed->render($request, $this->issueFeed->title($project), $this->issueFeed->items($query), $project);
                case 'json':
                case 'xml':
                    return $this->api->render($request, $this->presenter->issues($request, $query), 200, ['relation' => IssueApiPresenter::ISSUE_XML_ATTRIBUTES['relation']]);
            }
            $count = $query->issueCount();
            $pager = $this->paginate($request, $count);

            return $this->render($template, $this->list->vars($query, $project, $query->issues($pager['offset'], $pager['limit']), $count, $pager));
        } catch (StatementInvalid) {
            return $this->render('common/error.html.twig', ['message' => $this->l('error_query_statement_invalid'), 'status' => 500], new Response('', 500));
        }
    }

    /**
     * issues#index of a project. Its own action because the feeds and API keys are accepted per action
     * (App\Security\KeyAuthScope).
     */
    #[Route('/projects/{project_id}/issues.{_format}', name: 'project_issues', requirements: ['_format' => 'html|csv|atom|json|xml|pdf'], defaults: ['_format' => 'html'], methods: ['GET'], priority: 10)]
    public function projectIndex(Request $request, string $project_id, string $_format = 'html'): Response
    {
        return $this->index($request, $_format, $project_id);
    }
}

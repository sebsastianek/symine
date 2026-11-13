<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Api\IssueApiPresenter;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Wiki;
use App\Entity\WikiPage;
use App\Helper\Gantt;
use App\Query\IssueQuery;
use App\Security\Authorization;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Controller-side entry points of the PDF/PNG exports: permission checks (as the Redmine controllers
 * do them), document generation and send_data responses with Redmine's filenames.
 */
final class PdfExport
{
    public function __construct(
        private readonly IssuePdfExporter $issuesPdf,
        private readonly WikiPdfExporter $wikiPdf,
        private readonly GanttExporter $ganttExporter,
        private readonly IssueApiPresenter $presenter,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly PdfSupport $support,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** IssuesController#show format.pdf (the issue is already found and authorized) */
    public function issue(Issue $issue): Response
    {
        if (!$this->auth->allowedTo('export_issues', $issue->project)) {
            $this->deny();
        }
        $journals = $this->presenter->visibleJournals($issue);
        $pref = $this->currentUser->get()->getPref();
        if ('desc' === $pref->commentsSorting()) {
            $journals = array_reverse($journals);
        }

        return PdfSupport::response($this->issuesPdf->issueToPdf($issue, $journals), $issue->project->identifier.'-'.$issue->id.'.pdf');
    }

    /** IssuesController#index format.pdf (the query is valid and the export allowed) */
    public function issues(Request $request, IssueQuery $query, ?Project $project): Response
    {
        $limit = $this->support->settings->int('issues_export_limit') ?: 500;
        $issues = $query->issues(null, $limit);

        return PdfSupport::response($this->issuesPdf->issuesToPdf($issues, $project, $query), self::filenameForExport($request, $query, 'issues').'.pdf');
    }

    /** WikiController#show format.pdf */
    public function wikiPage(WikiPage $page, Project $project): Response
    {
        return PdfSupport::response($this->wikiPdf->wikiPageToPdf($page, $project), $page->title.'.pdf');
    }

    /** WikiController#export format.pdf */
    public function wikiPages(Wiki $wiki, Project $project): Response
    {
        return PdfSupport::response($this->wikiPdf->wikiPagesToPdf($wiki, $project), $project->identifier.'.pdf');
    }

    /** GanttsController#show format.pdf / format.png (export permission checked by the caller) */
    public function gantt(Gantt $gantt, ?Project $project, string $format): Response
    {
        $basename = (null !== $project ? $project->identifier.'-' : '').'gantt';
        if ('png' === $format) {
            return PdfSupport::response($this->ganttExporter->toImage($gantt), $basename.'.png', 'image/png', 'inline');
        }

        return PdfSupport::response($this->ganttExporter->toPdf($gantt), $basename.'.pdf');
    }

    /** ApplicationController#export_allowed?(:export_issues, query.base_scope) */
    public function exportAllowed(?IssueQuery $query, ?Project $project, string $permission = 'export_issues'): bool
    {
        $user = $this->currentUser->get();
        if ($user->admin) {
            return true;
        }
        if (null !== $project) {
            if (!$this->auth->allowedTo($permission, $project)) {
                return false;
            }
            if (($project->rgt ?? 0) - ($project->lft ?? 0) <= 1) {
                return true;
            }
        } elseif (!$this->auth->allowedToGlobally($permission)) {
            return false;
        }
        if (null === $query) {
            // Issue.none
            return true;
        }
        if (!$query->isValid()) {
            return false;
        }
        $allowed = $this->auth->allowedToCondition($permission, 'projects.id');
        try {
            $rows = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$query->baseFromSql().' WHERE '.$query->whereSql().' AND NOT ('.$allowed.')');
        } catch (\Throwable) {
            return false;
        }

        return 0 === (int) $rows;
    }

    /** QueriesHelper#filename_for_export */
    public static function filenameForExport(Request $request, IssueQuery $query, string $default): string
    {
        $name = (string) ($request->query->get('query_name') ?: $query->name());
        if ('_' === $name || '' === trim($name)) {
            $name = $default;
        }

        return mb_strtolower(Wiki::titleize($name));
    }

    /** @return never */
    public function deny(): void
    {
        if (!$this->currentUser->get()->isLogged()) {
            throw new AccessDeniedException();
        }
        throw new HttpException(403, $this->support->l('notice_not_authorized'));
    }
}

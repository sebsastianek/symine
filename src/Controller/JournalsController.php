<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Service\Issue\IssueChangesFeed;
use App\Service\Issue\IssueFinder;
use App\Service\Issue\IssueQuote;
use App\Service\Issue\JournalAttachmentsZip;
use App\Service\Issue\JournalFinder;
use App\Service\Issue\JournalJs;
use App\Service\Issue\JournalNotesForm;
use App\Service\JournalDiff;
use App\Twig\IssuesExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's JournalsController: notes edition, description diff, quote and the changes feed. */
final class JournalsController extends AbstractController
{
    public function __construct(
        private readonly JournalFinder $journals,
        private readonly IssueFinder $issues,
        private readonly JournalNotesForm $notesForm,
        private readonly IssueQuote $issueQuote,
        private readonly IssueChangesFeed $changes,
        private readonly JournalAttachmentsZip $zip,
        private readonly ApiRenderer $api,
        private readonly IssuesExtension $helpers,
    ) {
    }

    #[Route('/journals/{id}/edit', name: 'journal_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $journal = $this->journals->find($id);
        $this->notesForm->authorize($journal);
        $form = $this->renderView('journals/_notes_form.html.twig', $this->notesForm->view($journal));

        return new Response(JournalJs::edit((int) $journal->id, $form), 200, ['Content-Type' => JournalJs::CONTENT_TYPE]);
    }

    #[Route('/journals/{id}.{_format}', name: 'journal_update', requirements: ['id' => '\d+', '_format' => 'html|json|xml|js'], defaults: ['_format' => 'html'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id, string $_format = 'html'): Response
    {
        $journal = $this->journals->find($id);
        $this->notesForm->authorize($journal);
        $this->notesForm->submit($request, $journal);
        $issue = $journal->issue;
        $this->callHook('controller_journals_edit_post', ['journal' => $journal, 'params' => $request->request->all(), 'project' => $issue->project]);

        if ($this->isApi($request)) {
            return $this->api->noContent();
        }
        if ('js' === $_format || $request->isXmlHttpRequest()) {
            if ($this->notesForm->isEmptied($journal)) {
                return new Response(JournalJs::removed($id), 200, ['Content-Type' => JournalJs::CONTENT_TYPE]);
            }
            $vars = $this->notesForm->updatedView($journal);
            $js = JournalJs::updated(
                $id,
                $this->helpers->journalCssClasses($journal),
                $this->renderView('journals/_actions.html.twig', $vars),
                $this->renderView('journals/_private_notes_indicator.html.twig', $vars),
                $this->renderView('journals/_notes.html.twig', $vars),
                $this->renderView('journals/_update_info.html.twig', $vars),
            );

            return new Response($js, 200, ['Content-Type' => JournalJs::CONTENT_TYPE]);
        }

        return $this->redirectToRoute('issue_show', ['id' => $issue->id]);
    }

    #[Route('/journals/{id}/diff', name: 'journal_diff', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function diff(Request $request, int $id): Response
    {
        $journal = $this->journals->find($id);
        $issue = $journal->issue;
        $this->authorize('view_issues', $issue->project);
        $detail = $this->journals->diffDetail($journal, $request->query->get('detail_id'));

        return $this->render('journals/diff.html.twig', [
            'journal' => $journal,
            'issue' => $issue,
            'project' => $issue->project,
            'diff_html' => JournalDiff::toHtml((string) $detail->value, (string) $detail->oldValue),
            'main_menu_selected' => 'issues',
            'page_title' => $issue->tracker->name.' #'.$issue->id.': '.$issue->subject,
            'body_css' => 'controller-journals action-diff',
        ]);
    }

    /** Quote an issue description or a journal into the notes field (JournalsController#new) */
    #[Route('/issues/{id}/quoted', name: 'issue_quoted', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function quote(Request $request, int $id): Response
    {
        $issue = $this->issues->find($id);
        $this->issueQuote->authorize($issue);
        $quote = $this->issueQuote->quote($request, $issue);

        return new Response(JournalJs::quote($quote['content'], $quote['private']), 200, ['Content-Type' => JournalJs::CONTENT_TYPE]);
    }

    /**
     * Atom feed of the issue changes (JournalsController#index): the last 25 journals of the issues matching
     * the query (query_id, filter parameters or the query kept in the session), subprojects included.
     */
    #[Route('/issues/changes.{_format}', name: 'issue_changes', requirements: ['_format' => 'atom'], defaults: ['_format' => 'atom'], methods: ['GET'])]
    public function index(Request $request): Response
    {
        $project = null;
        if ($pid = $request->query->get('project_id')) {
            $project = $this->findProject((string) $pid);
        }
        $this->authorize('view_issues', $project, null === $project);

        return $this->render('journals/index.atom.twig', $this->changes->view($request, $project), new Response('', 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']));
    }

    /**
     * AttachmentsController#download_all for a journal (attachments/journals/:id/download): a zip of the
     * attachments that were added with the journal.
     */
    #[Route('/attachments/journals/{object_id}/download', name: 'journal_attachments_download', requirements: ['object_id' => '\d+'], methods: ['GET'], priority: 5)]
    public function downloadAttachments(int $object_id): Response
    {
        $journal = $this->journals->find($object_id);
        $issue = $journal->issue;
        // Journal#attachments_visible? => Issue#attachments_visible?
        $this->authorize('view_issues', $issue->project);
        $attachments = $this->zip->attachments($journal);
        if ($this->zip->isTooBig($attachments)) {
            $this->flashError($this->l('error_bulk_download_size_too_big', ['max_size' => $this->zip->maxSize()]));

            return $this->redirectToRoute('issue_show', ['id' => $issue->id]);
        }

        return $this->zip->download($journal, $attachments);
    }
}

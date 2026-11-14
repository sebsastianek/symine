<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Api\ApiRenderer;
use App\Api\IssueApiPresenter;
use App\Entity\Journal;
use App\Security\AccessGuard;
use App\Security\ApiRequestMatcher;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The form to edit the notes of an issue journal (journals#edit / #update): who may use it, what it saves
 * and the variables of the partials that show the result.
 */
final class JournalNotesForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUser $currentUser,
        private readonly Authorization $auth,
        private readonly AccessGuard $guard,
        private readonly IssueService $issues,
        private readonly AttachmentService $attachments,
        private readonly IssueApiPresenter $presenter,
    ) {
    }

    /** The user may edit notes in the project, and these ones in particular (Journal#editable_by?) */
    public function authorize(Journal $journal): void
    {
        $p = $journal->issue->project;
        if (!$this->auth->allowedTo('edit_issue_notes', $p) && !$this->auth->allowedTo('edit_own_issue_notes', $p)) {
            $this->guard->deny();
        }
        if (!$this->issues->isJournalEditable($journal)) {
            $this->guard->deny();
        }
    }

    /**
     * Variables of journals/_notes_form.
     *
     * @return array<string, mixed>
     */
    public function view(Journal $journal): array
    {
        return [
            'journal' => $journal,
            'can_set_private' => $this->auth->allowedTo('set_notes_private', $journal->issue->project),
        ];
    }

    /** Saves the notes submitted by the form or the API */
    public function submit(Request $request, Journal $journal): void
    {
        $attrs = ApiRequestMatcher::isApiRequest($request) ? (array) (ApiRenderer::payload($request)['journal'] ?? []) : array_merge($request->query->all('journal'), $request->request->all('journal'));
        if (\array_key_exists('notes', $attrs)) {
            $journal->notes = preg_replace('/\r\n?|\n/', "\r\n", (string) $attrs['notes']);
        }
        if (\array_key_exists('private_notes', $attrs) && $this->auth->allowedTo('set_notes_private', $journal->issue->project)) {
            $journal->privateNotes = \in_array($attrs['private_notes'], [true, 1, '1', 'true'], true);
        }
        $journal->updatedBy = $this->currentUser->get();
        $journal->updatedOn = new \DateTimeImmutable();
        // Redmine 7: a journal whose notes are emptied is kept (not destroyed), even without details
        if (!$journal->hasNotes() && $journal->details->count() > 0) {
            $journal->privateNotes = false;
        }
        $this->em->flush();
    }

    /** Whether nothing is left to show of the journal: no notes and no changes */
    public function isEmptied(Journal $journal): bool
    {
        return !$journal->hasNotes() && 0 === $journal->details->count();
    }

    /**
     * Variables of the journals/_actions, _private_notes_indicator, _notes and _update_info partials, which
     * replace the journal in the issue page once its notes are saved.
     *
     * @return array<string, mixed>
     */
    public function updatedView(Journal $journal): array
    {
        $issue = $journal->issue;
        $this->presenter->visibleJournals($issue); // sets indices

        return ['journal' => $journal, 'issue' => $issue, 'reply_links' => $this->issues->isNotesAddable($issue), 'attachments' => $this->attachments->forContainer('Issue', $issue->id)];
    }
}

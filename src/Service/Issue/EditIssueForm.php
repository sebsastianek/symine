<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Api\IssueApiPresenter;
use App\Entity\Attachment;
use App\Entity\Issue;
use App\Security\AccessGuard;
use App\Security\ApiRequestMatcher;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The issue edit form (issues#edit / #update): assigns the submitted parameters, saves the issue with its
 * notes, attachments and logged time, detects edit conflicts and gives the variables of the form.
 */
final class EditIssueForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUser $currentUser,
        private readonly Authorization $auth,
        private readonly AccessGuard $guard,
        private readonly IssueService $issues,
        private readonly AttachmentService $attachments,
        private readonly IssueApiPresenter $presenter,
        private readonly IssueFormView $form,
        private readonly IssueTimeLog $timeLog,
    ) {
    }

    /** The user may edit the issue or add notes to it (authorize + Issue#editable?) */
    public function authorize(Issue $issue): void
    {
        $p = $issue->project;
        if (!$this->auth->allowedTo('edit_issues', $p) && !$this->auth->allowedTo('edit_own_issues', $p) && !$this->auth->allowedTo('add_issue_notes', $p)) {
            $this->guard->deny();
        }
        if (!$this->issues->isEditable($issue)) {
            $this->guard->deny();
        }
    }

    /**
     * Assigns the submitted attributes the user may change (IssuesController#update_issue_from_params).
     *
     * @param array<string, mixed> $attrs
     */
    public function assign(Issue $issue, array $attrs): void
    {
        if ('me' === ($attrs['assigned_to_id'] ?? null)) {
            $attrs['assigned_to_id'] = $this->currentUser->get()->id;
        }
        $attrs = IssueParams::replaceNoneValues($attrs);
        $this->issues->setSafeAttributes($issue, $attrs);
    }

    /**
     * Updates the issue from the form or the API (issues#update).
     *
     * @param \Closure(string, array<string, mixed>): mixed $hook call_hook of the calling controller
     */
    public function submit(Request $request, Issue $issue, \Closure $hook): IssueUpdate
    {
        $isApi = ApiRequestMatcher::isApiRequest($request);
        $attrs = IssueParams::issue($request);
        $resolution = (string) $request->request->get('conflict_resolution', '');
        if ([] !== $attrs && '' !== $resolution) {
            switch ($resolution) {
                case 'overwrite':
                    unset($attrs['lock_version']);
                    break;
                case 'add_notes':
                    $attrs = array_intersect_key($attrs, ['notes' => 1, 'private_notes' => 1]);
                    break;
                case 'cancel':
                    return new IssueUpdate(cancelled: true);
            }
        }
        // optimistic locking (ActiveRecord::StaleObjectError)
        $currentLock = (int) $this->em->getConnection()->fetchOne('SELECT lock_version FROM issues WHERE id = ?', [$issue->id]);
        $conflict = \array_key_exists('lock_version', $attrs) && '' !== (string) $attrs['lock_version'] && (int) $attrs['lock_version'] !== $currentLock;
        unset($attrs['lock_version']);

        // attachments (stored before the issue attributes change: flushing them must not save the issue)
        $uploads = $isApi ? (\is_array($attrs['uploads'] ?? null) ? $attrs['uploads'] : []) : null;
        $attachmentsAddable = $this->issues->isAttributesEditable($issue) || $this->issues->isNotesAddable($issue);
        $atts = [];
        $unsavedAttachments = 0;
        if ($attachmentsAddable && !$conflict) {
            [$atts] = $this->attachments->fromRequest($request, $this->currentUser->get(), $uploads);
            if ([] !== $atts) {
                $this->em->flush();
            }
        } elseif (!$attachmentsAddable && ([] !== $request->files->all('attachments') || [] !== (array) $uploads)) {
            $unsavedAttachments = \count($request->files->all('attachments')) ?: \count((array) $uploads);
        }
        $this->assign($issue, $attrs);
        if ([] !== $atts) {
            $this->issues->addAttachments($issue, $atts);
        }

        if ($conflict) {
            $conflictJournals = [];
            if (!$isApi && ($last = $request->request->get('last_journal_id'))) {
                foreach ($this->presenter->visibleJournals($issue) as $j) {
                    if ($j->id > (int) $last) {
                        $conflictJournals[] = $j;
                    }
                }
            }

            return new IssueUpdate(conflict: true, conflictJournals: $conflictJournals, unsavedAttachments: $unsavedAttachments);
        }

        // time entry logged along with the update
        $timeEntry = null;
        $timeParams = $isApi ? [] : $request->request->all('time_entry');
        $errors = [];
        if ([] !== $timeParams && ('' !== trim((string) ($timeParams['hours'] ?? '')) || '' !== trim((string) ($timeParams['comments'] ?? '')))
            && $this->auth->allowedTo('log_time', $issue->project)) {
            [$timeEntry, $errors] = $this->timeLog->build($issue, $timeParams);
        }
        $journalAdded = false;
        if ([] === $errors) {
            $journalCountBefore = $issue->journals->count();
            $hookContext = ['params' => $request->request->all(), 'issue' => $issue, 'time_entry' => $timeEntry, 'journal' => null, 'project' => $issue->project];
            $hook('controller_issues_edit_before_save', $hookContext);
            $errors = $this->issues->save($issue);
            if ([] === $errors && $timeEntry) {
                $this->timeLog->save($timeEntry);
            }
            if ([] === $errors) {
                $hookContext['journal'] = $issue->journals->count() > $journalCountBefore ? ($issue->journals->last() ?: null) : null;
                $hook('controller_issues_edit_after_save', $hookContext);
            }
            $journalAdded = $issue->journals->count() > $journalCountBefore;
        }

        return new IssueUpdate(errors: $errors, savedAttachments: $atts, unsavedAttachments: $unsavedAttachments, journalAdded: $journalAdded);
    }

    /**
     * Variables of the issues/_edit partial (the edit form, also shown under the issue).
     *
     * @param list<Attachment> $savedAttachments files already stored by a submission that was refused
     *
     * @return array<string, mixed>
     */
    public function view(Request $request, Issue $issue, array $savedAttachments = []): array
    {
        $timeParams = $request->request->all('time_entry');
        $params = IssueParams::issue($request);

        return $this->form->vars($request, $issue, $issue->project) + [
            'attributes_editable' => $this->issues->isAttributesEditable($issue),
            'notes_addable' => $this->issues->isNotesAddable($issue),
            'time_loggable' => $this->issues->isTimeLoggable($issue),
            'attachments_addable' => $this->issues->isAttributesEditable($issue) || $this->issues->isNotesAddable($issue),
            'existing_attachments' => $this->attachments->forContainer('Issue', $issue->id),
            'deleted_attachment_ids' => array_map('intval', (array) ($params['deleted_attachment_ids'] ?? [])),
            'activities' => $this->timeLog->activities($issue->project),
            'time_entry' => $timeParams,
            'time_entry_custom_fields' => $this->timeLog->customFields($issue),
            'time_entry_custom_values' => $this->timeLog->customValues($timeParams),
            'notes' => $this->issues->pendingNotes($issue),
            'private_notes' => '1' === (string) ($params['private_notes'] ?? ''),
            'last_journal_id' => $request->request->get('last_journal_id') ?? $this->lastJournalId($issue),
            'conflict' => false,
            'conflict_journals' => [],
            'saved_attachments' => $savedAttachments,
        ];
    }

    /**
     * Variables of issues/edit: the form, with the errors or the conflict of the update that was refused.
     *
     * @return array<string, mixed>
     */
    public function page(Request $request, Issue $issue, ?IssueUpdate $refused = null): array
    {
        $conflict = true === $refused?->conflict
            ? ['conflict' => true, 'conflict_journals' => $refused->conflictJournals, 'lock_version' => $request->request->all('issue')['lock_version'] ?? null]
            : [];

        return $conflict + $this->view($request, $issue, $refused?->savedAttachments ?? []) + [
            'project' => $issue->project,
            'issue' => $issue,
            'errors' => $refused?->errors ?? [],
            'main_menu_selected' => 'issues',
            'page_title' => $issue->tracker->name.' #'.$issue->id.': '.$issue->subject,
            'body_css' => 'controller-issues action-'.($request->isMethod('GET') ? 'edit' : 'update'),
        ];
    }

    /**
     * The form refreshed after a field was changed (updateIssueFrom): the submitted values are assigned to
     * show what they allow, nothing is saved (see discard()).
     *
     * @return array{form: array<string, mixed>, time_loggable: bool, notes_addable: bool}
     */
    public function refresh(Request $request, Issue $issue): array
    {
        $this->assign($issue, IssueParams::issue($request));

        return [
            'form' => $this->form->vars($request, $issue, $issue->project),
            'time_loggable' => $this->auth->allowedTo('log_time', $issue->project),
            'notes_addable' => $this->issues->isNotesAddable($issue),
        ];
    }

    /** Forgets the values assigned for a form refresh, once the form is rendered: they must never be saved */
    public function discard(Issue $issue): void
    {
        $this->em->detach($issue);
    }

    private function lastJournalId(Issue $issue): ?int
    {
        $id = $this->em->getConnection()->fetchOne("SELECT MAX(id) FROM journals WHERE journalized_type = 'Issue' AND journalized_id = ?", [$issue->id]);

        return false === $id || null === $id ? null : (int) $id;
    }
}

<?php

declare(strict_types=1);

namespace Symine\Kanban\Card;

use App\Entity\Issue;
use App\Entity\User;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Service\AttachmentService;
use App\Service\IssueService;
use App\Service\WatcherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symine\Kanban\Entity\Board;

/**
 * What can be done to an issue from the dialog of its card: change a field, comment, add files, add a subtask,
 * watch. Every change goes through the same doors as the issue form (IssueService: safe attributes, validations,
 * journal, notifications), so the dialog can do nothing the form would refuse.
 */
final class CardEditor
{
    public function __construct(
        private readonly CardQuery $cards,
        private readonly IssueService $issues,
        private readonly AttachmentService $attachments,
        private readonly WatcherService $watchers,
        private readonly Authorization $auth,
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
    ) {
    }

    /** The issue of a card of the board, when the user may see it there */
    public function find(Board $board, int $issueId, User $user): ?Issue
    {
        return $this->cards->card($board, $issueId, $user);
    }

    /**
     * Changes fields (issue[<name>], only the ones of CardDetails::FIELDS) and/or adds a comment (notes, private_notes).
     */
    public function update(Board $board, int $issueId, Request $request, User $user): MoveResult
    {
        $issue = $this->find($board, $issueId, $user);
        if (null === $issue) {
            return MoveResult::refused($this->i18n->l('error_kanban_card_not_found'), 404);
        }
        $attributes = array_intersect_key($request->request->all('issue'), array_flip(CardDetails::FIELDS));
        $notes = trim((string) $request->request->get('notes', ''));
        if ([] === $attributes && '' === $notes) {
            return MoveResult::refused($this->i18n->l('field_notes').' '.$this->i18n->l('activerecord.errors.messages.blank'));
        }
        if ('' !== $notes && !$this->issues->isNotesAddable($issue, $user)) {
            return MoveResult::refused($this->i18n->l('notice_not_authorized'), 403);
        }
        // what the user may not change is refused rather than silently dropped: the dialog must not look saved
        $refused = array_diff(array_keys($attributes), $this->issues->safeAttributeNames($issue, $user));
        if ([] !== $refused) {
            return MoveResult::refused($this->i18n->l('error_kanban_field_not_allowed', ['issue' => '#'.$issue->id]), 403);
        }

        if ('' !== $notes) {
            $private = $request->request->getBoolean('private_notes') && $this->auth->allowedTo('set_notes_private', $issue->project, $user);
            $this->issues->setNotes($issue, $notes, $private);
        }
        if ([] !== $attributes) {
            $this->issues->setSafeAttributes($issue, $attributes, $user);
        }

        return $this->save($issue, $user);
    }

    /** Adds the files of the request (attachments[<n>][file]) to the issue */
    public function attach(Board $board, int $issueId, Request $request, User $user): MoveResult
    {
        $issue = $this->find($board, $issueId, $user);
        if (null === $issue) {
            return MoveResult::refused($this->i18n->l('error_kanban_card_not_found'), 404);
        }
        if (!$this->issues->isAttributesEditable($issue, $user)) {
            return MoveResult::refused($this->i18n->l('notice_not_authorized'), 403);
        }
        [$files, $errors] = $this->attachments->fromRequest($request, $user);
        if ([] !== $errors || [] === $files) {
            return new MoveResult($issue, [] !== $errors ? array_values($errors) : [$this->i18n->l('error_kanban_no_file')], 422);
        }
        $this->issues->addAttachments($issue, $files);

        return $this->save($issue, $user);
    }

    /** Adds a subtask (subject) in the project of the issue, with its tracker when the user may use it there */
    public function addSubtask(Board $board, int $issueId, Request $request, User $user): MoveResult
    {
        $parent = $this->find($board, $issueId, $user);
        if (null === $parent) {
            return MoveResult::refused($this->i18n->l('error_kanban_card_not_found'), 404);
        }
        $project = $parent->project;
        if (!$this->auth->allowedTo('add_issues', $project, $user) || !$this->auth->allowedTo('manage_subtasks', $project, $user)) {
            return MoveResult::refused($this->i18n->l('notice_not_authorized'), 403);
        }
        $child = $this->issues->newIssue($project, $user);
        $this->issues->setSafeAttributes($child, ['tracker_id' => (string) $parent->tracker->id], $user);
        $this->issues->setSafeAttributes($child, ['subject' => trim((string) $request->request->get('subject')), 'parent_issue_id' => (string) $parent->id], $user);
        if ($child->parent?->id !== $parent->id) {
            return MoveResult::refused($this->i18n->l('notice_not_authorized'), 403);
        }
        $errors = $this->issues->save($child, $user);
        if ([] !== $errors) {
            return new MoveResult($parent, array_values($errors), 422);
        }
        $this->em->refresh($parent);

        return new MoveResult($parent, [], 201);
    }

    /** Starts or stops watching the issue */
    public function toggleWatch(Board $board, int $issueId, User $user): MoveResult
    {
        $issue = $this->find($board, $issueId, $user);
        if (null === $issue) {
            return MoveResult::refused($this->i18n->l('error_kanban_card_not_found'), 404);
        }
        $this->watchers->setWatching([$issue], $user, !$this->watchers->isWatching('Issue', (int) $issue->id, $user));

        return new MoveResult($issue);
    }

    private function save(Issue $issue, User $user): MoveResult
    {
        $errors = $this->issues->save($issue, $user);
        if ([] !== $errors) {
            // nothing of a refused change stays behind for a later flush
            $this->em->refresh($issue);

            return new MoveResult($issue, array_values($errors), 422);
        }

        return new MoveResult($issue);
    }
}

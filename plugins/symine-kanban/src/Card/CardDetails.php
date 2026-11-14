<?php

declare(strict_types=1);

namespace Symine\Kanban\Card;

use App\Api\IssueApiPresenter;
use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\User;
use App\Security\Authorization;
use App\Service\AttachmentService;
use App\Service\IssueService;
use App\Service\WatcherService;
use Doctrine\ORM\EntityManagerInterface;
use Symine\Kanban\Entity\Board;

/**
 * What the dialog of a card shows: the issue with its description, files, subtasks and activity, and for every
 * field whether this user may change it there (the "safe attributes" of the issue form, nothing more).
 */
final class CardDetails
{
    /** Fields the dialog can change, as names of issue attributes */
    public const array FIELDS = ['subject', 'description', 'status_id', 'assigned_to_id', 'priority_id', 'fixed_version_id', 'category_id', 'start_date', 'due_date', 'done_ratio', 'estimated_hours'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IssueService $issues,
        private readonly IssueApiPresenter $presenter,
        private readonly AttachmentService $attachments,
        private readonly WatcherService $watchers,
        private readonly Authorization $auth,
    ) {
    }

    /** @return array<string, mixed> the variables of the dialog */
    public function view(Board $board, Issue $issue, User $user): array
    {
        $project = $issue->project;
        $safe = $user->isLogged() ? array_values(array_intersect(self::FIELDS, $this->issues->safeAttributeNames($issue, $user))) : [];

        $statuses = [$issue->status->id => $issue->status];
        foreach ($this->issues->newStatusesAllowedTo($issue, $user) as $status) {
            $statuses[$status->id] = $status;
        }
        uasort($statuses, static fn ($a, $b) => [$a->position, $a->id] <=> [$b->position, $b->id]);

        $children = [];
        foreach ($issue->children as $child) {
            if ($this->issues->isVisible($child, $user)) {
                $children[] = $child;
            }
        }
        usort($children, static fn (Issue $a, Issue $b) => $a->id <=> $b->id);

        // newest first, like a conversation
        $journals = array_reverse($this->presenter->visibleJournals($issue));
        $column = $board->columnsByStatus()[$issue->status->id] ?? null;

        return [
            'board' => $board,
            'issue' => $issue,
            'project' => $project,
            'column' => $column,
            'editable' => array_fill_keys($safe, true),
            'statuses' => array_values($statuses),
            'assignees' => isset($safe) && \in_array('assigned_to_id', $safe, true) ? $this->issues->assignableUsers($issue) : [],
            'priorities' => $this->em->getRepository(IssuePriority::class)->findBy(['active' => true], ['position' => 'ASC']),
            'versions' => \in_array('fixed_version_id', $safe, true) ? $this->issues->assignableVersions($issue) : [],
            'categories' => $project->issueCategories->toArray(),
            'attachments' => $this->attachments->forContainer('Issue', $issue->id),
            'children' => $children,
            'children_closed' => \count(array_filter($children, static fn (Issue $c) => $c->isClosed())),
            'journals' => $journals,
            'comments_count' => \count(array_filter($journals, static fn ($j) => $j->hasNotes())),
            'can_comment' => $user->isLogged() && $this->issues->isNotesAddable($issue, $user),
            'can_comment_privately' => $user->isLogged() && $this->auth->allowedTo('set_notes_private', $project, $user),
            'can_attach' => $user->isLogged() && $this->issues->isAttributesEditable($issue, $user),
            'can_add_subtask' => $user->isLogged() && $this->auth->allowedTo('add_issues', $project, $user) && $this->auth->allowedTo('manage_subtasks', $project, $user)
                && $issue->tracker->isCoreFieldEnabled('parent_issue_id'),
            'can_watch' => $user->isLogged(),
            'watching' => $user->isLogged() && $this->watchers->isWatching('Issue', (int) $issue->id, $user),
            'spent_hours' => $this->auth->allowedTo('view_time_entries', $project, $user) ? $this->issues->spentHours($issue) : null,
            'done_ratios' => range(0, 100, 10),
        ];
    }
}

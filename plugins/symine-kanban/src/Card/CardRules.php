<?php

declare(strict_types=1);

namespace Symine\Kanban\Card;

use App\Entity\Issue;
use App\Entity\IssueRelation;
use App\Entity\User;
use App\Security\CurrentUser;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symine\Kanban\Entity\Board;

/**
 * Where a card may go: the columns holding a status that the workflow lets the user give to the issue
 * (Issue#new_statuses_allowed_to), for a user who may change the issue at all.
 *
 * The answer only depends on the project, tracker and status of the issue and on whether the user is its author
 * or assignee, so it is worked out once for all the cards alike.
 */
final class CardRules implements ResetInterface
{
    /** @var array<string, list<int>> */
    private array $allowed = [];
    /** @var array<int, int>|null issues blocked by an open issue, as keys */
    private ?array $blocked = null;

    public function __construct(
        private readonly IssueService $issues,
        private readonly CurrentUser $currentUser,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function reset(): void
    {
        $this->allowed = [];
        $this->blocked = null;
    }

    /** @return list<int> ids of the statuses the user may give to the issue, the current one left out */
    public function allowedStatusIds(Issue $issue, ?User $user = null): array
    {
        $user ??= $this->currentUser->get();
        $isAuthor = $issue->author->id === $user->id;
        $assigneeId = $issue->assignedTo?->id;
        $isAssignee = null !== $assigneeId && ($assigneeId === $user->id || \in_array($assigneeId, $user->getGroupIds(), true));
        // the workflow gives the same answer to all the cards alike in these respects; whether an issue can be closed
        // (open subtasks, blocking issues) or reopened (closed parent) is its own matter
        $key = implode(':', [
            $user->id ?? 0, $issue->project->id, $issue->tracker->id, $issue->status->id, (int) $isAuthor, (int) $isAssignee,
            (int) $this->isClosable($issue), (int) (null === $issue->parent || $this->issues->isReopenable($issue)),
        ]);
        if (!isset($this->allowed[$key])) {
            $ids = [];
            if ($this->issues->isEditable($issue, $user)) {
                foreach ($this->issues->newStatusesAllowedTo($issue, $user) as $status) {
                    if ($status->id !== $issue->status->id) {
                        $ids[] = (int) $status->id;
                    }
                }
            }
            $this->allowed[$key] = $ids;
        }

        return $this->allowed[$key];
    }

    /** Issue#closable?, without loading the subtasks and relations of every card: most cards have neither */
    private function isClosable(Issue $issue): bool
    {
        $this->blocked ??= array_flip(array_map('intval', $this->em->createQuery(
            'SELECT DISTINCT IDENTITY(r.issueTo) FROM '.IssueRelation::class.' r JOIN r.issueFrom f JOIN f.status s WHERE r.relationType = :blocks AND s.isClosed = false'
        )->setParameter('blocks', IssueRelation::TYPE_BLOCKS)->getSingleColumnResult()));

        return !isset($this->blocked[(int) $issue->id]) && ($issue->isLeaf() || $this->issues->isClosable($issue));
    }

    /**
     * The columns the card can be dropped on, with the statuses it can take there (the preferred one first).
     * Its own column is always among them (reordering), with no status to change.
     *
     * @return array<int, list<int>> column id => status ids
     */
    public function moves(Board $board, Issue $issue, ?User $user = null): array
    {
        $allowed = $this->allowedStatusIds($issue, $user);
        $moves = [];
        foreach ($board->columns as $column) {
            $statusIds = $column->statusIds();
            if (\in_array($issue->status->id, $statusIds, true)) {
                $moves[(int) $column->id] = [];
            } elseif ([] !== ($targets = array_values(array_intersect($statusIds, $allowed)))) {
                $moves[(int) $column->id] = $targets;
            }
        }

        return $moves;
    }
}

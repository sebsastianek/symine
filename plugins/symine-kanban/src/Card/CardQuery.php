<?php

declare(strict_types=1);

namespace Symine\Kanban\Card;

use App\Doctrine\BatchLoader;
use App\Entity\Issue;
use App\Entity\User;
use App\Security\CurrentUser;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symine\Kanban\Board\BoardScope;
use Symine\Kanban\Entity\Board;
use Symine\Kanban\Entity\CardPosition;

/**
 * The issues of a board: the ones of its projects and trackers that the user may see (Redmine's issue visibility:
 * private issues, roles, tracker permissions), except the statuses hidden on purpose and the issues closed longer
 * ago than the board keeps them.
 */
final class CardQuery
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BoardScope $scope,
        private readonly IssueService $issues,
        private readonly CurrentUser $currentUser,
        private readonly BatchLoader $batch,
    ) {
    }

    /**
     * Number of cards per status.
     *
     * @return array<int, int> status id => count
     */
    public function countsByStatus(Board $board, ?CardFilter $filter = null, ?User $user = null): array
    {
        $qb = $this->base($board, $filter, $user);
        if (null === $qb) {
            return [];
        }
        $counts = [];
        foreach ($qb->select('IDENTITY(i.status) AS status_id', 'COUNT(i.id) AS n')->groupBy('i.status')->getQuery()->getArrayResult() as $row) {
            $counts[(int) $row['status_id']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The cards of some statuses, in the order of the board.
     *
     * @param list<int> $statusIds
     *
     * @return list<Issue>
     */
    public function cards(Board $board, array $statusIds, ?CardFilter $filter = null, int $limit = 100, int $offset = 0, ?User $user = null): array
    {
        $qb = [] === $statusIds ? null : $this->base($board, $filter, $user);
        if (null === $qb) {
            return [];
        }
        $qb->select('i', 's', 't', 'p', 'pr', 'a', 'v', 'c')
            ->join('i.tracker', 't')->join('i.project', 'p')->join('i.priority', 'pr')
            ->leftJoin('i.assignedTo', 'a')->leftJoin('i.fixedVersion', 'v')->leftJoin('i.category', 'c')
            ->andWhere('i.status IN (:statuses)')->setParameter('statuses', $statusIds)
            ->setFirstResult($offset)->setMaxResults($limit);
        $this->order($qb, $board);
        $cards = $qb->getQuery()->getResult();
        // what a card may be changed to depends on whether it has subtasks: one query for the page, not one per card
        $this->batch->collections($cards, 'children');

        return $cards;
    }

    /**
     * Every card of some statuses, as ids in manual order: what ranking a card works on.
     *
     * @param list<int> $statusIds
     *
     * @return list<int>
     */
    public function orderedIds(Board $board, array $statusIds, ?User $user = null): array
    {
        $qb = [] === $statusIds ? null : $this->base($board, null, $user);
        if (null === $qb) {
            return [];
        }
        $qb->select('i.id')->andWhere('i.status IN (:statuses)')->setParameter('statuses', $statusIds);
        $this->manualOrder($qb, $board);

        return array_map('intval', $qb->getQuery()->getSingleColumnResult());
    }

    /** The issue of a card, when it is on the board for the user */
    public function card(Board $board, int $issueId, ?User $user = null): ?Issue
    {
        $qb = $this->base($board, null, $user, false);
        if (null === $qb) {
            return null;
        }

        return $qb->select('i')->andWhere('i.id = :id')->setParameter('id', $issueId)->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /**
     * Ids of what the cards of the board refer to through an association (assignedTo, fixedVersion): the choices of
     * the quick filters, limited to what the user can see on the board.
     *
     * @return list<int>
     */
    public function referencedIds(Board $board, string $association, ?User $user = null): array
    {
        $qb = \in_array($association, ['assignedTo', 'fixedVersion'], true) ? $this->base($board, null, $user) : null;
        if (null === $qb) {
            return [];
        }

        return array_map('intval', $qb->select('DISTINCT IDENTITY(i.'.$association.')')->andWhere('i.'.$association.' IS NOT NULL')->getQuery()->getSingleColumnResult());
    }

    /** When the cards of the board last changed, with their number: a cheap way to know whether to redraw it */
    public function version(Board $board, ?CardFilter $filter = null, ?User $user = null): string
    {
        $qb = $this->base($board, $filter, $user);
        $row = null === $qb ? [] : $qb->select('COUNT(i.id) AS n', 'MAX(i.updatedOn) AS updated')->getQuery()->getArrayResult()[0] ?? [];
        $positions = $this->em->createQueryBuilder()->select('COUNT(cp.id)', 'SUM(cp.position)')->from(CardPosition::class, 'cp')
            ->where('cp.boardId = :board')->setParameter('board', (int) $board->id)->getQuery()->getSingleResult();

        return substr(hash('xxh128', json_encode([$row, $positions, $board->updatedOn->getTimestamp()])), 0, 16);
    }

    /**
     * @param bool $respectWindow leave out the issues closed too long ago and the hidden statuses
     */
    private function base(Board $board, ?CardFilter $filter, ?User $user, bool $respectWindow = true): ?QueryBuilder
    {
        $user ??= $this->currentUser->get();
        $projectIds = $this->scope->projectIds($board, $user);
        if ([] === $projectIds) {
            return null;
        }
        $qb = $this->em->createQueryBuilder()->from(Issue::class, 'i')->join('i.status', 's')
            ->where('i.project IN (:projects)')->setParameter('projects', $projectIds)
            ->andWhere($this->issues->visibleCondition($user, 'i'));

        $trackerIds = array_values(array_map('intval', $board->trackerIds ?? []));
        if ([] !== $trackerIds) {
            $qb->andWhere('i.tracker IN (:trackers)')->setParameter('trackers', $trackerIds);
        }
        if ($respectWindow) {
            $hidden = $board->hiddenStatusIds();
            if ([] !== $hidden) {
                $qb->andWhere('i.status NOT IN (:hidden)')->setParameter('hidden', $hidden);
            }
            $days = (int) $board->option('closed_days');
            if ($days > 0) {
                $qb->andWhere('(s.isClosed = false OR i.closedOn IS NULL OR i.closedOn >= :closed_since)')
                    ->setParameter('closed_since', new \DateTimeImmutable('-'.$days.' days'));
            }
        }
        if (null !== $filter) {
            $this->filter($qb, $filter, $user);
        }

        return $qb;
    }

    private function filter(QueryBuilder $qb, CardFilter $filter, User $user): void
    {
        if ('' !== $filter->text) {
            if (preg_match('/^#?(\d+)$/', $filter->text, $m)) {
                $qb->andWhere('i.id = :card_id')->setParameter('card_id', (int) $m[1]);
            } else {
                foreach (array_slice(preg_split('/\s+/', $filter->text) ?: [], 0, 5) as $n => $word) {
                    // "%" and "_" typed by the user are searched for as such, on every database
                    $qb->andWhere('LOWER(i.subject) LIKE :word'.$n." ESCAPE '!'")
                        ->setParameter('word'.$n, '%'.strtr(mb_strtolower($word), ['!' => '!!', '%' => '!%', '_' => '!_']).'%');
                }
            }
        }
        if ('me' === $filter->assignee) {
            $ids = $user->isLogged() ? [(int) $user->id, ...array_map('intval', $user->getGroupIds())] : [0];
            $qb->andWhere('IDENTITY(i.assignedTo) IN (:assignees)')->setParameter('assignees', $ids);
        } elseif ('none' === $filter->assignee) {
            $qb->andWhere('i.assignedTo IS NULL');
        } elseif ('' !== $filter->assignee) {
            $qb->andWhere('IDENTITY(i.assignedTo) = :assignee')->setParameter('assignee', (int) $filter->assignee);
        }
        foreach (['tracker' => $filter->trackerId, 'priority' => $filter->priorityId, 'fixedVersion' => $filter->versionId] as $field => $value) {
            if (null !== $value) {
                $qb->andWhere('IDENTITY(i.'.$field.') = :f_'.$field)->setParameter('f_'.$field, $value);
            }
        }
    }

    private function order(QueryBuilder $qb, Board $board): void
    {
        switch ($board->option('sort')) {
            case 'priority':
                $qb->orderBy('pr.position', 'DESC')->addOrderBy('i.id', 'ASC');
                break;
            case 'due_date':
                $qb->addSelect('CASE WHEN i.dueDate IS NULL THEN 1 ELSE 0 END AS HIDDEN no_due')
                    ->orderBy('no_due', 'ASC')->addOrderBy('i.dueDate', 'ASC')->addOrderBy('i.id', 'ASC');
                break;
            case 'updated_on':
                $qb->orderBy('i.updatedOn', 'DESC')->addOrderBy('i.id', 'DESC');
                break;
            default:
                $this->manualOrder($qb, $board);
        }
    }

    /** Cards somebody placed, in that order; then the ones never moved, oldest first (new cards arrive at the bottom) */
    private function manualOrder(QueryBuilder $qb, Board $board): void
    {
        $qb->leftJoin(CardPosition::class, 'cp', 'WITH', 'cp.boardId = :position_board AND cp.issueId = i.id')
            ->setParameter('position_board', (int) $board->id)
            ->addSelect('CASE WHEN cp.position IS NULL THEN 1 ELSE 0 END AS HIDDEN unplaced', 'cp.position AS HIDDEN placed_at')
            ->orderBy('unplaced', 'ASC')->addOrderBy('placed_at', 'ASC')->addOrderBy('i.id', 'ASC');
    }
}

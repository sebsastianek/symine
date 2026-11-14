<?php

declare(strict_types=1);

namespace Symine\Kanban\Board;

use App\Entity\IssueStatus;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symine\Kanban\Entity\Board;

/**
 * What a board covers: its projects (the selected ones, their subprojects when asked for), their trackers and the
 * issue statuses those trackers can be in. This is what the status mapping has to account for.
 */
final class BoardScope implements ResetInterface
{
    /** @var array<string, list<Project>> */
    private array $projects = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly IssueService $issues,
    ) {
    }

    /** The projects kept here belong to the entity manager of the request: forgotten with it */
    public function reset(): void
    {
        $this->projects = [];
    }

    /**
     * The projects of the board that the user can see, in tree order. A board without selected projects covers the
     * project it belongs to, or every visible project when it is above the projects.
     *
     * @return list<Project>
     */
    public function projects(Board $board, ?User $user = null): array
    {
        $user ??= $this->currentUser->get();
        // by what decides the answer, not by the object: boards are edited within a request and reloaded between requests
        $key = json_encode([$user->id ?? 0, $board->projectIds, $board->projectId, $board->includeSubprojects]);
        if (isset($this->projects[$key])) {
            return $this->projects[$key];
        }
        $visible = $this->auth->visibleProjectIds($user);
        if ([] === $visible) {
            return $this->projects[$key] = [];
        }
        $selected = array_values(array_map('intval', $board->projectIds ?? []));
        if ([] === $selected && null !== $board->projectId) {
            $selected = [$board->projectId];
        }

        $qb = $this->em->createQueryBuilder()->select('p')->from(Project::class, 'p')
            ->where('p.id IN (:visible)')->andWhere('p.status <> :archived')
            ->setParameter('visible', $visible)->setParameter('archived', Project::STATUS_ARCHIVED)
            ->orderBy('p.lft', 'ASC')->addOrderBy('p.name', 'ASC');
        if ([] !== $selected) {
            if ($board->includeSubprojects) {
                // a project and everything below it: the nested set of Redmine's projects table
                $qb->andWhere('EXISTS (SELECT 1 FROM '.Project::class.' root WHERE root.id IN (:selected) AND p.lft >= root.lft AND p.rgt <= root.rgt)');
            } else {
                $qb->andWhere('p.id IN (:selected)');
            }
            $qb->setParameter('selected', $selected);
        }

        return $this->projects[$key] = $qb->getQuery()->getResult();
    }

    /** @return list<int> */
    public function projectIds(Board $board, ?User $user = null): array
    {
        return array_values(array_map(static fn (Project $p) => (int) $p->id, $this->projects($board, $user)));
    }

    /**
     * The trackers of the projects of the board, limited to the ones the board selects.
     *
     * @return list<Tracker>
     */
    public function trackers(Board $board, ?User $user = null): array
    {
        $trackers = [];
        foreach ($this->projects($board, $user) as $project) {
            foreach ($project->trackers as $tracker) {
                $trackers[$tracker->id] = $tracker;
            }
        }
        $only = array_map('intval', $board->trackerIds ?? []);
        if ([] !== $only) {
            $trackers = array_filter($trackers, static fn (Tracker $t) => \in_array($t->id, $only, true));
        }
        usort($trackers, static fn (Tracker $a, Tracker $b) => [$a->position, $a->id] <=> [$b->position, $b->id]);

        return array_values($trackers);
    }

    /** @return list<IssueStatus> every issue status, in Redmine's order */
    public function allStatuses(): array
    {
        return $this->em->getRepository(IssueStatus::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The statuses an issue of the board can be in: the ones the workflows of its trackers use, and the ones its
     * issues are in right now (a workflow may have changed since).
     *
     * @return list<int>
     */
    public function relevantStatusIds(Board $board, ?User $user = null): array
    {
        $ids = [];
        foreach ($this->trackers($board, $user) as $tracker) {
            foreach ($this->issues->trackerStatusIds($tracker) as $id) {
                $ids[(int) $id] = true;
            }
        }
        $projectIds = $this->projectIds($board, $user);
        if ([] !== $projectIds) {
            $qb = $this->em->createQueryBuilder()->select('DISTINCT IDENTITY(i.status)')->from(\App\Entity\Issue::class, 'i')
                ->where('i.project IN (:projects)')->setParameter('projects', $projectIds);
            $only = array_map('intval', $board->trackerIds ?? []);
            if ([] !== $only) {
                $qb->andWhere('i.tracker IN (:trackers)')->setParameter('trackers', $only);
            }
            foreach ($qb->getQuery()->getSingleColumnResult() as $id) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Statuses the board would silently lose track of: relevant, in no column and not hidden on purpose.
     * Issues in these statuses are shown in the "Unmapped" column.
     *
     * @return list<int>
     */
    public function unmappedStatusIds(Board $board, ?User $user = null): array
    {
        $known = [...array_keys($board->columnsByStatus()), ...$board->hiddenStatusIds()];

        return array_values(array_diff($this->relevantStatusIds($board, $user), $known));
    }
}

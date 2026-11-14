<?php

declare(strict_types=1);

namespace Symine\Kanban\Card;

use App\Entity\Attachment;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\User;
use App\Security\Authorization;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the small marks at the bottom of a card say: how many comments, files and subtasks the issue has, and the
 * picture shown as its cover. Counted for all the cards of a board at once (four queries, whatever their number),
 * and only over what the user may see: private comments and invisible subtasks are left out.
 */
final class CardBadges
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly IssueService $issues,
    ) {
    }

    /**
     * @param list<Issue> $issues
     *
     * @return array<int, array{comments: int, attachments: int, subtasks: int, subtasks_closed: int, cover: ?int}> by issue id
     */
    public function of(array $issues, User $user, bool $withCover = false): array
    {
        $badges = [];
        $privateOk = [];
        $projectAllows = [];
        foreach ($issues as $issue) {
            $badges[(int) $issue->id] = ['comments' => 0, 'attachments' => 0, 'subtasks' => 0, 'subtasks_closed' => 0, 'cover' => null];
            $projectId = (int) $issue->project->id;
            $projectAllows[$projectId] ??= $this->auth->allowedTo('view_private_notes', $issue->project, $user);
            if ($projectAllows[$projectId]) {
                $privateOk[] = (int) $issue->id;
            }
        }
        $ids = array_keys($badges);
        if ([] === $ids) {
            return [];
        }

        $comments = $this->em->createQueryBuilder()->select('IDENTITY(j.issue) AS id', 'COUNT(j.id) AS n')->from(Journal::class, 'j')
            ->where('j.issue IN (:ids)')->andWhere("j.journalizedType = 'Issue'")->andWhere("j.notes IS NOT NULL AND j.notes <> ''")
            ->andWhere('j.privateNotes = false OR j.issue IN (:private) OR j.user = :user')
            ->setParameter('ids', $ids)->setParameter('private', $privateOk ?: [0])->setParameter('user', (int) ($user->id ?? 0))
            ->groupBy('j.issue')->getQuery()->getArrayResult();
        foreach ($comments as $row) {
            $badges[(int) $row['id']]['comments'] = (int) $row['n'];
        }

        $files = $this->em->createQueryBuilder()->select('a.containerId AS id', 'COUNT(a.id) AS n')->from(Attachment::class, 'a')
            ->where("a.containerType = 'Issue'")->andWhere('a.containerId IN (:ids)')->setParameter('ids', $ids)
            ->groupBy('a.containerId')->getQuery()->getArrayResult();
        foreach ($files as $row) {
            $badges[(int) $row['id']]['attachments'] = (int) $row['n'];
        }

        $children = $this->em->createQueryBuilder()
            ->select('IDENTITY(c.parent) AS id', 'COUNT(c.id) AS n', 'SUM(CASE WHEN s.isClosed = true THEN 1 ELSE 0 END) AS closed')
            ->from(Issue::class, 'c')->join('c.status', 's')
            ->where('c.parent IN (:ids)')->andWhere($this->issues->visibleCondition($user, 'c'))->setParameter('ids', $ids)
            ->groupBy('c.parent')->getQuery()->getArrayResult();
        foreach ($children as $row) {
            $badges[(int) $row['id']]['subtasks'] = (int) $row['n'];
            $badges[(int) $row['id']]['subtasks_closed'] = (int) $row['closed'];
        }

        if ($withCover) {
            $images = $this->em->createQueryBuilder()->select('a.id', 'a.containerId AS issue_id')->from(Attachment::class, 'a')
                ->where("a.containerType = 'Issue'")->andWhere('a.containerId IN (:ids)')->andWhere("a.contentType LIKE 'image/%'")
                ->setParameter('ids', $ids)->orderBy('a.id', 'ASC')->getQuery()->getArrayResult();
            foreach ($images as $row) {
                // the first picture attached to the issue
                $badges[(int) $row['issue_id']]['cover'] ??= (int) $row['id'];
            }
        }

        return $badges;
    }
}

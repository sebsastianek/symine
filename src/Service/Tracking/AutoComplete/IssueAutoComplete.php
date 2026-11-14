<?php

declare(strict_types=1);

namespace App\Service\Tracking\AutoComplete;

use App\Entity\Issue;
use App\Entity\Project;
use App\Query\Tokenizer;
use App\Service\IssueService;
use App\Service\ProjectService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The issues offered when an issue id or subject is typed (AutoCompletesController#issues): parent task,
 * related issue, "#" in a text area.
 */
final class IssueAutoComplete
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IssueService $issues,
        private readonly ProjectService $projects,
    ) {
    }

    /**
     * Parameters: q (or term), project_id, scope, status ("o" for open issues), issue_id (an issue to leave out).
     *
     * @return list<array{id: ?int, label: string, value: ?int}>
     *
     * @throws NotFoundHttpException when the project does not exist
     */
    public function suggest(Request $request): array
    {
        $project = null;
        if ($pid = $request->query->get('project_id')) {
            $project = $this->projects->findByIdentifierOrId((string) $pid) ?? throw new NotFoundHttpException();
        }
        $q = trim((string) ($request->query->get('q') ?? $request->query->get('term') ?? ''));
        $status = (string) $request->query->get('status', '');
        $issueId = (string) $request->query->get('issue_id', '');

        $qb = $this->em->createQueryBuilder()->select('i', 't')->from(Issue::class, 'i')->join('i.tracker', 't')->join('i.status', 's')->join('i.project', 'p')
            ->where($this->issues->visibleCondition(null, 'i'));
        $this->crossProjectScope($qb, $project, (string) $request->query->get('scope', ''));
        if ('' !== $status) {
            $qb->andWhere('s.isClosed = :closed')->setParameter('closed', 'o' !== $status);
        }
        if ('' !== $issueId) {
            $qb->andWhere('i.id <> :exclude')->setParameter('exclude', (int) $issueId);
        }
        $issues = [];
        if ('' !== $q) {
            if (preg_match('/^#?(\d+)$/', $q, $m)) {
                $byId = (clone $qb)->andWhere('i.id = :id')->setParameter('id', (int) $m[1])->getQuery()->getOneOrNullResult();
                if ($byId) {
                    $issues[] = $byId;
                }
            }
            // Issue.like: Query.tokenized_like_conditions, every word (or "quoted phrase") must be found in the subject
            $likeQb = clone $qb;
            foreach ((new Tokenizer($q))->tokens() ?: [$q] as $n => $token) {
                $likeQb->andWhere("LOWER(i.subject) LIKE :q$n ESCAPE '!'")->setParameter("q$n", '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($token)).'%');
            }
            $like = $likeQb->orderBy('i.id', 'DESC')->setMaxResults(10)->getQuery()->getResult();
            foreach ($like as $i) {
                if (!\in_array($i, $issues, true)) {
                    $issues[] = $i;
                }
            }
        } else {
            $issues = $qb->orderBy('i.id', 'DESC')->setMaxResults(10)->getQuery()->getResult();
        }

        return array_map(static fn (Issue $i) => [
            'id' => $i->id,
            'label' => $i->tracker->name.' #'.$i->id.': '.mb_strimwidth($i->subject, 0, 255, '...'),
            'value' => $i->id,
        ], $issues);
    }

    /** Issue.cross_project_scope */
    private function crossProjectScope(QueryBuilder $qb, ?Project $project, string $scope): void
    {
        if (null === $project) {
            return;
        }
        switch ($scope) {
            case 'all':
            case 'system':
                return;
            case 'tree':
                $root = $project->getRoot();
                $qb->andWhere('p.lft >= :rl AND p.rgt <= :rr')->setParameter('rl', $root->lft)->setParameter('rr', $root->rgt);

                return;
            case 'hierarchy':
                $qb->andWhere('(p.lft >= :l AND p.rgt <= :r) OR (p.lft < :l AND p.rgt > :r)')->setParameter('l', $project->lft)->setParameter('r', $project->rgt);

                return;
            case 'descendants':
                $qb->andWhere('p.lft >= :l AND p.rgt <= :r')->setParameter('l', $project->lft)->setParameter('r', $project->rgt);

                return;
            default:
                $qb->andWhere('p.id = :pid')->setParameter('pid', $project->id);
        }
    }
}

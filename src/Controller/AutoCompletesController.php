<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Issue;
use App\Entity\Project;
use App\Service\IssueService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's AutoCompletesController#issues (issue id / subject autocompletion). */
final class AutoCompletesController extends AbstractController
{
    public function __construct(private readonly IssueService $issues)
    {
    }

    #[Route('/issues/auto_complete', name: 'issues_auto_complete', methods: ['GET'])]
    public function issues(Request $request): JsonResponse
    {
        $project = null;
        if ($pid = $request->query->get('project_id')) {
            $project = $this->projects->findByIdentifierOrId((string) $pid) ?? throw $this->createNotFoundException();
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
            $like = (clone $qb)->andWhere('LOWER(i.subject) LIKE :q')->setParameter('q', '%'.mb_strtolower($q).'%')
                ->orderBy('i.id', 'DESC')->setMaxResults(10)->getQuery()->getResult();
            foreach ($like as $i) {
                if (!\in_array($i, $issues, true)) {
                    $issues[] = $i;
                }
            }
        } else {
            $issues = $qb->orderBy('i.id', 'DESC')->setMaxResults(10)->getQuery()->getResult();
        }

        return new JsonResponse(array_map(static fn (Issue $i) => [
            'id' => $i->id,
            'label' => $i->tracker->name.' #'.$i->id.': '.mb_strimwidth($i->subject, 0, 255, '...'),
            'value' => $i->id,
        ], $issues));
    }

    /** AutoCompletesController#wiki_pages ([[ autocompletion in wiki textareas). */
    #[Route('/wiki_pages/auto_complete', name: 'wiki_pages_auto_complete', methods: ['GET'])]
    public function wikiPages(Request $request): JsonResponse
    {
        $project = null;
        if ('' !== ($pid = (string) $request->query->get('project_id', ''))) {
            $project = $this->projects->findByIdentifierOrId($pid) ?? throw $this->createNotFoundException();
        }
        $wiki = null === $project ? null : $this->em->getRepository(\App\Entity\Wiki::class)->findOneBy(['project' => $project]);
        if (null === $wiki || !$this->auth->allowedTo('view_wiki_pages', $project)) {
            return new JsonResponse([]);
        }
        $q = trim((string) $request->query->get('q', ''));
        $qb = $this->em->createQueryBuilder()->select('wp')->from(\App\Entity\WikiPage::class, 'wp')
            ->where('wp.wiki = :wiki')->setParameter('wiki', $wiki)->orderBy('wp.id', 'DESC')->setMaxResults(10);
        if ('' !== $q) {
            $qb->andWhere('LOWER(wp.title) LIKE :q')->setParameter('q', '%'.mb_strtolower($q).'%');
        }

        return new JsonResponse(array_map(static fn (\App\Entity\WikiPage $p) => [
            'id' => $p->id,
            'label' => mb_strlen($p->title) > 255 ? mb_substr($p->title, 0, 252).'...' : $p->title,
            'value' => $p->title,
        ], $qb->getQuery()->getResult()));
    }

    /** Issue.cross_project_scope */
    private function crossProjectScope(\Doctrine\ORM\QueryBuilder $qb, ?Project $project, string $scope): void
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

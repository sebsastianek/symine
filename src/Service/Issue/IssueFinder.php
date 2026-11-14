<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Issue;
use App\Entity\Project;
use App\Security\AccessGuard;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads the issues an action works on (find_issue / find_issues of Redmine's ApplicationController): 404 when
 * one does not exist, access denied when one is not visible by the user.
 */
final class IssueFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IssueService $issues,
        private readonly AccessGuard $guard,
    ) {
    }

    public function find(int $id): Issue
    {
        $issue = $this->em->find(Issue::class, $id);
        if (null === $issue) {
            throw new NotFoundHttpException();
        }
        if (!$this->issues->isVisible($issue)) {
            $this->guard->deny();
        }

        return $issue;
    }

    /**
     * The issues named by the "ids" (or "id") parameter of a bulk action, by id.
     *
     * @return list<Issue>
     */
    public function fromRequest(Request $request): array
    {
        $ids = IssueParams::get($request, 'ids') ?? IssueParams::get($request, 'id');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        if ([] === $ids) {
            throw new NotFoundHttpException();
        }
        $issues = $this->em->getRepository(Issue::class)->findBy(['id' => $ids], ['id' => 'ASC']);
        if (\count($issues) !== \count($ids)) {
            throw new NotFoundHttpException();
        }
        foreach ($issues as $i) {
            if (!$this->issues->isVisible($i)) {
                $this->guard->deny();
            }
        }

        return $issues;
    }

    /**
     * The projects of the issues, without duplicates.
     *
     * @param list<Issue> $issues
     *
     * @return list<Project>
     */
    public static function projectsOf(array $issues): array
    {
        $out = [];
        foreach ($issues as $i) {
            $out[$i->project->id] = $i->project;
        }

        return array_values($out);
    }
}

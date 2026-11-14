<?php

declare(strict_types=1);

namespace App\Service\Tracking\ContextMenu;

use App\Entity\Issue;
use App\Security\AccessGuard;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads the issues named by the ids (or id) parameter of a context menu request: ApplicationController#find_issues
 * (404 when there is none, 403 when one of them is not visible).
 */
final class IssueSelectionFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IssueService $issues,
        private readonly AccessGuard $guard,
    ) {
    }

    public function find(Request $request): IssueSelection
    {
        $params = array_replace($request->query->all(), $request->request->all());
        $ids = $params['ids'] ?? $params['id'] ?? [];
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        $issues = [] === $ids ? [] : $this->em->getRepository(Issue::class)->findBy(['id' => $ids]);
        if ([] === $issues) {
            throw new NotFoundHttpException();
        }
        foreach ($issues as $i) {
            if (!$this->issues->isVisible($i)) {
                $this->guard->deny();
            }
        }

        return new IssueSelection($issues);
    }
}

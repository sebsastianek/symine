<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\IssueRelation;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads the issue relation an action works on: 404 when it does not exist, access denied unless the user can
 * see both issues.
 */
final class IssueRelationFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly AccessGuard $guard,
        private readonly IssueService $issues,
    ) {
    }

    public function find(int $id): IssueRelation
    {
        $relation = $this->em->find(IssueRelation::class, $id) ?? throw new NotFoundHttpException();
        if (!$this->issues->isVisible($relation->issueFrom) || !$this->issues->isVisible($relation->issueTo)) {
            $this->guard->deny();
        }

        return $relation;
    }

    /** A relation the user may delete: he also manages the relations of one of the two issues */
    public function findDeletable(int $id): IssueRelation
    {
        $relation = $this->find($id);
        if (!$this->auth->allowedTo('manage_issue_relations', $relation->issueFrom->project) && !$this->auth->allowedTo('manage_issue_relations', $relation->issueTo->project)) {
            $this->guard->deny();
        }

        return $relation;
    }
}

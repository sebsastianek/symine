<?php

declare(strict_types=1);

namespace App\Service\Admin\IssueStatuses;

use App\Entity\IssueStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Loads the issue status an administration action works on. */
final class IssueStatusFinder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** The issue status of the route (404 when there is none) */
    public function find(int $id): IssueStatus
    {
        return $this->em->find(IssueStatus::class, $id) ?? throw new NotFoundHttpException('Not Found');
    }
}

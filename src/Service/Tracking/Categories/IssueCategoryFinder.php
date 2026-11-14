<?php

declare(strict_types=1);

namespace App\Service\Tracking\Categories;

use App\Entity\IssueCategory;
use App\Security\AccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads an issue category of a project the user can see (find_model_object + find_project_from_association).
 */
final class IssueCategoryFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessGuard $guard,
    ) {
    }

    /** 404 when there is no such category, 403 when its project is not visible */
    public function find(int $id): IssueCategory
    {
        $category = $this->em->find(IssueCategory::class, $id);
        if (null === $category) {
            throw new NotFoundHttpException();
        }
        $this->guard->findProject((int) $category->project->id);

        return $category;
    }
}

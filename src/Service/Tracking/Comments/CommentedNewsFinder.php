<?php

declare(strict_types=1);

namespace App\Service\Tracking\Comments;

use App\Entity\News;
use App\Security\AccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads the news a comment is added to or removed from (find_model_object + find_project_from_association).
 */
final class CommentedNewsFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessGuard $guard,
    ) {
    }

    /** 404 when there is no such news, 403 when its project is not visible */
    public function find(int $id): News
    {
        $news = $this->em->find(News::class, $id);
        if (null === $news || null === $news->project) {
            throw new NotFoundHttpException();
        }
        $this->guard->findProject((int) $news->project->id);

        return $news;
    }
}

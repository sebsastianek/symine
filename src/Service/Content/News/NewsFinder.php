<?php

declare(strict_types=1);

namespace App\Service\Content\News;

use App\Api\ApiRenderer;
use App\Entity\Comment;
use App\Entity\News;
use App\Security\AccessGuard;
use App\Security\ApiRequestMatcher;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads what the news actions work on: a news item of a visible project, its comments, and the project a
 * news item posted without a project in its URL is meant for.
 */
final class NewsFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessGuard $guard,
        private readonly CurrentUser $currentUser,
    ) {
    }

    public function find(int $id): News
    {
        $news = $this->em->find(News::class, $id);
        if (null === $news || null === $news->project) {
            throw new NotFoundHttpException();
        }
        // find_project_from_association: checks the project is visible / not archived
        $this->guard->findProject((int) $news->project->id);

        return $news;
    }

    /** The project_id parameter of POST /news, which has no project in its URL (404 without one) */
    public function submittedProjectId(Request $request): string
    {
        $projectId = $request->request->get('project_id') ?? $request->query->get('project_id');
        if ((null === $projectId || '' === $projectId) && ApiRequestMatcher::isApiRequest($request)) {
            // JSON/XML body parameters are merged into params like Rails does
            $projectId = ApiRenderer::payload($request)['project_id'] ?? null;
            $projectId = \is_scalar($projectId) ? (string) $projectId : null;
        }
        if (null === $projectId || '' === $projectId) {
            throw new NotFoundHttpException();
        }

        return (string) $projectId;
    }

    /** @return list<Comment> oldest first, or newest first when the user prefers so */
    public function comments(News $news): array
    {
        $comments = $this->em->getRepository(Comment::class)->findBy(['commentedType' => 'News', 'commentedId' => $news->id], ['createdOn' => 'ASC', 'id' => 'ASC']);
        $user = $this->currentUser->get();
        if ($user->isLogged() && 'desc' === $user->getPref()->commentsSorting()) {
            $comments = array_reverse($comments);
        }

        return $comments;
    }
}

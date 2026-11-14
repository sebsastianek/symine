<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Tracking\Comments\CommentedNewsFinder;
use App\Service\Tracking\Comments\NewsComments;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of CommentsController (news comments). */
final class CommentsController extends AbstractController
{
    public function __construct(
        private readonly CommentedNewsFinder $finder,
        private readonly NewsComments $comments,
    ) {
    }

    #[Route('/news/{id}/comments', name: 'news_comment_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function create(Request $request, int $id): Response
    {
        $news = $this->finder->find($id);
        $this->authorize('comment_news', $news->project);
        if ($this->comments->add($news, $this->user(), $request->request->all('comment')['comments'] ?? '')) {
            $this->flashNotice('label_comment_added');
        }

        return $this->redirectToRoute('news_show', ['id' => $news->id]);
    }

    #[Route('/news/{id}/comments/{comment_id}', name: 'news_comment_destroy', methods: ['DELETE'], requirements: ['id' => '\d+', 'comment_id' => '\d+'])]
    public function destroy(int $id, int $comment_id): Response
    {
        $news = $this->finder->find($id);
        $this->authorize('manage_news', $news->project);
        $this->comments->remove($news, $comment_id, $this->user());

        return $this->redirectToRoute('news_show', ['id' => $news->id]);
    }
}

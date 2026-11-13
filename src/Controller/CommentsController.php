<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\News;
use App\Event\ContentEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of CommentsController (news comments). */
final class CommentsController extends AbstractController
{
    public function __construct(private readonly EventDispatcherInterface $events)
    {
    }

    #[Route('/news/{id}/comments', name: 'news_comment_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function create(Request $request, int $id): Response
    {
        $news = $this->findNews($id);
        $this->authorize('comment_news', $news->project);
        $content = $request->request->all('comment')['comments'] ?? '';
        if (\is_string($content) && '' !== trim($content)) {
            $comment = new Comment();
            $comment->commentedType = 'News';
            $comment->commentedId = (int) $news->id;
            $comment->author = $this->user();
            $comment->content = $content;
            $this->em->persist($comment);
            // counter_cache
            ++$news->commentsCount;
            $this->em->flush();
            $this->events->dispatch(new ContentEvent('news_comment', 'created', $comment, $this->user(), ['news' => $news]));
            $this->flashNotice('label_comment_added');
        }

        return $this->redirectToRoute('news_show', ['id' => $news->id]);
    }

    #[Route('/news/{id}/comments/{comment_id}', name: 'news_comment_destroy', methods: ['DELETE'], requirements: ['id' => '\d+', 'comment_id' => '\d+'])]
    public function destroy(int $id, int $comment_id): Response
    {
        $news = $this->findNews($id);
        $this->authorize('manage_news', $news->project);
        $comment = $this->em->getRepository(Comment::class)->findOneBy(['id' => $comment_id, 'commentedType' => 'News', 'commentedId' => $news->id]);
        if (null === $comment) {
            throw $this->createNotFoundException();
        }
        $this->events->dispatch(new ContentEvent('news_comment', 'deleted', $comment, $this->user(), ['news' => $news]));
        $this->em->remove($comment);
        $news->commentsCount = max(0, $news->commentsCount - 1);
        $this->em->flush();

        return $this->redirectToRoute('news_show', ['id' => $news->id]);
    }

    private function findNews(int $id): News
    {
        $news = $this->em->find(News::class, $id);
        if (null === $news || null === $news->project) {
            throw $this->createNotFoundException();
        }
        $this->findProject((int) $news->project->id);

        return $news;
    }
}

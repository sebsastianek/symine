<?php

declare(strict_types=1);

namespace App\Service\Tracking\Comments;

use App\Entity\Comment;
use App\Entity\News;
use App\Entity\User;
use App\Event\ContentEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The comments of a news: adding and removing them, with the count kept on the news and the events that
 * notify of them.
 */
final class NewsComments
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * @param mixed $content the submitted comment[comments]
     *
     * @return bool false when there is no text: nothing is added
     */
    public function add(News $news, User $author, mixed $content): bool
    {
        if (!\is_string($content) || '' === trim($content)) {
            return false;
        }
        $comment = new Comment();
        $comment->commentedType = 'News';
        $comment->commentedId = (int) $news->id;
        $comment->author = $author;
        $comment->content = $content;
        $this->em->persist($comment);
        // counter_cache
        ++$news->commentsCount;
        $this->em->flush();
        $this->events->dispatch(new ContentEvent('news_comment', 'created', $comment, $author, ['news' => $news]));

        return true;
    }

    /** @throws NotFoundHttpException when the news has no such comment */
    public function remove(News $news, int $commentId, User $user): void
    {
        $comment = $this->em->getRepository(Comment::class)->findOneBy(['id' => $commentId, 'commentedType' => 'News', 'commentedId' => $news->id]);
        if (null === $comment) {
            throw new NotFoundHttpException();
        }
        $this->events->dispatch(new ContentEvent('news_comment', 'deleted', $comment, $user, ['news' => $news]));
        // has_many :reactions, dependent: :delete_all
        $this->em->createQuery('DELETE FROM App\Entity\Reaction r WHERE r.reactableType = :t AND r.reactableId = :id')->setParameters(['t' => 'Comment', 'id' => $comment->id])->execute();
        $this->em->remove($comment);
        $news->commentsCount = max(0, $news->commentsCount - 1);
        $this->em->flush();
    }
}

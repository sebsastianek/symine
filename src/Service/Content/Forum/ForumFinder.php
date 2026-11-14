<?php

declare(strict_types=1);

namespace App\Service\Content\Forum;

use App\Entity\Board;
use App\Entity\Message;
use App\Entity\Project;
use App\Security\AccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads the forum and the message an action works on (404 when they do not exist or do not belong together).
 */
final class ForumFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessGuard $guard,
    ) {
    }

    /** The forum of the project with this id */
    public function boardOf(Project $project, int $id): Board
    {
        $board = $this->em->getRepository(Board::class)->findOneBy(['id' => $id, 'project' => $project]);
        if (null === $board) {
            throw new NotFoundHttpException();
        }

        return $board;
    }

    /** The forum with this id, when its project is visible (403 otherwise) */
    public function board(int $id): Board
    {
        $board = $this->em->find(Board::class, $id);
        if (null === $board) {
            throw new NotFoundHttpException();
        }
        $this->guard->findProject((int) $board->project->id);

        return $board;
    }

    /** The message of the forum with this id */
    public function message(Board $board, int $id): Message
    {
        $message = $this->em->getRepository(Message::class)->findOneBy(['id' => $id, 'board' => $board]);
        if (null === $message) {
            throw new NotFoundHttpException();
        }

        return $message;
    }
}

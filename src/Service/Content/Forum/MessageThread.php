<?php

declare(strict_types=1);

namespace App\Service\Content\Forum;

use App\Entity\Message;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\ForumService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the page of a forum topic shows: the topic, a page of its replies (the one of the reply named by the
 * "r" parameter when there is one), what the user may do with each of them and the reply form.
 */
final class MessageThread
{
    public const int REPLIES_PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ForumService $forums,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
    ) {
    }

    /** @return array<string, mixed> */
    public function view(Message $message, Request $request): array
    {
        $board = $message->board;
        $topic = $this->forums->root($message);

        $page = $request->query->get('page');
        $replyCount = (int) $this->em->createQuery('SELECT COUNT(m.id) FROM App\Entity\Message m WHERE m.parent = :t')->setParameter('t', $topic)->getSingleScalarResult();
        if ($request->query->has('r') && null === $page) {
            $offset = (int) $this->em->createQuery('SELECT COUNT(m.id) FROM App\Entity\Message m WHERE m.parent = :t AND m.id < :r')
                ->setParameters(['t' => $topic, 'r' => (int) $request->query->get('r')])->getSingleScalarResult();
            $page = 1 + intdiv($offset, self::REPLIES_PER_PAGE);
        }
        $pages = max(1, (int) ceil($replyCount / self::REPLIES_PER_PAGE));
        $page = max(1, min($pages, (int) ($page ?? 1)));
        $offset = ($page - 1) * self::REPLIES_PER_PAGE;
        $replies = $this->em->createQuery('SELECT m, a FROM App\Entity\Message m LEFT JOIN m.author a WHERE m.parent = :t ORDER BY m.createdOn ASC, m.id ASC')
            ->setParameter('t', $topic)->setFirstResult($offset)->setMaxResults(self::REPLIES_PER_PAGE)->getResult();

        $reply = new Message();
        $reply->board = $board;
        $reply->subject = 'RE: '.$message->subject;

        $user = $this->currentUser->get();
        $project = $topic->board->project;
        $editable = [];
        $destroyable = [];
        foreach ([$topic, ...$replies] as $m) {
            $editable[$m->id] = $this->forums->editableBy($m, $user);
            $destroyable[$m->id] = $this->forums->destroyableBy($m, $user);
        }

        return [
            'project' => $project,
            'board' => $topic->board,
            'ancestors' => $this->forums->ancestors($topic->board),
            'topic' => $topic,
            'message' => $message,
            'replies' => $replies,
            'reply' => $reply,
            'errors' => [],
            'reply_pager' => ['page' => $page, 'pages' => $pages, 'count' => $replyCount, 'offset' => $offset, 'limit' => self::REPLIES_PER_PAGE],
            'editable' => $editable,
            'destroyable' => $destroyable,
            'can_reply' => !$topic->locked && $this->auth->allowedTo('add_messages', $project),
        ];
    }
}

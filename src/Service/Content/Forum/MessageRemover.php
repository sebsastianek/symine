<?php

declare(strict_types=1);

namespace App\Service\Content\Forum;

use App\Entity\Message;
use App\Event\ContentEvent;
use App\Security\CurrentUser;
use App\Service\ForumService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Deletes a forum message with its replies, after telling the listeners (webhooks) while it still exists.
 */
final class MessageRemover
{
    public function __construct(
        private readonly ForumService $forums,
        private readonly CurrentUser $currentUser,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function remove(Message $message): void
    {
        $this->dispatcher->dispatch(new ContentEvent('message', 'deleted', $message, $this->currentUser->get(), ['board' => $message->board, 'parent' => $message->parent]));
        $this->forums->destroyMessage($message);
    }
}

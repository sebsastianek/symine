<?php

declare(strict_types=1);

namespace App\Service\Content\Forum;

use App\Entity\Board;
use App\Entity\Message;
use App\Event\ContentEvent;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\Content\Submission;
use App\Service\ForumService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The forms of a forum message: a new topic and the edition of a message (message[subject], message[content],
 * message[sticky], message[locked], message[board_id]) and a reply (reply[subject], reply[content]), with their
 * files (attachments[..]).
 */
final class MessageForm
{
    public function __construct(
        private readonly ForumService $forums,
        private readonly AttachmentService $attachments,
        private readonly CurrentUser $currentUser,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /** A new topic of the forum by the current user, filled with the message[..] parameters of the request */
    public function build(Board $board, Request $request): Message
    {
        $message = new Message();
        $message->author = $this->currentUser->get();
        $message->board = $board;
        $this->fill($message, $request);

        return $message;
    }

    /** A new reply by the current user to the topic of the message */
    public function buildReply(Message $message): Message
    {
        $reply = new Message();
        $reply->author = $this->currentUser->get();
        $reply->board = $message->board;
        $reply->parent = $this->forums->root($message);

        return $reply;
    }

    /** Fills a message with the message[..] parameters of the request (those of the URL for a GET request) */
    public function fill(Message $message, Request $request): void
    {
        $this->forums->assignMessage($message, $request->isMethod('POST') ? $request->request->all('message') : $request->query->all('message'), $this->currentUser->get());
    }

    /** Saves a new topic (already filled, see build()) when it is valid */
    public function create(Message $message, Request $request): Submission
    {
        $user = $this->currentUser->get();
        [$attachments, $attErrors] = $this->attachments->fromRequest($request, $user);
        $errors = $this->forums->validateMessage($message, true);
        if ([] !== $errors) {
            return new Submission($errors);
        }
        $this->forums->createMessage($message);
        $this->attachments->attachTo($attachments, 'Message', (int) $message->id);
        $this->em->flush();
        $this->dispatcher->dispatch(new ContentEvent('message', 'created', $message, $user));

        return new Submission([], \count($attErrors));
    }

    /** Saves the reply submitted to a topic (see buildReply()) when it is valid */
    public function reply(Message $reply, Request $request): Submission
    {
        $user = $this->currentUser->get();
        $board = $reply->board;
        $this->forums->assignMessage($reply, $request->request->all('reply'), $user);
        // board, sticky and locked are not changed by a reply
        $reply->board = $board;
        $reply->locked = false;
        $reply->sticky = 0;
        [$attachments, $attErrors] = $this->attachments->fromRequest($request, $user);
        $errors = $this->forums->validateMessage($reply, true);
        if ([] !== $errors) {
            return new Submission($errors);
        }
        $this->forums->createMessage($reply);
        $this->attachments->attachTo($attachments, 'Message', (int) $reply->id);
        $this->em->flush();
        $this->dispatcher->dispatch(new ContentEvent('message', 'created', $reply, $user));

        return new Submission([], \count($attErrors));
    }

    /** Saves the changes submitted for a message when they are valid */
    public function update(Message $message, Request $request): Submission
    {
        $user = $this->currentUser->get();
        $previousBoardId = (int) $message->board->id;
        $this->fill($message, $request);
        [$attachments, $attErrors] = $this->attachments->fromRequest($request, $user);
        $errors = $this->forums->validateMessage($message, false);
        if ([] !== $errors) {
            return new Submission($errors);
        }
        $this->forums->updateMessage($message, $previousBoardId);
        $this->attachments->attachTo($attachments, 'Message', (int) $message->id);
        $this->em->flush();
        $this->dispatcher->dispatch(new ContentEvent('message', 'updated', $message, $user));

        return new Submission([], \count($attErrors));
    }

    /**
     * The form of a new topic.
     *
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    public function view(Message $message, array $errors = []): array
    {
        return [
            'project' => $message->board->project,
            'board' => $message->board,
            'message' => $message,
            'errors' => $errors,
            'can_sticky' => $this->forums->safeAttribute($message, 'sticky', $this->currentUser->get()),
        ];
    }

    /**
     * The form of an existing message.
     *
     * @param Board        $board  the forum the message was found in (the form may have moved it to another one)
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    public function editView(Board $board, Message $message, array $errors = []): array
    {
        return [
            'project' => $board->project,
            'board' => $board,
            'message' => $message,
            'topic' => $this->forums->root($message),
            'ancestors' => $this->forums->ancestors($message->board),
            'errors' => $errors,
            'replying' => null !== $message->parent,
            'can_sticky' => $this->forums->safeAttribute($message, 'sticky', $this->currentUser->get()),
            'board_options' => $this->forums->boardOptions($this->forums->boards($board->project)),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\Message;
use App\Event\ContentEvent;
use App\Service\AttachmentService;
use App\Service\ForumService;
use App\Service\TextFormatter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of MessagesController (forum topics and replies) */
final class MessagesController extends AbstractController
{
    public const int REPLIES_PER_PAGE = 25;

    public function __construct(
        private readonly ForumService $forums,
        private readonly AttachmentService $attachments,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly TextFormatter $formatter,
    ) {
    }

    #[Route('/boards/{board_id}/topics/{id}', name: 'board_message_show', requirements: ['board_id' => '\d+', 'id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, int $board_id, int $id): Response
    {
        [$board, $message] = $this->findMessage($board_id, $id);
        $project = $board->project;
        $this->authorize('view_messages', $project);
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

        return $this->renderShow($request, $topic, $message, $replies, $reply, [], [
            'page' => $page, 'pages' => $pages, 'count' => $replyCount, 'offset' => $offset, 'limit' => self::REPLIES_PER_PAGE,
        ]);
    }

    #[Route('/boards/{board_id}/topics/new', name: 'message_new', requirements: ['board_id' => '\d+'], methods: ['GET', 'POST'])]
    public function new(Request $request, int $board_id): Response
    {
        $board = $this->findBoard($board_id);
        $project = $board->project;
        $this->authorize('add_messages', $project);
        $user = $this->user();
        $message = new Message();
        $message->author = $user;
        $message->board = $board;
        $this->forums->assignMessage($message, $request->isMethod('POST') ? $request->request->all('message') : $request->query->all('message'), $user);
        $errors = [];
        if ($request->isMethod('POST')) {
            [$attachments, $attErrors] = $this->attachments->fromRequest($request, $user);
            $errors = $this->forums->validateMessage($message, true);
            if ([] === $errors) {
                $this->forums->createMessage($message);
                $this->attachments->attachTo($attachments, 'Message', (int) $message->id);
                $this->em->flush();
                $this->dispatcher->dispatch(new ContentEvent('message', 'created', $message, $user));
                $this->callHook('controller_messages_new_after_save', ['params' => $request->request->all(), 'message' => $message, 'project' => $board->project]);
                $this->attachmentWarning($attErrors);
                $this->flashNotice('notice_successful_create');

                return $this->redirectToRoute('board_message_show', ['board_id' => $board->id, 'id' => $message->id]);
            }
        }

        return $this->render('messages/new.html.twig', [
            'project' => $project,
            'board' => $board,
            'message' => $message,
            'errors' => $errors,
            'can_sticky' => $this->forums->safeAttribute($message, 'sticky', $user),
            'main_menu_selected' => 'boards',
            'page_title' => $this->l('label_message_new'),
            'body_css' => 'controller-messages action-new',
        ], new Response('', [] === $errors ? 200 : 422));
    }

    #[Route('/boards/{board_id}/topics/{id}/replies', name: 'message_reply', requirements: ['board_id' => '\d+', 'id' => '\d+'], methods: ['POST'])]
    public function reply(Request $request, int $board_id, int $id): Response
    {
        [$board, $message] = $this->findMessage($board_id, $id);
        $this->authorize('add_messages', $board->project);
        $topic = $this->forums->root($message);
        $user = $this->user();
        $reply = new Message();
        $reply->author = $user;
        $reply->board = $board;
        $reply->parent = $topic;
        $this->forums->assignMessage($reply, $request->request->all('reply'), $user);
        // board, sticky and locked are not changed by a reply
        $reply->board = $board;
        $reply->locked = false;
        $reply->sticky = 0;
        [$attachments, $attErrors] = $this->attachments->fromRequest($request, $user);
        $errors = $this->forums->validateMessage($reply, true);
        if ([] === $errors) {
            $this->forums->createMessage($reply);
            $this->attachments->attachTo($attachments, 'Message', (int) $reply->id);
            $this->em->flush();
            $this->dispatcher->dispatch(new ContentEvent('message', 'created', $reply, $user));
            $this->callHook('controller_messages_reply_after_save', ['params' => $request->request->all(), 'message' => $reply, 'project' => $reply->board->project]);
            $this->attachmentWarning($attErrors);
        }
        // Redmine redirects even when the reply is invalid
        $this->flashNotice('notice_successful_update');

        $params = ['board_id' => $board->id, 'id' => $topic->id];
        if (null !== $reply->id) {
            $params['r'] = $reply->id;
        }

        return $this->redirectToRoute('board_message_show', $params);
    }

    #[Route('/boards/{board_id}/topics/{id}/edit', name: 'message_edit', requirements: ['board_id' => '\d+', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $board_id, int $id): Response
    {
        [$board, $message] = $this->findMessage($board_id, $id);
        $this->requireViewable($board);
        $user = $this->user();
        if (!$this->forums->editableBy($message, $user)) {
            $this->deny();
        }
        $previousBoardId = (int) $message->board->id;
        $this->forums->assignMessage($message, $request->isMethod('POST') ? $request->request->all('message') : $request->query->all('message'), $user);
        $errors = [];
        if ($request->isMethod('POST')) {
            [$attachments, $attErrors] = $this->attachments->fromRequest($request, $user);
            $errors = $this->forums->validateMessage($message, false);
            if ([] === $errors) {
                $this->forums->updateMessage($message, $previousBoardId);
                $this->attachments->attachTo($attachments, 'Message', (int) $message->id);
                $this->em->flush();
                $this->dispatcher->dispatch(new ContentEvent('message', 'updated', $message, $user));
                $this->attachmentWarning($attErrors);
                $this->flashNotice('notice_successful_update');
                $root = $this->forums->root($message);
                $params = ['board_id' => $message->board->id, 'id' => $root->id];
                if (null !== $message->parent) {
                    $params['r'] = $message->id;
                }

                return $this->redirectToRoute('board_message_show', $params);
            }
        }
        $topic = $this->forums->root($message);

        return $this->render('messages/edit.html.twig', [
            'project' => $board->project,
            'board' => $board,
            'message' => $message,
            'topic' => $topic,
            'ancestors' => $this->forums->ancestors($message->board),
            'errors' => $errors,
            'replying' => null !== $message->parent,
            'can_sticky' => $this->forums->safeAttribute($message, 'sticky', $user),
            'board_options' => $this->forums->boardOptions($this->forums->boards($board->project)),
            'main_menu_selected' => 'boards',
            'page_title' => $topic->subject,
            'body_css' => 'controller-messages action-edit',
        ], new Response('', [] === $errors ? 200 : 422));
    }

    #[Route('/boards/{board_id}/topics/{id}/destroy', name: 'message_destroy', requirements: ['board_id' => '\d+', 'id' => '\d+'], methods: ['POST', 'DELETE'])]
    public function destroy(int $board_id, int $id): Response
    {
        [$board, $message] = $this->findMessage($board_id, $id);
        $this->requireViewable($board);
        $user = $this->user();
        if (!$this->forums->destroyableBy($message, $user)) {
            $this->deny();
        }
        $parent = $message->parent;
        $r = $message->id;
        $this->dispatcher->dispatch(new ContentEvent('message', 'deleted', $message, $user, ['board' => $board, 'parent' => $parent]));
        $this->forums->destroyMessage($message);
        $this->flashNotice('notice_successful_delete');
        if (null !== $parent) {
            return $this->redirectToRoute('board_message_show', ['board_id' => $board->id, 'id' => $parent->id, 'r' => $r]);
        }

        return $this->redirectToRoute('board_show', ['project_id' => $board->project->identifier, 'id' => $board->id]);
    }

    #[Route('/boards/{board_id}/topics/quote/{id}.{_format}', name: 'message_quote', requirements: ['board_id' => '\d+', 'id' => '\d+', '_format' => 'js|html'], defaults: ['_format' => 'html'], methods: ['GET', 'POST'])]
    public function quote(Request $request, int $board_id, int $id, string $_format = 'html'): Response
    {
        [$board, $message] = $this->findMessage($board_id, $id);
        $this->authorize('add_messages', $board->project);
        if ('js' !== $_format) {
            throw $this->createNotFoundException();
        }
        $subject = $message->subject;
        if (!str_starts_with($subject, 'RE:')) {
            $subject = 'RE: '.$subject;
        }
        $partial = $request->request->get('quote') ?? $request->query->get('quote');
        $content = $this->forums->quote($message, \is_string($partial) ? $partial : null, $this->settings->string('default_language') ?: 'en');
        $js = '$(\'#message_subject\').val('.json_encode($subject, \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG).");\n"
            .'$(\'#message_content\').val('.json_encode($content, \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG).");\n"
            ."showAndScrollTo(\"reply\", \"message_content\");\n"
            ."\$('#message_content').scrollTop = \$('#message_content').scrollHeight - \$('#message_content').clientHeight;\n";

        return new Response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }

    #[Route('/boards/{board_id}/topics/preview', name: 'message_preview', requirements: ['board_id' => '\d+'], methods: ['POST'])]
    public function preview(Request $request, int $board_id): Response
    {
        $board = $this->findBoard($board_id);
        $this->requireViewable($board);
        $text = $request->request->get('text');
        if (!\is_string($text)) {
            $text = $request->request->all('message')['content'] ?? $request->request->all('reply')['content'] ?? null;
        }
        if (null === $text || '' === trim((string) $text)) {
            return new Response('<p class="empty-preview">'.htmlspecialchars($this->l('label_nothing_to_preview')).'</p>');
        }

        return new Response($this->formatter->format((string) $text, $board->project));
    }

    private function findBoard(int $id): Board
    {
        $board = $this->em->find(Board::class, $id);
        if (null === $board) {
            throw $this->createNotFoundException();
        }
        $this->findProject((int) $board->project->id);

        return $board;
    }

    /** @return array{0: Board, 1: Message} */
    private function findMessage(int $boardId, int $id): array
    {
        $board = $this->findBoard($boardId);
        $message = $this->em->getRepository(Message::class)->findOneBy(['id' => $id, 'board' => $board]);
        if (null === $message) {
            throw $this->createNotFoundException();
        }

        return [$board, $message];
    }

    private function requireViewable(Board $board): void
    {
        if (!$this->auth->allowedTo('view_messages', $board->project)) {
            $this->deny();
        }
    }

    /** render_attachment_warning_if_needed */
    private function attachmentWarning(array $errors): void
    {
        if ([] !== $errors) {
            $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => \count($errors)]));
        }
    }

    private function renderShow(Request $request, Message $topic, Message $message, array $replies, Message $reply, array $errors, array $pager): Response
    {
        $user = $this->user();
        $project = $topic->board->project;
        $editable = [];
        $destroyable = [];
        foreach ([$topic, ...$replies] as $m) {
            $editable[$m->id] = $this->forums->editableBy($m, $user);
            $destroyable[$m->id] = $this->forums->destroyableBy($m, $user);
        }

        return $this->render('messages/show.html.twig', [
            'project' => $project,
            'board' => $topic->board,
            'ancestors' => $this->forums->ancestors($topic->board),
            'topic' => $topic,
            'message' => $message,
            'replies' => $replies,
            'reply' => $reply,
            'errors' => $errors,
            'reply_pager' => $pager,
            'editable' => $editable,
            'destroyable' => $destroyable,
            'can_reply' => !$topic->locked && $this->auth->allowedTo('add_messages', $project),
            'main_menu_selected' => 'boards',
            'page_title' => $topic->subject,
            'body_css' => 'controller-messages action-show',
        ]);
    }
}

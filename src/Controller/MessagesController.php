<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Content\Forum\ForumFinder;
use App\Service\Content\Forum\MessageForm;
use App\Service\Content\Forum\MessagePreview;
use App\Service\Content\Forum\MessageQuote;
use App\Service\Content\Forum\MessageRemover;
use App\Service\Content\Forum\MessageThread;
use App\Service\ForumService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of MessagesController (forum topics and replies) */
final class MessagesController extends AbstractController
{
    public function __construct(
        private readonly ForumService $forums,
        private readonly ForumFinder $finder,
        private readonly MessageForm $form,
        private readonly MessageThread $thread,
        private readonly MessageQuote $quotes,
        private readonly MessagePreview $previews,
        private readonly MessageRemover $remover,
    ) {
    }

    #[Route('/boards/{board_id}/topics/{id}', name: 'board_message_show', requirements: ['board_id' => '\d+', 'id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, int $board_id, int $id): Response
    {
        $board = $this->finder->board($board_id);
        $message = $this->finder->message($board, $id);
        $this->authorize('view_messages', $board->project);
        $view = $this->thread->view($message, $request);

        return $this->render('messages/show.html.twig', $view + [
            'main_menu_selected' => 'boards',
            'page_title' => $view['topic']->subject,
            'body_css' => 'controller-messages action-show',
        ]);
    }

    #[Route('/boards/{board_id}/topics/new', name: 'message_new', requirements: ['board_id' => '\d+'], methods: ['GET', 'POST'])]
    public function new(Request $request, int $board_id): Response
    {
        $board = $this->finder->board($board_id);
        $this->authorize('add_messages', $board->project);
        $message = $this->form->build($board, $request);
        $errors = [];
        if ($request->isMethod('POST')) {
            $result = $this->form->create($message, $request);
            if ($result->isSaved()) {
                $this->callHook('controller_messages_new_after_save', ['params' => $request->request->all(), 'message' => $message, 'project' => $board->project]);
                if ($result->unsavedAttachments > 0) {
                    $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => $result->unsavedAttachments]));
                }
                $this->flashNotice('notice_successful_create');

                return $this->redirectToRoute('board_message_show', ['board_id' => $board->id, 'id' => $message->id]);
            }
            $errors = $result->errors;
        }

        return $this->render('messages/new.html.twig', $this->form->view($message, $errors) + [
            'main_menu_selected' => 'boards',
            'page_title' => $this->l('label_message_new'),
            'body_css' => 'controller-messages action-new',
        ], new Response('', [] === $errors ? 200 : 422));
    }

    #[Route('/boards/{board_id}/topics/{id}/replies', name: 'message_reply', requirements: ['board_id' => '\d+', 'id' => '\d+'], methods: ['POST'])]
    public function reply(Request $request, int $board_id, int $id): Response
    {
        $board = $this->finder->board($board_id);
        $message = $this->finder->message($board, $id);
        $this->authorize('add_messages', $board->project);
        $reply = $this->form->buildReply($message);
        $result = $this->form->reply($reply, $request);
        if ($result->isSaved()) {
            $this->callHook('controller_messages_reply_after_save', ['params' => $request->request->all(), 'message' => $reply, 'project' => $reply->board->project]);
            if ($result->unsavedAttachments > 0) {
                $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => $result->unsavedAttachments]));
            }
        }
        // Redmine redirects even when the reply is invalid
        $this->flashNotice('notice_successful_update');

        $params = ['board_id' => $board->id, 'id' => $reply->parent->id];
        if (null !== $reply->id) {
            $params['r'] = $reply->id;
        }

        return $this->redirectToRoute('board_message_show', $params);
    }

    #[Route('/boards/{board_id}/topics/{id}/edit', name: 'message_edit', requirements: ['board_id' => '\d+', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $board_id, int $id): Response
    {
        $board = $this->finder->board($board_id);
        $message = $this->finder->message($board, $id);
        if (!$this->auth->allowedTo('view_messages', $board->project) || !$this->forums->editableBy($message, $this->user())) {
            $this->deny();
        }
        $errors = [];
        if ($request->isMethod('POST')) {
            $result = $this->form->update($message, $request);
            if ($result->isSaved()) {
                if ($result->unsavedAttachments > 0) {
                    $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => $result->unsavedAttachments]));
                }
                $this->flashNotice('notice_successful_update');
                $params = ['board_id' => $message->board->id, 'id' => $this->forums->root($message)->id];
                if (null !== $message->parent) {
                    $params['r'] = $message->id;
                }

                return $this->redirectToRoute('board_message_show', $params);
            }
            $errors = $result->errors;
        } else {
            $this->form->fill($message, $request);
        }
        $view = $this->form->editView($board, $message, $errors);

        return $this->render('messages/edit.html.twig', $view + [
            'main_menu_selected' => 'boards',
            'page_title' => $view['topic']->subject,
            'body_css' => 'controller-messages action-edit',
        ], new Response('', [] === $errors ? 200 : 422));
    }

    #[Route('/boards/{board_id}/topics/{id}/destroy', name: 'message_destroy', requirements: ['board_id' => '\d+', 'id' => '\d+'], methods: ['POST', 'DELETE'])]
    public function destroy(int $board_id, int $id): Response
    {
        $board = $this->finder->board($board_id);
        $message = $this->finder->message($board, $id);
        if (!$this->auth->allowedTo('view_messages', $board->project) || !$this->forums->destroyableBy($message, $this->user())) {
            $this->deny();
        }
        $parent = $message->parent;
        $r = $message->id;
        $this->remover->remove($message);
        $this->flashNotice('notice_successful_delete');
        if (null !== $parent) {
            return $this->redirectToRoute('board_message_show', ['board_id' => $board->id, 'id' => $parent->id, 'r' => $r]);
        }

        return $this->redirectToRoute('board_show', ['project_id' => $board->project->identifier, 'id' => $board->id]);
    }

    #[Route('/boards/{board_id}/topics/quote/{id}.{_format}', name: 'message_quote', requirements: ['board_id' => '\d+', 'id' => '\d+', '_format' => 'js|html'], defaults: ['_format' => 'html'], methods: ['GET', 'POST'])]
    public function quote(Request $request, int $board_id, int $id, string $_format = 'html'): Response
    {
        $board = $this->finder->board($board_id);
        $message = $this->finder->message($board, $id);
        $this->authorize('add_messages', $board->project);
        // respond_to: format.js (".js" or a JavaScript Accept header, as sent by data-remote links), format.html is a 404
        $accept = (string) $request->headers->get('Accept', '');
        if ('js' !== $_format && !str_contains($accept, 'javascript') && !($request->isXmlHttpRequest() && ('' === $accept || '*/*' === $accept))) {
            throw $this->createNotFoundException();
        }

        return new Response($this->quotes->script($message, $request), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }

    #[Route('/boards/{board_id}/topics/preview', name: 'message_preview', requirements: ['board_id' => '\d+'], methods: ['POST'])]
    public function preview(Request $request, int $board_id): Response
    {
        $board = $this->finder->board($board_id);
        if (!$this->auth->allowedTo('view_messages', $board->project)) {
            $this->deny();
        }

        return new Response($this->previews->html($board, $request));
    }
}

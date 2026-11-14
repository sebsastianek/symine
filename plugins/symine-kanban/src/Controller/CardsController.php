<?php

declare(strict_types=1);

namespace Symine\Kanban\Controller;

use App\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symine\Kanban\Card\CardCreator;
use Symine\Kanban\Card\CardDetails;
use Symine\Kanban\Card\CardEditor;
use Symine\Kanban\Card\CardMover;
use Symine\Kanban\Entity\Board;
use Symine\Kanban\Http\BoardValueResolver;
use Symine\Kanban\Http\CardResponder;

/**
 * Cards: their dialog, moving and adding them, and what the dialog can do to the issue (fields, comments, files,
 * subtasks, watching). What may be done is decided by the permissions and the workflow of the issues (the services
 * of Symine\Kanban\Card), never by the board. Changes answer JSON for the script of the board (CardResponder).
 */
final class CardsController extends AbstractController
{
    private const array CARD = ['id' => '\d+', 'issue_id' => '\d+'];

    public function __construct(
        private readonly CardMover $mover,
        private readonly CardCreator $creator,
        private readonly CardEditor $editor,
        private readonly CardDetails $details,
        private readonly CardResponder $responder,
    ) {
    }

    /** The dialog of a card */
    #[Route('/kanban/boards/{id}/cards/{issue_id}', name: 'kanban_card_show', requirements: self::CARD, methods: ['GET'])]
    public function show(#[ValueResolver(BoardValueResolver::class)] Board $board, int $issue_id): Response
    {
        $issue = $this->editor->find($board, $issue_id, $this->user()) ?? throw $this->createNotFoundException();

        return $this->render('@KanbanPlugin/boards/_card_dialog.html.twig', $this->details->view($board, $issue, $this->user()));
    }

    #[Route('/kanban/boards/{id}/cards/{issue_id}', name: 'kanban_card_update', requirements: self::CARD, methods: ['POST', 'PATCH'])]
    public function update(Request $request, #[ValueResolver(BoardValueResolver::class)] Board $board, int $issue_id): Response
    {
        $this->requireLogin();

        return $this->responder->respond($board, $this->editor->update($board, $issue_id, $request, $this->user()), $request, $this->user(), true);
    }

    #[Route('/kanban/boards/{id}/cards/{issue_id}/attachments', name: 'kanban_card_attach', requirements: self::CARD, methods: ['POST'])]
    public function attach(Request $request, #[ValueResolver(BoardValueResolver::class)] Board $board, int $issue_id): Response
    {
        $this->requireLogin();

        return $this->responder->respond($board, $this->editor->attach($board, $issue_id, $request, $this->user()), $request, $this->user(), true);
    }

    #[Route('/kanban/boards/{id}/cards/{issue_id}/subtasks', name: 'kanban_card_subtask', requirements: self::CARD, methods: ['POST'])]
    public function subtask(Request $request, #[ValueResolver(BoardValueResolver::class)] Board $board, int $issue_id): Response
    {
        $this->requireLogin();

        return $this->responder->respond($board, $this->editor->addSubtask($board, $issue_id, $request, $this->user()), $request, $this->user(), true);
    }

    #[Route('/kanban/boards/{id}/cards/{issue_id}/watch', name: 'kanban_card_watch', requirements: self::CARD, methods: ['POST'])]
    public function watch(Request $request, #[ValueResolver(BoardValueResolver::class)] Board $board, int $issue_id): Response
    {
        $this->requireLogin();

        return $this->responder->respond($board, $this->editor->toggleWatch($board, $issue_id, $this->user()), $request, $this->user(), true);
    }

    #[Route('/kanban/boards/{id}/cards/{issue_id}/move', name: 'kanban_card_move', requirements: self::CARD, methods: ['POST'])]
    public function move(Request $request, #[ValueResolver(BoardValueResolver::class)] Board $board, int $issue_id): Response
    {
        $this->requireLogin();

        return $this->responder->respond($board, $this->mover->move($board, $issue_id, $request, $this->user()), $request, $this->user());
    }

    #[Route('/kanban/boards/{id}/cards', name: 'kanban_card_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function create(Request $request, #[ValueResolver(BoardValueResolver::class)] Board $board): Response
    {
        $this->requireLogin();

        return $this->responder->respond($board, $this->creator->create($board, $request, $this->user()), $request, $this->user());
    }
}

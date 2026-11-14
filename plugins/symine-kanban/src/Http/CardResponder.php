<?php

declare(strict_types=1);

namespace Symine\Kanban\Http;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symine\Kanban\Board\BoardView;
use Symine\Kanban\Card\CardDetails;
use Symine\Kanban\Card\CardFilter;
use Symine\Kanban\Card\MoveResult;
use Symine\Kanban\Entity\Board;
use Twig\Environment;

/**
 * The answer the board script gets after something was done to a card:
 * {card: "<html>", dialog: "<html>", columns: {id: {count, over_limit}}, version: "..."} — the card as it is now,
 * its dialog when one is open, and the numbers of the column headers — or {errors: [...]} with the status saying why.
 */
final class CardResponder
{
    public function __construct(
        private readonly Environment $twig,
        private readonly BoardView $view,
        private readonly CardDetails $details,
    ) {
    }

    public function respond(Board $board, MoveResult $result, Request $request, User $user, bool $withDialog = false): JsonResponse
    {
        if (!$result->isSuccess()) {
            return new JsonResponse(['errors' => $result->errors], $result->status);
        }
        $payload = ['card' => $this->twig->render('@KanbanPlugin/boards/_card.html.twig', $this->view->cardVariables($board, $result->issue, $user))];
        if ($withDialog) {
            $payload['dialog'] = $this->twig->render('@KanbanPlugin/boards/_card_dialog.html.twig', $this->details->view($board, $result->issue, $user));
        }

        return new JsonResponse($payload + $this->view->summary($board, CardFilter::fromRequest($request), $user), $result->status);
    }
}

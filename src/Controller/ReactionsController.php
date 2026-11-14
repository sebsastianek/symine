<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ReactionService;
use App\Service\Tracking\Http\JavascriptResponse;
use App\Service\Tracking\Reactions\ReactableFinder;
use App\Service\Tracking\Reactions\ReactionScript;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's ReactionsController (JS responses replacing the reaction button). */
final class ReactionsController extends AbstractController
{
    public function __construct(
        private readonly ReactionService $reactions,
        private readonly ReactableFinder $finder,
        private readonly ReactionScript $script,
    ) {
    }

    #[Route('/reactions', name: 'reaction_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $object = $this->finder->find($request);
        if (!$this->script->isRequested($request)) {
            throw $this->createNotFoundException();
        }
        $this->reactions->react($object, $this->user());

        return new JavascriptResponse($this->script->replaceButton($object));
    }

    #[Route('/reactions/{id}', name: 'reaction_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(Request $request, int $id): Response
    {
        $object = $this->finder->find($request);
        if (!$this->script->isRequested($request)) {
            throw $this->createNotFoundException();
        }
        $this->reactions->unreact($object, $this->user(), $id);

        return new JavascriptResponse($this->script->replaceButton($object));
    }
}

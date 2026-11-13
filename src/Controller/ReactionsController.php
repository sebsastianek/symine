<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ReactionService;
use App\Twig\ReactionsExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's ReactionsController (JS responses replacing the reaction button). */
final class ReactionsController extends AbstractController
{
    public function __construct(
        private readonly ReactionService $reactions,
        private readonly ReactionsExtension $helper,
    ) {
    }

    #[Route('/reactions', name: 'reaction_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $object = $this->setObject($request);
        if (!$this->wantsJs($request)) {
            throw $this->createNotFoundException();
        }
        $this->reactions->react($object, $this->user());

        return $this->replaceButton($object);
    }

    #[Route('/reactions/{id}', name: 'reaction_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(Request $request, int $id): Response
    {
        $object = $this->setObject($request);
        if (!$this->wantsJs($request)) {
            throw $this->createNotFoundException();
        }
        $this->reactions->unreact($object, $this->user(), $id);

        return $this->replaceButton($object);
    }

    /** before_action :require_login, :check_enabled, :set_object, :authorize_reactable */
    private function setObject(Request $request): object
    {
        $this->requireLogin();
        if (!$this->reactions->enabled()) {
            $this->deny();
        }
        $type = (string) ($request->query->get('object_type') ?? $request->request->get('object_type') ?? '');
        if (!\in_array($type, ReactionService::REACTABLE_TYPES, true)) {
            $this->deny();
        }
        $id = (int) ($request->query->get('object_id') ?? $request->request->get('object_id') ?? 0);
        $object = $this->reactions->find($type, $id) ?? throw $this->createNotFoundException();
        if (!$this->reactions->isEditable($object, $this->user())) {
            $this->deny();
        }

        return $object;
    }

    private function wantsJs(Request $request): bool
    {
        if ('js' === $request->attributes->get('_format') || 'js' === $request->query->get('format')) {
            return true;
        }
        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'javascript') || $request->isXmlHttpRequest() && !str_contains($accept, 'html');
    }

    /** reactions/_replace_button.js.erb */
    private function replaceButton(object $object): Response
    {
        $id = $this->helper->reactionIdFor($object);
        $button = $this->helper->reactionButton($object);
        $js = "(() => {\n"
            ."  const button = $('[data-reaction-button-id=".$id."]');\n\n"
            ."  removeHoverTooltips(button);\n"
            .'  button.html($('.json_encode($button, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG).").children());\n"
            ."  setupHoverTooltips(button);\n"
            .'})();';

        return new Response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }
}

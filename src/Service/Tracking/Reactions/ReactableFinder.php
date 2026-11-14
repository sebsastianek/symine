<?php

declare(strict_types=1);

namespace App\Service\Tracking\Reactions;

use App\Security\AccessGuard;
use App\Security\CurrentUser;
use App\Service\ReactionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads what a reaction is added to or removed from, named by object_type / object_id, for a user who may react
 * to it: before_action :require_login, :check_enabled, :set_object, :authorize_reactable of ReactionsController.
 */
final class ReactableFinder
{
    public function __construct(
        private readonly ReactionService $reactions,
        private readonly AccessGuard $guard,
        private readonly CurrentUser $currentUser,
    ) {
    }

    /** @throws NotFoundHttpException when there is no such object */
    public function find(Request $request): object
    {
        $this->guard->requireLogin();
        if (!$this->reactions->enabled()) {
            $this->guard->deny();
        }
        $type = (string) ($request->query->get('object_type') ?? $request->request->get('object_type') ?? '');
        if (!\in_array($type, ReactionService::REACTABLE_TYPES, true)) {
            $this->guard->deny();
        }
        $id = (int) ($request->query->get('object_id') ?? $request->request->get('object_id') ?? 0);
        $object = $this->reactions->find($type, $id) ?? throw new NotFoundHttpException();
        if (!$this->reactions->isEditable($object, $this->currentUser->get())) {
            $this->guard->deny();
        }

        return $object;
    }
}

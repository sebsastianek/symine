<?php

declare(strict_types=1);

namespace Symine\Kanban\Http;

use App\Security\AccessGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symine\Kanban\Board\BoardAccess;
use Symine\Kanban\Board\BoardFinder;
use Symine\Kanban\Entity\Board;
use Symine\Kanban\KanbanSchema;

/**
 * Gives controllers the board named by the route: an action declares
 * "#[ValueResolver(BoardValueResolver::class)] Board $board" and the {id} of the URL is loaded. 404 when there is no
 * such board; a board the user may not see is refused like any other page (login page for visitors, 403 otherwise).
 *
 * The resolver is targeted (asked for by name) on purpose: Board is a Doctrine entity, and Doctrine's own resolver
 * would otherwise load any board by id without the visibility check.
 */
#[AsTargetedValueResolver]
final class BoardValueResolver implements ValueResolverInterface
{
    public function __construct(
        private readonly BoardFinder $finder,
        private readonly BoardAccess $access,
        private readonly AccessGuard $guard,
        private readonly KanbanSchema $schema,
    ) {
    }

    /** @return iterable<Board> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (Board::class !== $argument->getType()) {
            return [];
        }
        $id = $request->attributes->get('id');
        $board = $this->schema->isInstalled() && is_numeric($id) ? $this->finder->find((int) $id) : null;
        if (null === $board) {
            throw new NotFoundHttpException();
        }
        if (!$this->access->canView($board)) {
            $this->guard->deny();
        }

        return [$board];
    }
}

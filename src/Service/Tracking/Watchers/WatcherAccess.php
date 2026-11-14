<?php

declare(strict_types=1);

namespace App\Service\Tracking\Watchers;

use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Twig\IssuesExtension;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Who may add or remove watchers: the add_<type>_watchers / delete_<type>_watchers permissions of
 * WatchersController (before_action :authorize and the check done on each watched object).
 */
final class WatcherAccess
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly Authorization $auth,
        private readonly IssuesExtension $helpers,
    ) {
    }

    /**
     * The permission on the project of the request, or on any project when the request names neither objects nor
     * a project. Objects of several projects are checked one by one by requireWatchables().
     *
     * @param 'add'|'delete' $action
     */
    public function authorize(WatcherContext $context, string $action): void
    {
        $perm = $action.'_'.(null !== ($first = $context->first()) ? $this->helpers->watchableType($first) : 'issue').'_watchers';
        if ($context->project) {
            $this->guard->authorize($perm, $context->project);
        } elseif ($context->isEmpty()) {
            $this->guard->authorize($perm, null, true);
        }
    }

    /**
     * The request must name watched objects (404) and the user needs the permission on each of them.
     *
     * @param 'add'|'delete' $action
     */
    public function requireWatchables(WatcherContext $context, string $action): void
    {
        if ($context->isEmpty()) {
            throw new NotFoundHttpException();
        }
        foreach ($context->watchables as $w) {
            if (!$this->auth->allowedTo($action.'_'.$this->helpers->watchableType($w).'_watchers', $this->helpers->watchableProject($w))) {
                $this->guard->deny();
            }
        }
    }
}

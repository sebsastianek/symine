<?php

declare(strict_types=1);

namespace Symine\Kanban\Board;

use App\Entity\Project;
use App\Entity\User;
use App\Security\Authorization;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symine\Kanban\Entity\Board;
use Symine\Kanban\KanbanSchema;

/**
 * Who may see and change boards. The rules follow Redmine's saved queries:
 *
 *  - seeing boards needs "view_kanban_boards" in the project of the board (in any project for a board above the
 *    projects); a private board is seen by its owner only, a board shared with roles by the members with those roles;
 *  - "save_kanban_boards" lets somebody keep private boards, "manage_public_kanban_boards" lets them share boards
 *    and change the ones others shared;
 *  - what is on a card is governed by the permissions of the issues, never by the board.
 */
final class BoardAccess
{
    public function __construct(
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly EntityManagerInterface $em,
        private readonly KanbanSchema $schema,
    ) {
    }

    /** Whether the user may see boards somewhere (menu entry above the projects) */
    public function mayViewAnyBoard(?User $user = null): bool
    {
        return $this->schema->isInstalled() && $this->auth->allowedToGlobally('view_kanban_boards', $user ?? $this->currentUser->get());
    }

    public function mayViewBoardsOf(?Project $project, ?User $user = null): bool
    {
        return $this->allowed('view_kanban_boards', $project, $user ?? $this->currentUser->get());
    }

    public function canView(Board $board, ?User $user = null): bool
    {
        $user ??= $this->currentUser->get();
        $project = $this->project($board);
        if (null !== $board->projectId && null === $project) {
            // the project of the board is gone
            return $user->admin;
        }
        if (!$this->allowed('view_kanban_boards', $project, $user)) {
            return false;
        }
        if ($user->admin || ($user->isLogged() && $board->userId === $user->id)) {
            return true;
        }

        return match ($board->visibility) {
            Board::VISIBILITY_PUBLIC => true,
            Board::VISIBILITY_ROLES => [] !== array_intersect($board->roleIds ?? [], $this->roleIds($project, $user)),
            default => false,
        };
    }

    public function canCreate(?Project $project, ?User $user = null): bool
    {
        $user ??= $this->currentUser->get();

        return $user->isLogged() && ($this->allowed('save_kanban_boards', $project, $user) || $this->canShare($project, $user));
    }

    /** Whether the user may make boards visible to others (and change the boards others shared) */
    public function canShare(?Project $project, ?User $user = null): bool
    {
        $user ??= $this->currentUser->get();

        return $user->isLogged() && $this->allowed('manage_public_kanban_boards', $project, $user);
    }

    public function canEdit(Board $board, ?User $user = null): bool
    {
        $user ??= $this->currentUser->get();
        if (!$user->isLogged() || !$this->canView($board, $user)) {
            return false;
        }
        $project = $this->project($board);
        if ($user->admin) {
            return true;
        }
        if ($board->isPrivate()) {
            return $board->userId === $user->id && $this->canCreate($project, $user);
        }

        return $this->canShare($project, $user);
    }

    public function project(Board $board): ?Project
    {
        return null === $board->projectId ? null : $this->em->find(Project::class, $board->projectId);
    }

    private function allowed(string $permission, ?Project $project, User $user): bool
    {
        return null === $project
            ? $this->auth->allowedToGlobally($permission, $user)
            : $this->auth->allowedTo($permission, $project, $user);
    }

    /** @return list<int> the roles of the user in the project, or in any project for a board above the projects */
    private function roleIds(?Project $project, User $user): array
    {
        $roles = null === $project ? $this->auth->allRoles($user) : $this->auth->rolesForProject($project, $user);

        return array_values(array_filter(array_map(static fn ($role) => $role->id, $roles)));
    }
}

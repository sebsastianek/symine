<?php

declare(strict_types=1);

namespace Symine\Kanban\Board;

use App\Entity\Project;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symine\Kanban\Entity\Board;

/** Finds the boards the current user may see */
final class BoardFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BoardAccess $access,
        private readonly CurrentUser $currentUser,
    ) {
    }

    public function find(int $id): ?Board
    {
        return $this->em->find(Board::class, $id);
    }

    /**
     * The visible boards of a project, in the order of its board menu.
     *
     * @return list<Board>
     */
    public function forProject(Project $project): array
    {
        return $this->visible($this->em->getRepository(Board::class)->findBy(['projectId' => $project->id], ['position' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']));
    }

    /**
     * Every visible board: the ones above the projects first, then project by project.
     *
     * @return list<Board>
     */
    public function all(): array
    {
        $boards = $this->visible($this->em->getRepository(Board::class)->findBy([], ['position' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']));
        usort($boards, static fn (Board $a, Board $b) => [null !== $a->projectId, $a->projectId, $a->position, $a->name] <=> [null !== $b->projectId, $b->projectId, $b->position, $b->name]);

        return $boards;
    }

    /**
     * The boards shown next to a board, for switching: the ones of the same project (or the ones above the projects).
     *
     * @return list<Board>
     */
    public function siblings(Board $board): array
    {
        return $this->visible($this->em->getRepository(Board::class)->findBy(['projectId' => $board->projectId], ['position' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']));
    }

    /**
     * @param list<Board> $boards
     *
     * @return list<Board>
     */
    private function visible(array $boards): array
    {
        $user = $this->currentUser->get();

        return array_values(array_filter($boards, fn (Board $board) => $this->access->canView($board, $user)));
    }
}

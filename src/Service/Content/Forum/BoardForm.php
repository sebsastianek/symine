<?php

declare(strict_types=1);

namespace App\Service\Content\Forum;

use App\Entity\Board;
use App\Entity\Project;
use App\Service\ForumService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The form of a forum in the settings of a project: board[name], board[description], board[parent_id] and
 * board[position] (sent alone when the forums are reordered by drag and drop).
 */
final class BoardForm
{
    public function __construct(
        private readonly ForumService $forums,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** A new forum of the project, filled with the board[..] parameters of the URL of the form */
    public function build(Project $project, Request $request): Board
    {
        $board = new Board();
        $board->project = $project;
        $this->forums->assignBoard($board, $request->query->all('board'));

        return $board;
    }

    /**
     * Saves a new forum when what was submitted is valid.
     *
     * @return array{0: Board, 1: list<string>} the forum and the errors that prevented saving it
     */
    public function create(Project $project, Request $request): array
    {
        $board = new Board();
        $board->project = $project;
        $this->forums->assignBoard($board, $request->request->all('board'));
        $errors = $this->forums->validateBoard($board);
        if ([] === $errors) {
            $this->forums->saveBoard($board);
        }

        return [$board, $errors];
    }

    /**
     * Saves the changes submitted for a forum when they are valid.
     *
     * @return list<string> the errors that prevented saving
     */
    public function update(Board $board, Request $request): array
    {
        $before = ['position' => $board->position, 'parent_id' => $board->parent?->id];
        $this->forums->assignBoard($board, $request->request->all('board'));
        $errors = $this->forums->validateBoard($board, $before['parent_id']);
        if ([] === $errors) {
            $this->forums->saveBoard($board, $before);
        }

        return $errors;
    }

    /** Forgets the invalid changes made to a forum, once the form showing them has been rendered */
    public function revert(Board $board): void
    {
        $this->em->refresh($board);
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    public function view(Board $board, array $errors = []): array
    {
        return [
            'project' => $board->project,
            'board' => $board,
            'errors' => $errors,
            'parent_options' => $this->forums->boardOptions($this->forums->validParents($board)),
        ];
    }

    /** Where the form leads once saved: the "Forums" tab of the project settings */
    public function settingsUrl(Project $project): string
    {
        try {
            return $this->urls->generate('project_settings', ['id' => $project->identifier, 'tab' => 'boards']);
        } catch (\Throwable) {
            return $this->urls->generate('boards_index', ['project_id' => $project->identifier]);
        }
    }
}

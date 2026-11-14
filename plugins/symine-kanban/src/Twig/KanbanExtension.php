<?php

declare(strict_types=1);

namespace Symine\Kanban\Twig;

use Symine\Kanban\Board\BoardAccess;
use Symine\Kanban\Board\BoardScope;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Template helpers of the Kanban pages */
final class KanbanExtension extends AbstractExtension
{
    public function __construct(private readonly BoardScope $scope, private readonly BoardAccess $access)
    {
    }

    public function getFunctions(): array
    {
        return [
            // id => name of every issue status: the board script names the statuses a card can take in a column
            new TwigFunction('kanban_status_names', function (): array {
                $names = [];
                foreach ($this->scope->allStatuses() as $status) {
                    $names[(int) $status->id] = $status->name;
                }

                return $names;
            }),
            // the project a board belongs to, null for a board above the projects
            new TwigFunction('kanban_board_project', $this->access->project(...)),
        ];
    }
}

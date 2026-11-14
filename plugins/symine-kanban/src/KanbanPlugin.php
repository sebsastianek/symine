<?php

declare(strict_types=1);

namespace Symine\Kanban;

use App\Entity\Project;
use App\Entity\User;
use App\Plugin\AbstractSyminePlugin;
use App\Plugin\PluginDefinition;
use Symine\Kanban\Board\BoardAccess;

/**
 * Kanban boards: Trello-like boards over the issues of one or several projects.
 *
 * A board maps issue statuses to columns. Every status is either in a column or explicitly hidden; issues in a
 * status that is neither are shown in an automatic "Unmapped" column, so nothing disappears by accident.
 * Moving a card changes the status of the issue, within what the workflow allows the user to do.
 */
final class KanbanPlugin extends AbstractSyminePlugin
{
    public const string ID = 'symine_kanban';

    public function getPluginId(): string
    {
        return self::ID;
    }

    public function register(PluginDefinition $plugin): void
    {
        $plugin
            ->name('Symine Kanban')
            ->author('Symine')
            ->description('Kanban boards over one or several projects, with issue statuses mapped to columns')
            ->version('1.0.0')
            ->requiresSymine('0.1')
            ->settings(['default_closed_days' => '14', 'default_card_limit' => '100', 'default_refresh' => '60'], '@KanbanPlugin/settings/_kanban_settings.html.twig')
            ->projectModule('kanban', static function (PluginDefinition $p): void {
                $p->permission('view_kanban_boards', ['kanban_index', 'project_kanban', 'kanban_board_show', 'kanban_board_cards', 'kanban_board_version', 'kanban_card_show'], ['read' => true]);
                $p->permission('save_kanban_boards', ['kanban_board_new', 'kanban_board_create', 'kanban_board_edit', 'kanban_board_update', 'kanban_board_destroy', 'kanban_board_copy'], ['require' => 'loggedin']);
                $p->permission('manage_public_kanban_boards', [], ['require' => 'member']);
            })
            ->menu('project_menu', 'kanban', 'project_kanban', ['caption' => 'label_kanban', 'param' => 'project_id', 'after' => 'issues'])
            ->menu('application_menu', 'kanban', 'kanban_index', [
                'caption' => 'label_kanban',
                'after' => 'issues',
                'permission' => false,
                // only where it leads somewhere: for people who may see boards in at least one project
                'if' => fn (?Project $project, User $user): bool => (bool) $this->container?->get(BoardAccess::class)->mayViewAnyBoard($user),
            ]);
    }
}

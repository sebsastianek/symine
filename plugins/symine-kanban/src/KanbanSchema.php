<?php

declare(strict_types=1);

namespace Symine\Kanban;

use App\Plugin\PluginSchema;

/**
 * Whether the migrations of the plugin have been run. Until `bin/console symine:plugins:migrate symine_kanban`
 * the plugin stays out of the way (no menu entry, pages explain what to do) instead of failing on a missing table.
 *
 * The menu asks on every request: the answer comes from PluginSchema, which remembers "yes" across requests.
 */
final class KanbanSchema
{
    private const array TABLES = ['kanban_boards', 'kanban_columns', 'kanban_card_positions'];

    public function __construct(private readonly PluginSchema $schema)
    {
    }

    public function isInstalled(): bool
    {
        try {
            return $this->schema->hasTables(KanbanPlugin::ID, self::TABLES);
        } catch (\Throwable) {
            return false;
        }
    }

    public function reset(): void
    {
        $this->schema->forget(KanbanPlugin::ID);
    }
}

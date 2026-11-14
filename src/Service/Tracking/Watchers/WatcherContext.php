<?php

declare(strict_types=1);

namespace App\Service\Tracking\Watchers;

use App\Entity\Project;

/**
 * What a watchers request works on: the watched objects named by object_type / object_id, their projects, and
 * the project of the request (the one of the objects when they share it, or the one given as project_id).
 */
final class WatcherContext
{
    /**
     * @param list<object>  $watchables
     * @param list<Project> $projects   the projects of the watchables
     */
    public function __construct(
        public readonly array $watchables = [],
        public readonly ?Project $project = null,
        public readonly array $projects = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->watchables;
    }

    public function isSingle(): bool
    {
        return 1 === \count($this->watchables);
    }

    public function first(): ?object
    {
        return $this->watchables[0] ?? null;
    }
}

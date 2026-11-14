<?php

declare(strict_types=1);

namespace App\Service\Tracking\ContextMenu;

use App\Entity\Issue;
use App\Entity\Project;

/**
 * The issues a context menu was opened on: the single issue when there is only one, their ids and their projects.
 */
final class IssueSelection
{
    /** The issue, when exactly one is selected */
    public readonly ?Issue $issue;
    /** @var list<int> sorted */
    public readonly array $ids;
    /** @var list<Project> */
    public readonly array $projects;
    /** The project, when all the issues belong to the same one */
    public readonly ?Project $project;

    /** @param non-empty-list<Issue> $issues */
    public function __construct(public readonly array $issues)
    {
        $projects = [];
        foreach ($issues as $i) {
            $projects[$i->project->id] = $i->project;
        }
        $this->projects = array_values($projects);
        $this->project = 1 === \count($this->projects) ? $this->projects[0] : null;
        $this->issue = 1 === \count($issues) ? $issues[0] : null;
        $ids = array_map(static fn (Issue $i) => (int) $i->id, $issues);
        sort($ids);
        $this->ids = $ids;
    }
}

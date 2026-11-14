<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Issue;
use App\Entity\Project;

/**
 * Outcome of a bulk update or bulk copy of issues: what was saved, what was not and why.
 */
final class IssueBulkUpdate
{
    /**
     * @param list<Issue>   $issues        the issues that were to be updated or copied
     * @param list<Project> $projects      the projects of the selected issues
     * @param list<Issue>   $saved         the updated issues, or the copies
     * @param list<Issue>   $unsaved       the selected issues that could not be updated or copied
     * @param list<string>  $errorMessages "message: #id, #id" for each validation message (bulk_edit_error_messages)
     */
    public function __construct(
        public readonly array $issues,
        public readonly array $projects,
        public readonly array $saved,
        public readonly array $unsaved,
        public readonly array $errorMessages,
    ) {
    }

    public function isSaved(): bool
    {
        return [] === $this->unsaved;
    }

    /** The issue to show after "update and follow" of a single issue */
    public function followedIssue(): ?Issue
    {
        return 1 === \count($this->issues) && 1 === \count($this->saved) ? $this->saved[0] : null;
    }

    /** The project whose issue list is shown after "update and follow": the one all the saved issues are in */
    public function followedProject(): ?Project
    {
        $identifiers = array_unique(array_map(static fn (Issue $i) => $i->project->identifier, $this->saved));

        return 1 === \count($identifiers) ? $this->saved[0]->project : null;
    }

    /** The project of the selected issues when they all were in the same one */
    public function project(): ?Project
    {
        return 1 === \count($this->projects) ? $this->projects[0] : null;
    }
}

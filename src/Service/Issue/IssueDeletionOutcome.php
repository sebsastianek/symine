<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Project;

/**
 * Outcome of a request to delete issues: they are deleted, or the user must first confirm what becomes of
 * the time logged on them.
 */
final class IssueDeletionOutcome
{
    /**
     * @param Project|null              $project      the project of the issues when they all were in the same one
     * @param array<string, mixed>|null $confirmation variables of issues/destroy when nothing was deleted yet
     */
    public function __construct(
        public readonly ?Project $project,
        public readonly ?array $confirmation = null,
    ) {
    }

    public function isDeleted(): bool
    {
        return null === $this->confirmation;
    }
}

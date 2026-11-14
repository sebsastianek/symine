<?php

declare(strict_types=1);

namespace App\Service\Timelog;

use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\TimeEntry;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A time entry being logged through the "new" form or the API: the entry, where it is logged (the project or
 * the issue of the request) and, once submitted, why it could not be saved.
 */
#[Exclude]
final class TimeEntryDraft
{
    /**
     * @param list<string>         $errors    empty when the entry is not submitted yet or was saved
     * @param ?string              $projectId the project the request named (route or parameters), if any
     * @param ?int                 $issueId   the issue the request named (route or parameters), if any
     * @param array<string, mixed> $attrs     the submitted attributes (params[:time_entry])
     */
    public function __construct(
        public readonly TimeEntry $entry,
        public readonly ?Project $project,
        public readonly ?Issue $issue,
        public readonly array $errors = [],
        public readonly ?string $projectId = null,
        public readonly ?int $issueId = null,
        public readonly array $attrs = [],
    ) {
    }
}

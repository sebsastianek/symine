<?php

declare(strict_types=1);

namespace App\Service\Timelog;

use App\Entity\TimeEntry;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Outcome of a bulk update of time entries: those saved, those refused and why. */
#[Exclude]
final class TimeEntryBulkResult
{
    /**
     * @param list<TimeEntry> $saved
     * @param list<TimeEntry> $unsaved left as they were
     * @param list<string>    $errors  the distinct validation messages of the refused entries
     */
    public function __construct(
        public readonly array $saved,
        public readonly array $unsaved,
        public readonly array $errors,
    ) {
    }
}

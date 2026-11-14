<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Attachment;
use App\Entity\Journal;

/**
 * Outcome of a submitted issue edit form or API update: saved, refused by validation, in conflict with a
 * newer version of the issue, or abandoned by the user after a conflict.
 */
final class IssueUpdate
{
    /**
     * @param bool             $cancelled          the user gave up his changes after a conflict
     * @param bool             $conflict           the issue was changed by someone else in the meantime
     * @param list<Journal>    $conflictJournals   what the others changed since the form was opened
     * @param list<string>     $errors
     * @param list<Attachment> $savedAttachments   files stored with this submission (kept when the form is shown again)
     * @param int              $unsavedAttachments files sent although the user may not add any
     * @param bool             $journalAdded       the update left a trace in the history
     */
    public function __construct(
        public readonly bool $cancelled = false,
        public readonly bool $conflict = false,
        public readonly array $conflictJournals = [],
        public readonly array $errors = [],
        public readonly array $savedAttachments = [],
        public readonly int $unsavedAttachments = 0,
        public readonly bool $journalAdded = false,
    ) {
    }

    public function isSaved(): bool
    {
        return !$this->cancelled && !$this->conflict && [] === $this->errors;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Attachment;
use App\Entity\Issue;

/**
 * Outcome of a submitted "new issue" form or API call: the issue, whether it was saved, the validation errors
 * and what happened to the files sent with it.
 */
final class IssueCreation
{
    /**
     * @param list<string>     $errors
     * @param list<Attachment> $savedAttachments   files stored with this submission (kept when the form is shown again)
     * @param int              $unsavedAttachments files that could not be stored
     */
    public function __construct(
        public readonly Issue $issue,
        public readonly bool $saved,
        public readonly array $errors = [],
        public readonly array $savedAttachments = [],
        public readonly int $unsavedAttachments = 0,
    ) {
    }
}

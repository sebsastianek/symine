<?php

declare(strict_types=1);

namespace App\Service\Content;

/**
 * Outcome of a submitted content form (news, forum message...): the validation errors that prevented saving
 * and the number of attached files that could not be saved (render_attachment_warning_if_needed).
 */
final class Submission
{
    /** @param list<string> $errors */
    public function __construct(
        public readonly array $errors = [],
        public readonly int $unsavedAttachments = 0,
    ) {
    }

    public function isSaved(): bool
    {
        return [] === $this->errors;
    }
}

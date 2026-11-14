<?php

declare(strict_types=1);

namespace App\Service\Content\Documents;

/**
 * Outcome of the document form: the validation errors that prevented saving, the custom field values as they
 * were submitted (to show the form again) and the number of files that could not be saved.
 */
final class DocumentSubmission
{
    /**
     * @param list<string>      $errors
     * @param array<int, mixed> $customFieldValues custom field id => submitted value
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $customFieldValues,
        public readonly int $unsavedAttachments = 0,
    ) {
    }

    public function isSaved(): bool
    {
        return [] === $this->errors;
    }
}

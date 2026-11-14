<?php

declare(strict_types=1);

namespace App\Service\Wiki;

/**
 * Outcome of the edit form of a wiki page: whether it was saved, and otherwise why not, with what was
 * submitted so the form can be shown again.
 */
final class WikiPageSubmission
{
    /**
     * @param list<string> $errors           validation errors that prevented saving
     * @param bool         $conflict         the page was changed by someone else in the meantime (nothing saved)
     * @param bool         $created          the page did not exist before
     * @param mixed        $submittedVersion the version the edit was based on, as submitted
     * @param int|null     $lockVersion      the version the save was checked against
     * @param int|null     $section          the section edited, when only one was
     * @param list<string> $attachmentErrors files that could not be attached
     */
    public function __construct(
        public readonly array $errors = [],
        public readonly bool $conflict = false,
        public readonly bool $created = false,
        public readonly string $text = '',
        public readonly ?string $comments = null,
        public readonly mixed $submittedVersion = null,
        public readonly ?int $lockVersion = null,
        public readonly ?int $section = null,
        public readonly ?string $sectionHash = null,
        public readonly array $attachmentErrors = [],
    ) {
    }

    public function isSaved(): bool
    {
        return !$this->conflict && [] === $this->errors;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Admin\AuthSources;

use App\Entity\AuthSource;

/** The outcome of submitting the authentication mode form: the mode as submitted and what was wrong with it. */
final class AuthSourceSubmission
{
    /** @param list<string> $errors full error messages; empty when the authentication mode was saved */
    public function __construct(
        public readonly AuthSource $source,
        public readonly array $errors,
    ) {
    }

    public function isSaved(): bool
    {
        return [] === $this->errors;
    }
}

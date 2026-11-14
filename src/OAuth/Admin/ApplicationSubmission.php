<?php

declare(strict_types=1);

namespace App\OAuth\Admin;

use App\Entity\OauthApplication;

/** The outcome of submitting the OAuth application form: the application as submitted and what was wrong with it. */
final class ApplicationSubmission
{
    /**
     * @param list<string> $errors full error messages; empty when the application was saved
     * @param string|null  $secret the secret of the application just created, in clear
     */
    public function __construct(
        public readonly OauthApplication $application,
        public readonly array $errors,
        public readonly ?string $secret = null,
    ) {
    }

    public function isSaved(): bool
    {
        return [] === $this->errors;
    }
}

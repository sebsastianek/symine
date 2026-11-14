<?php

declare(strict_types=1);

namespace App\Service\Admin\Users;

use App\Entity\User;

/** The outcome of submitting the user form: the user as submitted, what was wrong, and what was asked. */
final class UserSubmission
{
    /**
     * @param list<string>         $errors          full error messages; empty when the user was saved
     * @param array<string, mixed> $attrs           the user[...] parameters as they were applied
     * @param bool                 $sendInformation whether the account information was to be e-mailed
     */
    public function __construct(
        public readonly User $user,
        public readonly array $errors,
        public readonly array $attrs,
        public readonly bool $sendInformation,
    ) {
    }

    public function isSaved(): bool
    {
        return [] === $this->errors;
    }

    /** Whether a generated password was asked: carried over to the next form by "Create and add another" */
    public function generatePassword(): bool
    {
        return !empty($this->attrs['generate_password']);
    }
}

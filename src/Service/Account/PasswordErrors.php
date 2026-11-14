<?php

declare(strict_types=1);

namespace App\Service\Account;

/**
 * What is wrong with a submitted new password: either one message about the request as a whole (wrong current
 * password, same password as before) or the validation errors of the password itself.
 */
final readonly class PasswordErrors
{
    /** @param list<string> $errors */
    public function __construct(
        public ?string $error = null,
        public array $errors = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->error && [] === $this->errors;
    }
}

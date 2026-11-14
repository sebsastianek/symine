<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;

/** The result of RegistrationForm::handle(): the outcome, the user being registered and what the form shows. */
final readonly class RegistrationResult
{
    /** @param array<string, mixed> $view the variables of account/register.html.twig (outcome Form) */
    public function __construct(
        public RegistrationOutcome $outcome,
        public User $user,
        public array $view = [],
    ) {
    }
}

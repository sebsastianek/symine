<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;

/** The result of a step of PasswordRecovery. */
final readonly class PasswordRecoveryResult
{
    /**
     * @param User|null            $user the account concerned (step Pending)
     * @param array<string, mixed> $view the variables of account/password_recovery.html.twig (step Form)
     */
    public function __construct(
        public PasswordRecoveryStep $step,
        public ?User $user = null,
        public array $view = [],
    ) {
    }
}

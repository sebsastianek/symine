<?php

declare(strict_types=1);

namespace App\Service\Admin\Groups;

use App\Entity\Group;

/** The outcome of submitting the group form: the group as submitted and what was wrong with it. */
final class GroupSubmission
{
    /**
     * @param list<string>         $errors full error messages; empty when the group was saved
     * @param array<string, mixed> $attrs  the group[...] parameters
     */
    public function __construct(
        public readonly Group $group,
        public readonly array $errors,
        public readonly array $attrs,
    ) {
    }

    public function isSaved(): bool
    {
        return [] === $this->errors;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Admin\Trackers;

/**
 * What a submitted tracker form gave besides the attributes of the tracker itself: the validation errors and
 * the custom fields and projects that were ticked, which are only stored once the tracker is saved.
 */
final class TrackerSubmission
{
    /**
     * @param list<string>   $errors
     * @param list<int>|null $customFieldIds null: not submitted
     * @param list<int>|null $projectIds     null: not submitted
     */
    public function __construct(
        public readonly array $errors = [],
        public readonly ?array $customFieldIds = null,
        public readonly ?array $projectIds = null,
    ) {
    }

    public function isValid(): bool
    {
        return [] === $this->errors;
    }
}

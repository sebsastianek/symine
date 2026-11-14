<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\User;

/** Dispatched after an issue is created, updated or deleted (used by notifications and webhooks). */
final class IssueEvent
{
    public const string CREATED = 'issue.created';
    public const string UPDATED = 'issue.updated';
    public const string DELETED = 'issue.deleted';

    public function __construct(
        public readonly Issue $issue,
        public readonly string $action,
        public readonly User $user,
        public readonly ?Journal $journal = null,
        /** false when only a journal was saved, not the issue (relation added/removed, split private notes): e-mail but no webhook */
        public readonly bool $webhook = true,
    ) {
    }
}

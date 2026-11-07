<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\User;

/**
 * Generic domain event for non-issue content, used by notifications (Mailer) and webhooks.
 * $type: news | news_comment | wiki_page | message | document | attachment(files) | time_entry | version | user | project
 * $action: created | updated | deleted
 */
final class ContentEvent
{
    public function __construct(
        public readonly string $type,
        public readonly string $action,
        public readonly object $subject,
        public readonly User $user,
        /** @var array<string, mixed> */
        public readonly array $context = [],
    ) {
    }
}

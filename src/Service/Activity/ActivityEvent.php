<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Principal;
use App\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * An activity / search event (port of Redmine's acts_as_event interface:
 * event_type, event_datetime, event_title, event_description, event_author, event_url, event_group).
 */
#[Exclude]
final class ActivityEvent
{
    public function __construct(
        public readonly string $type,
        public readonly \DateTimeImmutable $datetime,
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $description,
        public readonly ?Principal $author,
        public readonly ?Project $project,
        /** event_group key: events of the same group are displayed together */
        public readonly string $group,
        /** record id (search API) */
        public readonly int $id,
        /** author name when the author is not a user (changeset committer) */
        public readonly ?string $authorName = null,
    ) {
    }

    public function hasAuthor(): bool
    {
        return null !== $this->author || null !== $this->authorName;
    }
}

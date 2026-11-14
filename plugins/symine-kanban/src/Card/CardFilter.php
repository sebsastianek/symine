<?php

declare(strict_types=1);

namespace Symine\Kanban\Card;

use Symfony\Component\HttpFoundation\Request;

/**
 * The quick filters of a board page, kept in the query string so a filtered board can be bookmarked and shared:
 * q (words of the subject, or #id), assigned_to_id ("me", "none" or an id), tracker_id, priority_id,
 * fixed_version_id.
 */
final readonly class CardFilter
{
    public function __construct(
        public string $text = '',
        public string $assignee = '',
        public ?int $trackerId = null,
        public ?int $priorityId = null,
        public ?int $versionId = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $id = static fn (string $name): ?int => ctype_digit((string) $request->query->get($name)) ? (int) $request->query->get($name) : null;
        $assignee = (string) $request->query->get('assigned_to_id', '');

        return new self(
            mb_substr(trim((string) $request->query->get('q', '')), 0, 255),
            \in_array($assignee, ['me', 'none'], true) || ctype_digit($assignee) ? $assignee : '',
            $id('tracker_id'),
            $id('priority_id'),
            $id('fixed_version_id'),
        );
    }

    public function isActive(): bool
    {
        return '' !== $this->text || '' !== $this->assignee || null !== $this->trackerId || null !== $this->priorityId || null !== $this->versionId;
    }

    /** @return array<string, string> the query string that reproduces the filter */
    public function toQuery(): array
    {
        return array_filter([
            'q' => $this->text,
            'assigned_to_id' => $this->assignee,
            'tracker_id' => (string) ($this->trackerId ?? ''),
            'priority_id' => (string) ($this->priorityId ?? ''),
            'fixed_version_id' => (string) ($this->versionId ?? ''),
        ], static fn (string $v) => '' !== $v);
    }
}

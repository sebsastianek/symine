<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\IssueStatus;

/** Builds the REST API structure of app/views/issue_statuses/index.api.rsb. */
final class IssueStatusApiPresenter
{
    /**
     * @param list<IssueStatus> $statuses
     *
     * @return list<array{id: ?int, name: string, is_closed: bool, description: ?string}>
     */
    public function statuses(array $statuses): array
    {
        return array_map(static fn (IssueStatus $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'is_closed' => $s->isClosed,
            'description' => $s->description,
        ], $statuses);
    }
}

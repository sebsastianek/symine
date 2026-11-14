<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Query as QueryRecord;

/** The saved queries of the REST API (queries/index.api.rsb). */
final class QueryApiPresenter
{
    /**
     * @param array{queries: list<QueryRecord>, total_count: int, offset: int, limit: int} $page
     *
     * @return array{queries: list<array<string, mixed>>, total_count: int, offset: int, limit: int}
     */
    public function index(array $page): array
    {
        return ['queries' => array_map($this->query(...), $page['queries'])] + $page;
    }

    /** @return array{id: ?int, name: string, is_public: bool, project_id: ?int} */
    public function query(QueryRecord $q): array
    {
        return [
            'id' => $q->id,
            'name' => $q->name,
            'is_public' => QueryRecord::VISIBILITY_PRIVATE !== (int) $q->visibility,
            'project_id' => $q->project?->id,
        ];
    }
}

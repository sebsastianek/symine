<?php

declare(strict_types=1);

namespace App\Api;

use App\Service\Activity\ActivityEvent;
use App\Service\Search\SearchRun;
use Symfony\Component\HttpFoundation\Request;

/** Builds the REST API structure of app/views/search/index.api.rsb. */
final class SearchApiPresenter
{
    /** @return array<string, mixed> */
    public function results(SearchRun $search, Request $request): array
    {
        $base = $request->getSchemeAndHttpHost().$request->getBaseUrl();

        return [
            'results' => array_map(static fn (ActivityEvent $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'type' => $e->type,
                'url' => $base.$e->url,
                'description' => $e->description,
                'datetime' => ApiRenderer::time($e->datetime),
            ], $search->results ?? []),
            'total_count' => [] === $search->tokens ? null : $search->resultCount,
            'offset' => $search->offset ?? 0,
            'limit' => $search->limit,
        ];
    }
}

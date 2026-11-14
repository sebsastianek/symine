<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Service\Activity\ActivityEvent;

/** One submission of the search form: what was asked, where it was looked for and the page of results found. */
final readonly class SearchRun
{
    /**
     * @param string                   $question          what was typed, empty when it holds no usable word
     * @param string                   $searchAttachments "0" (no), "1" (also) or "only"
     * @param string                   $scopeParam        params[:scope]: all, my_projects, bookmarks, subprojects or ""
     * @param list<string>             $objectTypes       the kinds of objects that can be searched here
     * @param list<string>             $scope             the kinds of objects that were searched
     * @param list<string>             $tokens            the words of the question
     * @param list<ActivityEvent>|null $results           the page of results, null when nothing was searched
     * @param array<string, int>       $countByType       number of results per kind of object, in the order found
     * @param array{page: int, pages: int, count: int, limit: int, offset: int}|null $pager
     * @param ?int                     $offset            null when nothing was searched from the web form
     */
    public function __construct(
        public string $question,
        public bool $allWords,
        public bool $titlesOnly,
        public string $searchAttachments,
        public bool $openIssues,
        public string $scopeParam,
        public array $objectTypes,
        public array $scope,
        public array $tokens,
        public ?array $results,
        public int $resultCount,
        public array $countByType,
        public ?array $pager,
        public int $limit,
        public ?int $offset,
    ) {
    }
}

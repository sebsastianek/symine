<?php

declare(strict_types=1);

namespace App\Plugin\Search;

use App\Entity\Project;
use App\Entity\User;
use App\Service\Activity\ActivityEvent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A search provider (Redmine::Search.register :type + acts_as_searchable). Registered automatically.
 *
 * The type (plural, e.g. "hello_greetings") is a checkbox of the search form labelled
 * "label_<singular>_plural"; searching a single project requires the "view_<type>" permission
 * (like Redmine).
 */
#[AutoconfigureTag(self::TAG)]
interface SearchProviderInterface
{
    public const string TAG = 'symine.search_provider';

    public function getSearchType(): string;

    /**
     * acts_as_searchable#search_result_ranks_and_ids: [rank (timestamp), id] of the records visible by the
     * user matching the tokens.
     *
     * @param list<string>       $tokens
     * @param list<Project>|null $projects null = all projects
     * @param array{all_words?: bool, titles_only?: bool, attachments?: string, open_issues?: bool} $options
     *
     * @return list<array{0: int, 1: int}>
     */
    public function resultRanksAndIds(array $tokens, User $user, ?array $projects, array $options): array;

    /**
     * Search results (event_title, event_url, event_datetime...) for the given ids.
     *
     * @param list<int> $ids
     *
     * @return array<int, ActivityEvent> id => event
     */
    public function results(array $ids): array;
}

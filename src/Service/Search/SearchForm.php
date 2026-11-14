<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Issue;
use App\Entity\Project;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\Activity\SearchService;
use App\Service\IssueService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The search form (search#index): reads q, scope, the kinds of objects ticked and the options, and runs the
 * search for the page asked.
 *
 * Fields: q, scope, all_words, titles_only, attachments, open_issues, one field per kind of object (issues,
 * news, ...), page; offset and limit for the REST API.
 */
final class SearchForm
{
    public function __construct(
        private readonly SearchService $search,
        private readonly SearchProjects $projects,
        private readonly IssueService $issues,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Quick jump to an issue: the issue whose number ("123" or "#123") is all that was typed, when the user may see it */
    public function quickJump(Request $request): ?Issue
    {
        if (!preg_match('/^#?(\d+)$/', self::question($request), $m)) {
            return null;
        }
        $issue = $this->em->find(Issue::class, (int) $m[1]);

        return null !== $issue && $this->issues->isVisible($issue, $this->currentUser->get()) ? $issue : null;
    }

    /** @param bool $api the REST API pages with offset and limit instead of the search_results_per_page setting */
    public function submit(Request $request, ?Project $project, bool $api): SearchRun
    {
        $user = $this->currentUser->get();
        $q = $request->query;
        $question = self::question($request);
        $allWords = $q->has('all_words') ? '' !== (string) $q->get('all_words') : true;
        $titlesOnly = $q->has('titles_only') && '' !== (string) $q->get('titles_only');
        $searchAttachments = (string) $q->get('attachments', '') ?: '0';
        $openIssues = $q->has('open_issues') && '' !== (string) $q->get('open_issues');

        $scopeParam = (string) $q->get('scope', '');
        $projectsToSearch = $this->projects->toSearch($scopeParam, $project);
        $singleProject = 'subprojects' !== $scopeParam && !\in_array($scopeParam, ['all', 'my_projects', 'bookmarks'], true) ? $project : null;

        $objectTypes = $this->search->types();
        if (null !== $singleProject) {
            $objectTypes = array_values(array_filter($objectTypes, fn (string $t) => 'projects' !== $t && $this->auth->allowedTo('view_'.$t, $singleProject)));
        }
        $scope = array_values(array_filter($objectTypes, static fn (string $t) => '' !== (string) $q->get($t, '')));
        if ([] === $scope) {
            $scope = $objectTypes;
        }

        $tokens = SearchService::tokens($question);
        $results = null;
        $resultCount = 0;
        $countByType = [];
        $pager = null;
        if ($api) {
            $limit = max(1, min(100, (int) ($q->get('limit') ?: 25)));
            $offset = max(0, (int) $q->get('offset', 0));
            if ($q->has('page')) {
                $offset = max(0, ((int) $q->get('page') - 1) * $limit);
            }
        } else {
            $limit = $this->settings->int('search_results_per_page') ?: 10;
            $offset = null;
        }
        if ([] !== $tokens) {
            $ids = $this->search->resultIds($tokens, $user, $scope, $projectsToSearch, [
                'all_words' => $allWords, 'titles_only' => $titlesOnly, 'attachments' => $searchAttachments, 'open_issues' => $openIssues,
            ]);
            $resultCount = \count($ids);
            foreach ($ids as [$type]) {
                $countByType[$type] = ($countByType[$type] ?? 0) + 1;
            }
            $pages = max(1, (int) ceil($resultCount / $limit));
            $page = max(1, (int) $q->get('page', 1));
            $offset ??= ($page - 1) * $limit;
            $pager = ['page' => $page, 'pages' => $pages, 'count' => $resultCount, 'limit' => $limit, 'offset' => $offset];
            $results = $this->search->results(\array_slice($ids, $offset, $limit));
        } else {
            $question = '';
        }

        return new SearchRun($question, $allWords, $titlesOnly, $searchAttachments, $openIssues, $scopeParam, $objectTypes, $scope, $tokens, $results, $resultCount, $countByType, $pager, $limit, $offset);
    }

    private static function question(Request $request): string
    {
        return trim((string) $request->query->get('q', ''));
    }
}

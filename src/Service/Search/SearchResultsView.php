<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Project;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/** What the search page shows for a search: the form as submitted, the results, their counts by kind and the pages. */
final class SearchResultsView
{
    public function __construct(
        private readonly SearchProjects $projects,
        private readonly RouterInterface $router,
    ) {
    }

    /**
     * Variables of search/index.html.twig.
     *
     * @return array<string, mixed>
     */
    public function view(SearchRun $search, Request $request, ?Project $project): array
    {
        $q = $request->query;
        // render_results_by_type: sort_by(count).reverse (stable on insertion order)
        $countByType = $search->countByType;
        $keys = array_keys($countByType);
        $pos = array_flip($keys);
        usort($keys, static fn (string $a, string $b) => [$countByType[$a], $pos[$a]] <=> [$countByType[$b], $pos[$b]]);
        $countByType = array_combine(array_reverse($keys), array_map(static fn (string $k) => $countByType[$k], array_reverse($keys))) ?: [];
        $route = 'search';
        $routeParams = [];
        if ('project_search' === $request->attributes->get('_route') && null !== $project) {
            $route = 'project_search';
            $routeParams = ['id' => $project->identifier];
        } elseif (null !== $project) {
            $routeParams = ['id' => $project->identifier];
        }

        $countLinks = [];
        foreach ($countByType as $t => $c) {
            $params = array_filter(['q' => $q->get('q'), 'titles_only' => $q->get('titles_only'), 'all_words' => $q->get('all_words'), 'scope' => $q->get('scope')], static fn ($v) => null !== $v);
            $params[$t] = 1;
            ksort($params);
            $countLinks[] = ['type' => $t, 'count' => $c, 'url' => str_replace('%20', '+', $this->router->generate($route, $routeParams + $params))];
        }
        $pager = $search->pager;

        return [
            'count_links' => $countLinks,
            'project' => $project,
            'question' => $search->question,
            'all_words' => $search->allWords,
            'titles_only' => $search->titlesOnly,
            'search_attachments' => $search->searchAttachments,
            'open_issues' => $search->openIssues,
            'object_types' => $search->objectTypes,
            'scope' => $search->scope,
            'scope_param' => $search->scopeParam,
            'scope_options' => $this->projects->scopeOptions($project),
            'tokens' => $search->tokens,
            'results' => $search->results,
            'result_count' => $search->resultCount,
            'result_count_by_type' => $countByType,
            'pager' => $pager,
            'linked_pages' => null === $pager ? [] : self::linkedPages($pager['page'], $pager['pages'], $pager['count']),
            'route' => $route,
            'route_params' => $routeParams,
            'issues_filter_url' => $this->issuesFilterUrl($search, $project),
        ];
    }

    /** Redmine::Pagination::Paginator#linked_pages @return list<int> */
    public static function linkedPages(int $page, int $last, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $pages = [1, $last];
        for ($p = $page - 2; $p <= $page + 2; ++$p) {
            if ($p > 1 && $p < $last) {
                $pages[] = $p;
            }
        }
        $pages = array_values(array_unique($pages));
        sort($pages);

        return \count($pages) > 1 ? $pages : [];
    }

    /** SearchHelper#issues_filter_path */
    private function issuesFilterUrl(SearchRun $search, ?Project $project): ?string
    {
        if (!$this->router->getRouteCollection()->get('issues_index')) {
            return null;
        }
        $field = $search->titlesOnly ? 'subject' : 'any_searchable';
        $params = [
            'set_filter' => 1,
            'f' => ['status_id', $field],
            'op' => ['status_id' => $search->openIssues ? 'o' : '*', $field => $search->allWords ? '~' : '*~'],
            'v' => [$field => [$search->question]],
            'sort' => 'updated_on:desc',
        ];
        switch ($search->scopeParam) {
            case 'all':
                break;
            case 'my_projects':
            case 'bookmarks':
                $params['f'][] = 'project_id';
                $params['op']['project_id'] = '=';
                $params['v']['project_id'] = ['my_projects' === $search->scopeParam ? 'mine' : 'bookmarks'];
                break;
            case 'subprojects':
                $params['f'][] = 'subproject_id';
                $params['op']['subproject_id'] = '*';
                $params['project_id'] = $project?->id;
                break;
            default:
                if (null !== $project) {
                    $params['f'][] = 'subproject_id';
                    $params['op']['subproject_id'] = '!*';
                    $params['project_id'] = $project->id;
                }
        }

        return $this->router->generate('issues_index', $params);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Entity\Issue;
use App\Entity\Project;
use App\Service\Activity\ActivityEvent;
use App\Service\Activity\SearchService;
use App\Service\IssueService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's SearchController. */
final class SearchController extends AbstractController
{
    public function __construct(
        private readonly SearchService $search,
        private readonly IssueService $issues,
        private readonly ApiRenderer $api,
    ) {
    }

    #[Route('/search.{_format}', name: 'search', defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'], methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $id = $request->query->get('id');

        return $this->doSearch($request, null !== $id && '' !== $id ? $this->findProject((string) $id) : null);
    }

    #[Route('/projects/{id}/search.{_format}', name: 'project_search', defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'], methods: ['GET'])]
    public function project(Request $request, string $id): Response
    {
        return $this->doSearch($request, $this->findProject($id));
    }

    private function doSearch(Request $request, ?Project $project): Response
    {
        $this->authorize('search_project', $project, null === $project);
        $user = $this->user();
        $q = $request->query;
        $question = trim((string) $q->get('q', ''));
        $allWords = $q->has('all_words') ? '' !== (string) $q->get('all_words') : true;
        $titlesOnly = $q->has('titles_only') && '' !== (string) $q->get('titles_only');
        $searchAttachments = (string) $q->get('attachments', '') ?: '0';
        $openIssues = $q->has('open_issues') && '' !== (string) $q->get('open_issues');
        $isApi = $this->isApi($request);

        // quick jump to an issue
        if (!$isApi && preg_match('/^#?(\d+)$/', $question, $m)) {
            $issue = $this->em->find(Issue::class, (int) $m[1]);
            if (null !== $issue && $this->issues->isVisible($issue, $user)) {
                return $this->redirect($request->getBaseUrl().'/issues/'.$issue->id);
            }
        }

        $scopeParam = (string) $q->get('scope', '');
        $projectsToSearch = match ($scopeParam) {
            'all' => null,
            'my_projects' => $this->myProjects(),
            'bookmarks' => $this->bookmarkedProjects(),
            'subprojects' => null !== $project ? $project->getSelfAndDescendants() : null,
            default => null !== $project ? [$project] : null,
        };
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
        if ($isApi) {
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

        if ($isApi) {
            $base = $request->getSchemeAndHttpHost().$request->getBaseUrl();

            return $this->api->render($request, [
                'results' => array_map(static fn (ActivityEvent $e) => [
                    'id' => $e->id,
                    'title' => $e->title,
                    'type' => $e->type,
                    'url' => $base.$e->url,
                    'description' => $e->description,
                    'datetime' => ApiRenderer::time($e->datetime),
                ], $results ?? []),
                'total_count' => [] === $tokens ? null : $resultCount,
                'offset' => $offset ?? 0,
                'limit' => $limit,
            ]);
        }

        // render_results_by_type: sort_by(count).reverse (stable on insertion order)
        $keys = array_keys($countByType);
        $pos = array_flip($keys);
        usort($keys, static fn (string $a, string $b) => [$countByType[$a], $pos[$a]] <=> [$countByType[$b], $pos[$b]]);
        $countByType = array_combine(array_reverse($keys), array_map(static fn (string $k) => $countByType[$k], array_reverse($keys))) ?: [];
        $route = 'search';
        $routeParams = [];
        if ($request->attributes->get('_route') === 'project_search' && null !== $project) {
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
            $countLinks[] = ['type' => $t, 'count' => $c, 'url' => str_replace('%20', '+', $this->generateUrl($route, $routeParams + $params))];
        }

        return $this->render('search/index.html.twig', [
            'count_links' => $countLinks,
            'project' => $project,
            'question' => $question,
            'all_words' => $allWords,
            'titles_only' => $titlesOnly,
            'search_attachments' => $searchAttachments,
            'open_issues' => $openIssues,
            'object_types' => $objectTypes,
            'scope' => $scope,
            'scope_param' => $scopeParam,
            'scope_options' => $this->scopeOptions($project),
            'tokens' => $tokens,
            'results' => $results,
            'result_count' => $resultCount,
            'result_count_by_type' => $countByType,
            'pager' => $pager,
            'linked_pages' => null === $pager ? [] : self::linkedPages($pager['page'], $pager['pages'], $pager['count']),
            'route' => $route,
            'route_params' => $routeParams,
            'issues_filter_url' => $this->issuesFilterUrl($question, $scopeParam, $project, $allWords, $titlesOnly, $openIssues),
            'page_title' => $this->l('label_search'),
            'body_css' => 'controller-search action-index',
        ]);
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

    /** @return list<Project> User#projects (memberships) */
    private function myProjects(): array
    {
        $user = $this->user();
        if (!$user->isLogged()) {
            return [];
        }

        return array_values(array_map(static fn ($m) => $m->project, $this->auth->membershipsOf($user)));
    }

    /** @return list<int> */
    private function bookmarkedProjectIds(): array
    {
        $user = $this->user();
        if (!$user->isLogged()) {
            return [];
        }
        $v = $user->getPref()->get('bookmarked_project_ids');
        $ids = \is_array($v) ? $v : explode(',', (string) $v);

        return array_values(array_filter(array_map('intval', $ids)));
    }

    /** @return list<Project> */
    private function bookmarkedProjects(): array
    {
        $ids = $this->bookmarkedProjectIds();

        return [] === $ids ? [] : $this->em->getRepository(Project::class)->findBy(['id' => $ids]);
    }

    /** SearchHelper#project_select_tag options @return list<array{0: string, 1: string}> */
    private function scopeOptions(?Project $project): array
    {
        $options = [[$this->l('label_project_all'), 'all']];
        if ([] !== $this->myProjects()) {
            $options[] = [$this->l('label_my_projects'), 'my_projects'];
        }
        if ([] !== $this->bookmarkedProjectIds()) {
            $options[] = [$this->l('label_my_bookmarks'), 'bookmarks'];
        }
        if (null !== $project) {
            $activeDescendants = array_filter($project->getSelfAndDescendants(), static fn (Project $p) => $p !== $project && $p->isActive());
            if ([] !== $activeDescendants) {
                $options[] = [$this->l('label_and_its_subprojects', ['value' => $project->name]), 'subprojects'];
            }
            $options[] = [$project->name, ''];
        }

        return \count($options) > 1 ? $options : [];
    }

    /** SearchHelper#issues_filter_path */
    private function issuesFilterUrl(string $question, string $scope, ?Project $project, bool $allWords, bool $titlesOnly, bool $openIssues): ?string
    {
        if (!$this->container->get('router')->getRouteCollection()->get('issues_index')) {
            return null;
        }
        $field = $titlesOnly ? 'subject' : 'any_searchable';
        $params = [
            'set_filter' => 1,
            'f' => ['status_id', $field],
            'op' => ['status_id' => $openIssues ? 'o' : '*', $field => $allWords ? '~' : '*~'],
            'v' => [$field => [$question]],
            'sort' => 'updated_on:desc',
        ];
        switch ($scope) {
            case 'all':
                break;
            case 'my_projects':
            case 'bookmarks':
                $params['f'][] = 'project_id';
                $params['op']['project_id'] = '=';
                $params['v']['project_id'] = ['my_projects' === $scope ? 'mine' : 'bookmarks'];
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

        return $this->generateUrl('issues_index', $params);
    }
}

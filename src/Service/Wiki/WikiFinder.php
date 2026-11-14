<?php

declare(strict_types=1);

namespace App\Service\Wiki;

use App\Entity\Wiki;
use App\Entity\WikiContent;
use App\Entity\WikiContentVersion;
use App\Entity\WikiPage;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Service\WikiService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads what the wiki actions work on: the wiki of a visible project, an existing page, its content and one
 * of its versions (404 when there is none).
 */
final class WikiFinder
{
    public function __construct(
        private readonly WikiService $wikis,
        private readonly AccessGuard $guard,
        private readonly Authorization $auth,
    ) {
    }

    /** The wiki of the project (its project is $wiki->project) */
    public function wiki(string $projectId): Wiki
    {
        $project = $this->guard->findProject($projectId);
        $wiki = $this->wikis->wikiFor($project);
        if (null === $wiki) {
            throw new NotFoundHttpException();
        }

        return $wiki;
    }

    /** An existing page; one that was renamed is found by its old title (see WikiService::pageFoundWithRedirect()) */
    public function page(Wiki $wiki, string $title): WikiPage
    {
        $page = $this->wikis->findPage($wiki, $title);
        if (null === $page) {
            throw new NotFoundHttpException();
        }

        return $page;
    }

    public function content(WikiPage $page): WikiContent
    {
        return $page->content ?? throw new NotFoundHttpException();
    }

    public function version(WikiPage $page, int $version): WikiContentVersion
    {
        $v = $page->content ? $this->wikis->version($page->content, $version) : null;

        return $v ?? throw new NotFoundHttpException();
    }

    /**
     * The route parameters of a page found by an old title, to redirect to its present URL (404 when it
     * moved to a project the user cannot see).
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function routeOf(WikiPage $page, array $extra = []): array
    {
        $project = $page->wiki->project;
        if (!$this->auth->isProjectVisible($project)) {
            throw new NotFoundHttpException();
        }

        return ['project_id' => $project->identifier, 'id' => $page->title] + $extra;
    }
}

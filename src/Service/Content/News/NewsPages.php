<?php

declare(strict_types=1);

namespace App\Service\Content\News;

use App\Entity\Comment;
use App\Entity\News;
use App\Entity\Project;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Service\AttachmentService;
use App\Service\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What the news pages show: the list (HTML, 10 per page, and Atom) and a news item with its comments.
 */
final class NewsPages
{
    /** News per page of the HTML list and of the feed (news#index: @limit = 10) */
    private const int PER_PAGE = 10;

    public function __construct(
        private readonly NewsService $news,
        private readonly AttachmentService $attachments,
        private readonly Authorization $auth,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * The pager of the HTML list and of the feed, which do not follow the "objects per page" setting.
     *
     * @return array{offset: int, limit: int, page: int, count: int, pages: int, per_page_options: list<int>}
     */
    public function pager(Request $request, int $count): array
    {
        $limit = self::PER_PAGE;
        $pages = max(1, (int) ceil($count / $limit));
        $page = max(1, min($pages, (int) $request->query->get('page', 1)));

        return ['offset' => ($page - 1) * $limit, 'limit' => $limit, 'page' => $page, 'count' => $count, 'pages' => $pages, 'per_page_options' => [$limit]];
    }

    /**
     * @param list<News>           $newss
     * @param array<string, mixed> $pager
     *
     * @return array<string, mixed>
     */
    public function index(?Project $project, array $newss, array $pager): array
    {
        $news = new News();
        $news->project = $project;

        return [
            'project' => $project, 'newss' => $newss, 'pager' => $pager, 'news' => $news, 'errors' => [],
            'manage_projects' => $project ? [] : $this->news->manageableProjects(),
            'news_module_id' => $project ? $this->news->moduleId($project) : null,
        ];
    }

    public function feedTitle(?Project $project): string
    {
        return ($project ? $project->name : $this->settings->appTitle()).': '.$this->i18n->l('label_news_plural');
    }

    /**
     * @param list<News> $newss
     *
     * @return list<array<string, mixed>> the entries given to App\Service\AtomFeed
     */
    public function feedItems(array $newss): array
    {
        return array_map(fn (News $n) => [
            'title' => $n->title, 'url' => $this->urls->generate('news_show', ['id' => $n->id]), 'updated' => $n->createdOn ?? new \DateTimeImmutable(),
            'author' => $n->author, 'content' => $n->description, 'project' => $n->project, 'object' => $n,
        ], $newss);
    }

    /**
     * @param list<Comment> $comments
     *
     * @return array<string, mixed>
     */
    public function show(News $news, array $comments): array
    {
        return [
            'project' => $news->project, 'news' => $news, 'comments' => $comments, 'errors' => [],
            'attachments' => $this->attachments->forContainer('News', $news->id),
            'commentable' => $this->auth->allowedTo('comment_news', $news->project),
        ];
    }
}

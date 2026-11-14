<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\NewsApiPresenter;
use App\Service\AtomFeed;
use App\Service\Content\News\NewsFinder;
use App\Service\Content\News\NewsForm;
use App\Service\Content\News\NewsPages;
use App\Service\Content\News\NewsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of NewsController (news_controller.rb). */
final class NewsController extends AbstractController
{
    public function __construct(
        private readonly ApiRenderer $api,
        private readonly AtomFeed $feed,
        private readonly NewsFinder $finder,
        private readonly NewsService $news,
        private readonly NewsPages $pages,
        private readonly NewsForm $form,
        private readonly NewsApiPresenter $presenter,
    ) {
    }

    #[Route('/news.{_format}', name: 'news_index', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml|atom'])]
    public function index(Request $request): Response
    {
        $format = $request->getRequestFormat();
        $count = $this->news->count(null);
        if ('json' === $format || 'xml' === $format) {
            $pager = $this->paginate($request, $count);

            return $this->api->render($request, $this->presenter->index($this->news->page(null, $pager['offset'], $pager['limit']), $count, $pager));
        }
        $pager = $this->pages->pager($request, $count);
        $newss = $this->news->page(null, $pager['offset'], $pager['limit']);
        if ('atom' === $format) {
            return $this->feed->render($request, $this->pages->feedTitle(null), $this->pages->feedItems($newss), null);
        }

        return $this->render('news/index.html.twig', $this->pages->index(null, $newss, $pager) + [
            'main_menu_selected' => 'news', 'page_title' => $this->l('label_news_plural'),
            'body_css' => 'controller-news action-index',
        ]);
    }

    #[Route('/projects/{project_id}/news.{_format}', name: 'project_news', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml|atom'])]
    public function projectIndex(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('view_news', $project);

        $format = $request->getRequestFormat();
        $count = $this->news->count($project);
        if ('json' === $format || 'xml' === $format) {
            $pager = $this->paginate($request, $count);

            return $this->api->render($request, $this->presenter->index($this->news->page($project, $pager['offset'], $pager['limit']), $count, $pager));
        }
        $pager = $this->pages->pager($request, $count);
        $newss = $this->news->page($project, $pager['offset'], $pager['limit']);
        if ('atom' === $format) {
            return $this->feed->render($request, $this->pages->feedTitle($project), $this->pages->feedItems($newss), $project);
        }

        return $this->render('news/index.html.twig', $this->pages->index($project, $newss, $pager) + [
            'main_menu_selected' => 'news', 'page_title' => $this->l('label_news_plural'),
            'body_css' => 'controller-news action-index',
        ]);
    }

    #[Route('/news/new', name: 'news_new_global', methods: ['GET'])]
    #[Route('/projects/{project_id}/news/new', name: 'news_new', methods: ['GET'])]
    public function new(?string $project_id = null): Response
    {
        $project = null === $project_id ? null : $this->findProject($project_id);
        if (!$this->auth->allowedTo('manage_news', $project, null, true)) {
            $this->deny();
        }

        return $this->render('news/new.html.twig', $this->form->view($this->news->build($project), $project) + [
            'main_menu_selected' => 'news', 'page_title' => $this->l('label_news_plural'),
            'body_css' => 'controller-news action-new',
        ]);
    }

    #[Route('/news.{_format}', name: 'news_create_global', methods: ['POST'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function createGlobal(Request $request): Response
    {
        return $this->create($request, $this->finder->submittedProjectId($request));
    }

    #[Route('/projects/{project_id}/news.{_format}', name: 'project_news_create', methods: ['POST'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function create(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_news', $project);
        $news = $this->news->build($project);
        $result = $this->form->create($news, $request);
        if ($this->isApi($request)) {
            return $result->isSaved() ? $this->api->noContent() : $this->api->errors($request, $result->errors);
        }
        if (!$result->isSaved()) {
            return $this->render('news/new.html.twig', $this->form->view($news, $request->request->has('cross_project') ? null : $project, $result->errors) + [
                'main_menu_selected' => 'news', 'page_title' => $this->l('label_news_plural'),
                'body_css' => 'controller-news action-new',
            ], new Response(null, 422));
        }
        if ($result->unsavedAttachments > 0) {
            $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => $result->unsavedAttachments]));
        }
        $this->flashNotice('notice_successful_create');

        return $request->request->has('cross_project') ? $this->redirectToRoute('news_index') : $this->redirectToRoute('project_news', ['project_id' => $project->identifier]);
    }

    #[Route('/news/{id}.{_format}', name: 'news_show', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function show(Request $request, int $id): Response
    {
        $news = $this->finder->find($id);
        $this->authorize('view_news', $news->project);
        $comments = $this->finder->comments($news);
        if ($this->isApi($request)) {
            return $this->api->render($request, ['news' => $this->presenter->shown($request, $news, $comments)], 200, NewsApiPresenter::XML_ATTRIBUTES);
        }

        return $this->render('news/show.html.twig', $this->pages->show($news, $comments) + [
            'main_menu_selected' => 'news', 'page_title' => $news->title,
            'body_css' => 'controller-news action-show',
        ]);
    }

    #[Route('/news/{id}/edit', name: 'news_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $news = $this->finder->find($id);
        $this->authorize('manage_news', $news->project);

        return $this->render('news/edit.html.twig', $this->form->view($news, $news->project) + [
            'main_menu_selected' => 'news', 'page_title' => $this->l('label_news_plural'),
            'body_css' => 'controller-news action-edit',
        ]);
    }

    #[Route('/news/{id}.{_format}', name: 'news_update', methods: ['PUT', 'PATCH'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function update(Request $request, int $id): Response
    {
        $news = $this->finder->find($id);
        $this->authorize('manage_news', $news->project);
        $result = $this->form->update($news, $request);
        if ($this->isApi($request)) {
            return $result->isSaved() ? $this->api->noContent() : $this->api->errors($request, $result->errors);
        }
        if (!$result->isSaved()) {
            return $this->render('news/edit.html.twig', $this->form->view($news, $news->project, $result->errors) + [
                'main_menu_selected' => 'news', 'page_title' => $this->l('label_news_plural'),
                'body_css' => 'controller-news action-edit',
            ], new Response(null, 422));
        }
        if ($result->unsavedAttachments > 0) {
            $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => $result->unsavedAttachments]));
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('news_show', ['id' => $news->id]);
    }

    #[Route('/news/{id}.{_format}', name: 'news_destroy', methods: ['DELETE'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function destroy(Request $request, int $id): Response
    {
        $news = $this->finder->find($id);
        $project = $news->project;
        $this->authorize('manage_news', $project);
        $this->news->destroy($news);
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }
        $this->flashNotice('notice_successful_delete');

        return $this->redirectToRoute('project_news', ['project_id' => $project->identifier]);
    }
}

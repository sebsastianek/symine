<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\News;
use App\Entity\Project;
use App\Event\ContentEvent;
use App\Service\AtomFeed;
use App\Service\AttachmentService;
use App\Service\WatcherService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of NewsController (news_controller.rb). */
final class NewsController extends AbstractController
{
    public function __construct(
        private readonly ApiRenderer $api,
        private readonly AttachmentService $attachments,
        private readonly WatcherService $watchers,
        private readonly AtomFeed $feed,
        private readonly EventDispatcherInterface $events,
        private readonly \App\Service\ReactionService $reactions,
    ) {
    }

    #[Route('/news.{_format}', name: 'news_index', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml|atom'])]
    public function index(Request $request): Response
    {
        return $this->doIndex($request, null);
    }

    #[Route('/projects/{project_id}/news.{_format}', name: 'project_news', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml|atom'])]
    public function projectIndex(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('view_news', $project);

        return $this->doIndex($request, $project);
    }

    private function doIndex(Request $request, ?Project $project): Response
    {
        $format = $request->getRequestFormat();
        $cond = $this->auth->allowedToCondition('view_news', 'IDENTITY(n.project)');
        $where = $cond.($project ? ' AND n.project = :p' : '');
        $params = $project ? ['p' => $project->id] : [];
        $count = (int) $this->em->createQuery("SELECT COUNT(n.id) FROM App\Entity\News n JOIN n.project pr WHERE $where")->setParameters($params)->getSingleScalarResult();

        if ('json' === $format || 'xml' === $format) {
            $pager = $this->paginate($request, $count);
        } else {
            $limit = 10;
            $pages = max(1, (int) ceil($count / $limit));
            $page = max(1, min($pages, (int) $request->query->get('page', 1)));
            $pager = ['offset' => ($page - 1) * $limit, 'limit' => $limit, 'page' => $page, 'count' => $count, 'pages' => $pages, 'per_page_options' => [$limit]];
        }
        $newss = $this->em->createQuery("SELECT n, pr, a FROM App\Entity\News n JOIN n.project pr JOIN n.author a WHERE $where ORDER BY n.createdOn DESC, n.id DESC")
            ->setParameters($params)->setFirstResult($pager['offset'])->setMaxResults($pager['limit'])->getResult();

        if ('json' === $format || 'xml' === $format) {
            return $this->api->render($request, [
                'news' => array_map(fn (News $n) => $this->newsApi($n, false), $newss),
                'total_count' => $count, 'offset' => $pager['offset'], 'limit' => $pager['limit'],
            ]);
        }
        if ('atom' === $format) {
            $items = array_map(fn (News $n) => [
                'title' => $n->title, 'url' => $this->generateUrl('news_show', ['id' => $n->id]), 'updated' => $n->createdOn ?? new \DateTimeImmutable(),
                'author' => $n->author, 'content' => $n->description, 'project' => $n->project,
            ], $newss);

            return $this->feed->render($request, ($project ? $project->name : $this->settings->appTitle()).': '.$this->l('label_news_plural'), $items, $project);
        }

        $news = new News();
        $news->project = $project;

        return $this->render('news/index.html.twig', [
            'project' => $project, 'newss' => $newss, 'pager' => $pager, 'news' => $news, 'errors' => [],
            'manage_projects' => $project ? [] : $this->manageableProjects(),
            'news_module_id' => $project ? $this->newsModuleId($project) : null,
            'main_menu_selected' => 'news', 'page_title' => $this->l('label_news_plural'),
            'body_css' => 'controller-news action-index',
        ]);
    }

    #[Route('/news/new', name: 'news_new_global', methods: ['GET'])]
    public function newGlobal(): Response
    {
        return $this->doNew(null);
    }

    #[Route('/projects/{project_id}/news/new', name: 'news_new', methods: ['GET'])]
    public function new(string $project_id): Response
    {
        return $this->doNew($this->findProject($project_id));
    }

    private function doNew(?Project $project): Response
    {
        if (!$this->auth->allowedTo('manage_news', $project, null, true)) {
            $this->deny();
        }
        $news = new News();
        $news->project = $project;
        $news->author = $this->user();

        return $this->renderForm('news/new.html.twig', $news, $project, []);
    }

    #[Route('/news.{_format}', name: 'news_create_global', methods: ['POST'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function createGlobal(Request $request): Response
    {
        $projectId = $request->request->get('project_id') ?? $request->query->get('project_id');
        if ((null === $projectId || '' === $projectId) && $this->isApi($request)) {
            // JSON/XML body parameters are merged into params like Rails does
            $projectId = ApiRenderer::payload($request)['project_id'] ?? null;
            $projectId = \is_scalar($projectId) ? (string) $projectId : null;
        }
        if (null === $projectId || '' === $projectId) {
            throw $this->createNotFoundException();
        }

        return $this->doCreate($request, $this->findProject((string) $projectId));
    }

    #[Route('/projects/{project_id}/news.{_format}', name: 'project_news_create', methods: ['POST'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function create(Request $request, string $project_id): Response
    {
        return $this->doCreate($request, $this->findProject($project_id));
    }

    private function doCreate(Request $request, Project $project): Response
    {
        $this->authorize('manage_news', $project);
        $isApi = $this->isApi($request);
        $news = new News();
        $news->project = $project;
        $news->author = $this->user();
        $attrs = $this->newsParams($request);
        $this->assign($news, $attrs);
        $errors = $this->validate($news);
        [$atts, $attErrors] = $this->attachments->fromRequest($request, $this->user(), $isApi ? ($attrs['uploads'] ?? null) : null);
        if ([] !== $errors) {
            if ($isApi) {
                return $this->api->errors($request, $errors);
            }

            return $this->renderForm('news/new.html.twig', $news, $request->request->has('cross_project') ? null : $project, $errors, 422);
        }
        $this->em->persist($news);
        $this->em->flush();
        $this->attachments->attachTo($atts, 'News', (int) $news->id);
        $this->watchers->add('News', (int) $news->id, $news->author);
        $this->em->flush();
        $this->events->dispatch(new ContentEvent('news', 'created', $news, $this->user(), ['attachments' => $atts]));
        if ($isApi) {
            return $this->api->noContent();
        }
        $this->attachmentWarning($attErrors);
        $this->flashNotice('notice_successful_create');

        return $this->redirect($request->request->has('cross_project') ? $this->generateUrl('news_index') : $this->generateUrl('project_news', ['project_id' => $project->identifier]));
    }

    #[Route('/news/{id}.{_format}', name: 'news_show', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function show(Request $request, int $id): Response
    {
        $news = $this->findNews($id);
        $this->authorize('view_news', $news->project);
        $comments = $this->em->getRepository(Comment::class)->findBy(['commentedType' => 'News', 'commentedId' => $news->id], ['createdOn' => 'ASC', 'id' => 'ASC']);
        if ($this->user()->isLogged() && 'desc' === $this->user()->getPref()->commentsSorting()) {
            $comments = array_reverse($comments);
        }
        if ($this->isApi($request)) {
            $include = array_map('trim', explode(',', (string) $request->query->get('include', '')));
            $data = $this->newsApi($news, true);
            $withReactions = \in_array('reactions', $include, true);
            if ($withReactions && null !== ($r = $this->reactions->apiReactions($news))) {
                $data['reactions'] = $r;
            }
            if (\in_array('attachments', $include, true)) {
                $data['attachments'] = array_map(fn (Attachment $a) => $this->attachmentApi($request, $a), $this->attachments->forContainer('News', $news->id));
            }
            if (\in_array('comments', $include, true)) {
                if ($withReactions) {
                    $this->reactions->preload($comments);
                }
                $data['comments'] = array_map(fn (Comment $c) => [
                    'id' => $c->id, 'author' => ['id' => $c->author->id, 'name' => $c->author->getName()], 'content' => $c->content,
                ] + ($withReactions && null !== ($r = $this->reactions->apiReactions($c)) ? ['reactions' => $r] : []), $comments);
            }

            return $this->api->render($request, ['news' => $data], 200, ['comment' => ['id']]);
        }

        return $this->render('news/show.html.twig', [
            'project' => $news->project, 'news' => $news, 'comments' => $comments, 'errors' => [],
            'attachments' => $this->attachments->forContainer('News', $news->id),
            'commentable' => $this->auth->allowedTo('comment_news', $news->project),
            'main_menu_selected' => 'news', 'page_title' => $news->title,
            'body_css' => 'controller-news action-show',
        ]);
    }

    #[Route('/news/{id}/edit', name: 'news_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $news = $this->findNews($id);
        $this->authorize('manage_news', $news->project);

        return $this->renderForm('news/edit.html.twig', $news, $news->project, []);
    }

    #[Route('/news/{id}.{_format}', name: 'news_update', methods: ['PUT', 'PATCH'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function update(Request $request, int $id): Response
    {
        $news = $this->findNews($id);
        $this->authorize('manage_news', $news->project);
        $isApi = $this->isApi($request);
        $attrs = $this->newsParams($request);
        $this->assign($news, $attrs);
        $errors = $this->validate($news);
        if ([] !== $errors) {
            if ($isApi) {
                return $this->api->errors($request, $errors);
            }

            return $this->renderForm('news/edit.html.twig', $news, $news->project, $errors, 422);
        }
        [$atts, $attErrors] = $this->attachments->fromRequest($request, $this->user(), $isApi ? ($attrs['uploads'] ?? null) : null);
        $this->attachments->attachTo($atts, 'News', (int) $news->id);
        $this->em->flush();
        $this->events->dispatch(new ContentEvent('news', 'updated', $news, $this->user(), ['attachments' => $atts]));
        if ($isApi) {
            return $this->api->noContent();
        }
        $this->attachmentWarning($attErrors);
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('news_show', ['id' => $news->id]);
    }

    #[Route('/news/{id}.{_format}', name: 'news_destroy', methods: ['DELETE'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function destroy(Request $request, int $id): Response
    {
        $news = $this->findNews($id);
        $project = $news->project;
        $this->authorize('manage_news', $project);
        $this->events->dispatch(new ContentEvent('news', 'deleted', $news, $this->user()));
        $this->em->createQuery("DELETE FROM App\Entity\Comment c WHERE c.commentedType = 'News' AND c.commentedId = :id")->setParameter('id', $news->id)->execute();
        $this->attachments->deleteForContainer('News', (int) $news->id);
        $this->watchers->removeAll('News', (int) $news->id);
        $this->em->remove($news);
        $this->em->flush();
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }
        $this->flashNotice('notice_successful_delete');

        return $this->redirectToRoute('project_news', ['project_id' => $project->identifier]);
    }

    // --- helpers -----------------------------------------------------------------

    private function findNews(int $id): News
    {
        $news = $this->em->find(News::class, $id);
        if (null === $news || null === $news->project) {
            throw $this->createNotFoundException();
        }
        // find_project_from_association: checks the project is visible / not archived
        $this->findProject((int) $news->project->id);

        return $news;
    }

    /** @return array<string, mixed> */
    private function newsParams(Request $request): array
    {
        if ($this->isApi($request)) {
            $payload = ApiRenderer::payload($request);

            return (array) ($payload['news'] ?? []);
        }

        return (array) $request->request->all('news');
    }

    /** safe_attributes 'title', 'summary', 'description' */
    private function assign(News $news, array $attrs): void
    {
        foreach (['title' => 'title', 'summary' => 'summary', 'description' => 'description'] as $key => $prop) {
            if (\array_key_exists($key, $attrs) && !\is_array($attrs[$key])) {
                $news->$prop = 'title' === $key ? (string) $attrs[$key] : (null === $attrs[$key] ? null : (string) $attrs[$key]);
            }
        }
    }

    /** @return list<string> */
    private function validate(News $news): array
    {
        $errors = [];
        if ('' === trim($news->title)) {
            $errors[] = $this->l('field_title').' '.$this->l('activerecord.errors.messages.blank');
        } elseif (mb_strlen($news->title) > 60) {
            $errors[] = $this->l('field_title').' '.$this->l('activerecord.errors.messages.too_long', ['count' => 60]);
        }
        if (mb_strlen((string) $news->summary) > 255) {
            $errors[] = $this->l('field_summary').' '.$this->l('activerecord.errors.messages.too_long', ['count' => 255]);
        }
        if ('' === trim((string) $news->description)) {
            $errors[] = $this->l('field_description').' '.$this->l('activerecord.errors.messages.blank');
        }

        return $errors;
    }

    /** @param list<string> $errors */
    private function renderForm(string $template, News $news, ?Project $project, array $errors, int $status = 200): Response
    {
        return $this->render($template, [
            'project' => $project ?? $news->project, 'form_project' => $project, 'news' => $news, 'errors' => $errors,
            'manage_projects' => null === $project ? $this->manageableProjects() : [],
            'main_menu_selected' => 'news', 'page_title' => $this->l('label_news_plural'),
            'body_css' => 'controller-news action-'.(str_contains($template, 'edit') ? 'edit' : 'new'),
        ], new Response(null, $status));
    }

    /** @return list<Project> projects where the user can manage news (Project.allowed_to(:manage_news)) */
    private function manageableProjects(): array
    {
        $cond = $this->auth->allowedToCondition('manage_news', 'p.id');

        return $this->em->createQuery("SELECT p FROM App\Entity\Project p WHERE $cond ORDER BY p.lft")->getResult();
    }

    private function newsModuleId(Project $project): ?int
    {
        $id = $this->em->getConnection()->fetchOne("SELECT id FROM enabled_modules WHERE project_id = ? AND name = 'news'", [$project->id]);

        return false === $id ? null : (int) $id;
    }

    /** @param list<string> $errors */
    private function attachmentWarning(array $errors): void
    {
        if ([] !== $errors) {
            $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => \count($errors)]));
        }
    }

    /** @return array<string, mixed> */
    private function newsApi(News $n, bool $show): array
    {
        $data = ['id' => $n->id];
        if ($n->project) {
            $data['project'] = ['id' => $n->project->id, 'name' => $n->project->name];
        }
        $data['author'] = ['id' => $n->author->id, 'name' => $n->author->getName()];
        $data['title'] = $n->title;
        if (!$show || '' !== trim((string) $n->summary)) {
            $data['summary'] = $n->summary;
        }
        $data['description'] = $n->description;
        $data['created_on'] = ApiRenderer::time($n->createdOn);

        return $data;
    }

    /** render_api_attachment_attributes */
    private function attachmentApi(Request $request, Attachment $a): array
    {
        $base = $request->getSchemeAndHttpHost().$request->getBaseUrl();
        $data = [
            'id' => $a->id, 'filename' => $a->filename, 'filesize' => $a->getFilesize(), 'content_type' => $a->contentType,
            'description' => $a->description, 'content_url' => $base.'/attachments/download/'.$a->id.'/'.rawurlencode($a->filename),
        ];
        if ($a->isImage()) {
            $data['thumbnail_url'] = $base.'/attachments/thumbnail/'.$a->id;
        }
        $data['author'] = ['id' => $a->author->id, 'name' => $a->author->getName()];
        $data['created_on'] = ApiRenderer::time($a->createdOn);

        return $data;
    }
}

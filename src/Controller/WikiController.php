<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Entity\Attachment;
use App\Entity\Project;
use App\Entity\Wiki;
use App\Entity\WikiContentVersion;
use App\Entity\WikiPage;
use App\Event\ContentEvent;
use App\Service\AttachmentService;
use App\Service\TextFormatter;
use App\Service\Wiki\StaleWikiContentException;
use App\Service\WikiService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's WikiController and WikisController. */
final class WikiController extends AbstractController
{
    private const string FMT = 'json|xml';

    public function __construct(
        private readonly WikiService $wikis,
        private readonly AttachmentService $attachments,
        private readonly ApiRenderer $api,
        private readonly EventDispatcherInterface $events,
        private readonly TextFormatter $formatter,
    ) {
    }

    // ------------------------------------------------------------------ index

    #[Route('/projects/{project_id}/wiki/index.{_format}', name: 'wiki_index_api', requirements: ['_format' => self::FMT], methods: ['GET'], priority: 10)]
    #[Route('/projects/{project_id}/wiki/index', name: 'wiki_index_by_title', methods: ['GET'], priority: 10)]
    public function index(Request $request, string $project_id): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        $this->authorize('view_wiki_pages', $project);
        $pages = $this->wikis->pages($wiki);
        if ($this->isApi($request)) {
            return $this->api->render($request, ['wiki_pages' => array_map(fn (WikiPage $p) => array_filter([
                'title' => $p->title,
                'parent' => $p->parent ? ['title' => $p->parent->title] : null,
                'version' => $p->content?->version,
                'project' => ['id' => $project->id, 'name' => $project->name],
                'created_on' => ApiRenderer::time($p->createdOn),
                'updated_on' => ApiRenderer::time($p->content?->updatedOn),
            ], static fn ($v, $k) => 'parent' !== $k || null !== $v, \ARRAY_FILTER_USE_BOTH), $pages)], 200, ['parent' => ['title']]);
        }

        return $this->render('wiki/index.html.twig', $this->vars($project, $wiki, [
            'pages' => $pages,
            'hierarchy' => $this->hierarchy($pages, true),
            'body_css' => 'controller-wiki action-index',
            'page_title' => $this->l('label_index_by_title'),
        ]));
    }

    #[Route('/projects/{project_id}/wiki/date_index', name: 'wiki_date_index', methods: ['GET'], priority: 10)]
    public function dateIndex(string $project_id): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        $this->authorize('view_wiki_pages', $project);
        $pages = $this->wikis->pages($wiki);
        $byDate = [];
        foreach ($pages as $p) {
            $d = $p->content?->updatedOn;
            $key = $d ? $d->setTimezone($this->i18n->userTimezone())->format('Y-m-d') : '';
            $byDate[$key][] = $p;
        }
        krsort($byDate);

        return $this->render('wiki/date_index.html.twig', $this->vars($project, $wiki, [
            'pages' => $pages,
            'pages_by_date' => $byDate,
            'body_css' => 'controller-wiki action-date_index',
            'page_title' => $this->l('label_index_by_date'),
        ]));
    }

    #[Route('/projects/{project_id}/wiki/export.{_format}', name: 'wiki_export_format', requirements: ['_format' => 'html|zip|pdf'], methods: ['GET'], priority: 10)]
    #[Route('/projects/{project_id}/wiki/export', name: 'wiki_export', methods: ['GET'], priority: 10)]
    public function export(Request $request, string $project_id, string $_format = 'html', ?\App\Pdf\PdfExport $pdfExport = null): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        $this->authorize('export_wiki_pages', $project);
        if ('pdf' === $_format && null !== $pdfExport) {
            return $pdfExport->wikiPages($wiki, $project);
        }
        $pages = $this->wikis->pages($wiki);
        if ('zip' === $_format) {
            $tmp = tempnam(sys_get_temp_dir(), 'wikizip');
            $zip = new \ZipArchive();
            $zip->open($tmp, \ZipArchive::OVERWRITE);
            $names = [];
            foreach ($pages as $page) {
                $base = preg_replace('/[\/?%*:|"\'<>\n\r\x00]+/', '_', str_replace('\\', '_', $page->title));
                $name = $base.'.txt';
                $dup = 0;
                while (isset($names[$name])) {
                    $name = $base.'('.++$dup.').txt';
                }
                $names[$name] = true;
                $zip->addFromString($name, (string) $page->content?->text);
            }
            $zip->close();
            $data = (string) file_get_contents($tmp);
            @unlink($tmp);

            return new Response($data, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="'.$project->identifier.'-wiki.zip"']);
        }
        $html = $this->renderView('wiki/export_multiple.html.twig', [
            'project' => $project,
            'wiki' => $wiki,
            'pages' => $pages,
            'hierarchy' => $this->hierarchy($pages, true, static fn (WikiPage $p) => '#'.$p->title),
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html', 'Content-Disposition' => 'attachment; filename="wiki.html"']);
    }

    // ------------------------------------------------------------------ new

    #[Route('/projects/{project_id}/wiki/new', name: 'wiki_new', methods: ['GET', 'POST'], priority: 10)]
    public function new(Request $request, string $project_id): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        $this->authorize('edit_wiki_pages', $project);
        $page = new WikiPage();
        $page->wiki = $wiki;
        $title = (string) ($request->request->get('title') ?? $request->query->get('title', ''));
        $page->title = Wiki::titleize($title);
        $errors = [];
        $parent = (string) ($request->request->get('parent') ?? $request->query->get('parent', ''));
        if ($request->isMethod('POST')) {
            if (!$this->wikis->isEditable($page)) {
                $page->title = '';
            }
            $errors = array_values(array_filter($this->wikis->validatePage($page), fn ($e) => str_starts_with($e, $this->l('field_title'))));
            if ([] === $errors) {
                $path = $this->generateUrl('wiki_show', array_filter(['project_id' => $project->identifier, 'id' => $page->title, 'parent' => '' !== $parent ? $parent : null]));
                if ($request->isXmlHttpRequest()) {
                    return new Response('window.location = '.json_encode($path).';', 200, ['Content-Type' => 'text/javascript']);
                }

                return $this->redirect($path);
            }
        }
        $vars = $this->vars($project, $wiki, ['page' => $page, 'errors' => $errors, 'title' => $title, 'parent' => $parent, 'body_css' => 'controller-wiki action-new', 'page_title' => $this->l('label_wiki_page_new')]);
        if ($request->isXmlHttpRequest()) {
            $modal = $this->renderView('wiki/_new_modal.html.twig', $vars);

            return new Response("$('#ajax-modal').html(".json_encode($modal)."); showModal('ajax-modal', '600px');", 200, ['Content-Type' => 'text/javascript']);
        }

        return $this->render('wiki/new.html.twig', $vars);
    }

    // ------------------------------------------------------------------ show

    #[Route('/projects/{project_id}/wiki', name: 'wiki_index', methods: ['GET'])]
    #[Route('/projects/{project_id}/wiki/{id}.{_format}', name: 'wiki_show_format', requirements: ['_format' => 'json|xml|html|txt|pdf'], methods: ['GET'])]
    #[Route('/projects/{project_id}/wiki/{id}/{version}.{_format}', name: 'wiki_show_version_format', requirements: ['version' => '\d+', '_format' => self::FMT], methods: ['GET'])]
    #[Route('/projects/{project_id}/wiki/{id}/{version}', name: 'wiki_show_version', requirements: ['version' => '\d+'], methods: ['GET'])]
    #[Route('/projects/{project_id}/wiki/{id}', name: 'wiki_show', methods: ['GET'])]
    public function show(Request $request, string $project_id, ?string $id = null, ?string $version = null, ?string $_format = null, ?\App\Pdf\PdfExport $pdfExport = null): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        $this->authorize('view_wiki_pages', $project);
        $page = $this->wikis->findOrNewPage($wiki, $id);
        if ($this->wikis->pageFoundWithRedirect()) {
            return $this->redirectToPage($page, 'wiki_show');
        }
        $version ??= $request->query->has('version') && '' !== $request->query->get('version') ? (string) $request->query->get('version') : null;
        if (null !== $version && !$this->auth->allowedTo('view_wiki_edits', $project)) {
            $this->deny();
        }
        $isApi = $this->isApi($request);
        $content = null;
        $versionRow = null;
        if (null !== $page->content) {
            if (null !== $version) {
                $versionRow = $this->wikis->version($page->content, (int) $version);
                $content = $versionRow;
            } else {
                $content = $page->content;
            }
        }
        if (null === $content) {
            if (null === $version && $this->auth->allowedTo('edit_wiki_pages', $project) && $this->wikis->isEditable($page) && !$isApi && null === $_format) {
                return $this->edit($request, $project_id, $page->title);
            }
            throw $this->createNotFoundException();
        }
        $this->callHook('controller_wiki_show_before_render', ['content' => $content, 'format' => $_format, 'project' => $project]);
        $text = $versionRow instanceof WikiContentVersion ? $this->wikis->versionText($versionRow) : (string) $page->content->text;
        $current = null === $versionRow || $versionRow->version === $page->content->version;

        if ('pdf' === $_format && null !== $pdfExport) {
            if (!$this->auth->allowedTo('export_wiki_pages', $project)) {
                // respond_to html/api only: unknown format
                return new Response('', 406);
            }

            return $pdfExport->wikiPage($page, $project);
        }
        if (\in_array($_format, ['html', 'txt'], true)) {
            $this->authorize('export_wiki_pages', $project);
            if ('txt' === $_format) {
                return new Response($text, 200, ['Content-Type' => 'text/plain', 'Content-Disposition' => 'attachment; filename="'.$page->title.'.txt"']);
            }
            $html = $this->renderView('wiki/export.html.twig', ['page' => $page, 'project' => $project, 'text' => $text]);

            return new Response($html, 200, ['Content-Type' => 'text/html', 'Content-Disposition' => 'attachment; filename="'.$page->title.'.html"']);
        }

        if ($isApi) {
            $data = [
                'title' => $page->title,
            ];
            if ($page->parent) {
                $data['parent'] = ['title' => $page->parent->title];
            }
            $data['text'] = $text;
            $data['version'] = $content->version;
            if ($content->author) {
                $data['author'] = ['id' => $content->author->id, 'name' => $content->author->getName()];
            }
            $data['comments'] = $content->comments;
            $data['project'] = ['id' => $project->id, 'name' => $project->name];
            $data['created_on'] = ApiRenderer::time($page->createdOn);
            $data['updated_on'] = ApiRenderer::time($content->updatedOn);
            if (\in_array('attachments', explode(',', (string) $request->query->get('include', '')), true)) {
                $data['attachments'] = array_map(fn (Attachment $a) => $this->apiAttachment($request, $a), $this->wikis->attachmentsOf($page));
            }

            return $this->api->render($request, ['wiki_page' => $data], 200, ['parent' => ['title']]);
        }

        $editable = $this->wikis->isEditable($page);
        $sectionsEditable = $editable && $this->auth->allowedTo('edit_wiki_pages', $project) && $current && $this->wikis->supportsSectionEdit();
        $attachments = $this->wikis->attachmentsOf($page);

        return $this->render('wiki/show.html.twig', $this->vars($project, $wiki, [
            'page' => $page,
            'content' => $content,
            'text' => $text,
            'current' => $current,
            'previous' => $versionRow ? $this->wikis->previousVersion($versionRow) : null,
            'next' => $versionRow ? $this->wikis->nextVersion($versionRow) : null,
            'editable' => $editable,
            'html' => $this->formatter->format($text, $project, array_filter([
                'headings' => true,
                'object' => $page,
                'attachments' => $attachments,
                'edit_section_url' => $sectionsEditable ? $this->generateUrl('wiki_edit', ['project_id' => $project->identifier, 'id' => $page->title]) : null,
            ])),
            'attachments' => $attachments,
            'versions_count' => $this->wikis->versionsCount($page->content),
            'ancestors' => $this->wikis->ancestors($page),
            'body_css' => 'controller-wiki action-show',
            'page_title' => $page->prettyTitle(),
        ]));
    }

    // ------------------------------------------------------------------ edit / update

    #[Route('/projects/{project_id}/wiki/{id}/edit', name: 'wiki_edit', methods: ['GET'])]
    public function edit(Request $request, string $project_id, string $id): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        $this->authorize('edit_wiki_pages', $project);
        $page = $this->wikis->findOrNewPage($wiki, $id);
        if ($this->wikis->pageFoundWithRedirect()) {
            return $this->redirectToPage($page, 'wiki_edit');
        }
        if (!$this->wikis->isEditable($page)) {
            $this->deny();
        }
        if (null === $page->id && '' !== (string) $request->query->get('parent', '')) {
            $page->parent = $this->wikis->findPage($wiki, (string) $request->query->get('parent'));
        }
        $text = null;
        if (null !== $page->content) {
            $version = $request->query->get('version');
            if (null !== $version && '' !== $version) {
                $v = $this->wikis->version($page->content, (int) $version);
                $text = $v ? $this->wikis->versionText($v) : null;
            } else {
                $text = $page->content->text;
            }
        }
        if (null === $text || '' === trim($text)) {
            $text = $this->wikis->initialPageContent($page);
        }
        $section = null;
        $sectionHash = null;
        if ('' !== (string) $request->query->get('section', '') && $this->wikis->supportsSectionEdit()) {
            $section = (int) $request->query->get('section');
            [$text, $sectionHash] = $this->wikis->getSection($text, $section);
            if ('' === trim($text)) {
                throw $this->createNotFoundException();
            }
        }

        return $this->renderEdit($project, $wiki, $page, $text, null, $page->content?->version, $section, $sectionHash);
    }

    #[Route('/projects/{project_id}/wiki/{id}.{_format}', name: 'wiki_update_api', requirements: ['_format' => self::FMT], methods: ['PUT', 'PATCH'])]
    #[Route('/projects/{project_id}/wiki/{id}', name: 'wiki_update', methods: ['PUT', 'PATCH', 'POST'])]
    public function update(Request $request, string $project_id, string $id): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        $this->authorize('edit_wiki_pages', $project);
        $isApi = $this->isApi($request);
        $page = $this->wikis->findOrNewPage($wiki, $id);
        if (!$this->wikis->isEditable($page)) {
            $this->deny();
        }
        $wasNew = null === $page->id;
        $payload = $isApi ? ApiRenderer::payload($request) : $request->request->all();
        $pageParams = (array) ($payload['wiki_page'] ?? []);
        $this->wikis->track($page);
        $this->applyPageAttributes($page, $wiki, $pageParams);
        $contentParams = $payload['content'] ?? null;
        if (null === $contentParams && [] !== $pageParams) {
            $contentParams = array_intersect_key($pageParams, ['text' => 1, 'comments' => 1, 'version' => 1]);
        }
        $contentParams = (array) ($contentParams ?? []);
        $comments = isset($contentParams['comments']) ? (string) $contentParams['comments'] : null;
        $text = isset($contentParams['text']) ? str_replace("\r\n", "\n", (string) $contentParams['text']) : null;
        $section = null;
        $sectionHash = null;
        $lockVersion = null;
        try {
            if ('' !== (string) ($payload['section'] ?? '') && $this->wikis->supportsSectionEdit() && null !== $page->content) {
                $section = (int) $payload['section'];
                $sectionHash = (string) ($payload['section_hash'] ?? '');
                $newText = $this->wikis->updateSection((string) $page->content->text, $section, (string) $text, $sectionHash);
                $lockVersion = $page->content->version;
            } else {
                $newText = $text ?? (string) $page->content?->text;
                if (isset($contentParams['version']) && '' !== (string) $contentParams['version']) {
                    $lockVersion = (int) $contentParams['version'];
                }
            }
            $errors = $this->wikis->saveWithContent($page, $newText, $comments, $this->user(), $lockVersion);
        } catch (StaleWikiContentException) {
            if ($isApi) {
                return new Response('', 409);
            }
            $this->em->clear();
            [$project, $wiki] = $this->findWiki($project_id);
            $page = $this->wikis->findOrNewPage($wiki, $id);
            $this->addFlash('error', $this->l('notice_locking_conflict'));

            return $this->renderEdit($project, $wiki, $page, (string) $text, $comments, (int) ($contentParams['version'] ?? $page->content?->version), $section, $sectionHash, [], true);
        }
        if ([] !== $errors) {
            if ($isApi) {
                return $this->api->errors($request, $errors);
            }

            return $this->renderEdit($project, $wiki, $page, (string) $text, $comments, $lockVersion, $section, $sectionHash, $errors);
        }
        // attachments
        $uploads = $isApi ? ($pageParams['uploads'] ?? null) : null;
        [$files, $attErrors] = $this->attachments->fromRequest($request, $this->user(), \is_array($uploads) ? $uploads : null);
        $this->attachments->attachTo($files, 'WikiPage', (int) $page->id);
        if ($this->auth->allowedTo('delete_wiki_pages_attachments', $project) && $this->wikis->isEditable($page)) {
            foreach ((array) ($pageParams['deleted_attachment_ids'] ?? []) as $aid) {
                $a = $this->em->find(Attachment::class, (int) $aid);
                if ($a && 'WikiPage' === $a->containerType && $a->containerId === $page->id) {
                    $this->attachments->delete($a);
                }
            }
        }
        $this->em->flush();
        if ([] !== $attErrors && !$isApi) {
            $this->addFlash('warning', implode(', ', $attErrors));
        }
        $this->events->dispatch(new ContentEvent('wiki_page', $wasNew ? 'created' : 'updated', $page, $this->user(), ['content' => $page->content, 'project' => $project, 'attachments' => $files]));
        $this->callHook('controller_wiki_edit_after_save', ['params' => $request->request->all(), 'page' => $page, 'project' => $project]);

        if ($isApi) {
            if ($wasNew) {
                $response = $this->show($request, $project_id, $page->title, null, ApiRenderer::format($request));
                $response->setStatusCode(201);
                $response->headers->set('Location', $this->generateUrl('wiki_show', ['project_id' => $project->identifier, 'id' => $page->title]));

                return $response;
            }

            return $this->api->noContent();
        }
        $url = $this->generateUrl('wiki_show', ['project_id' => $project->identifier, 'id' => $page->title]);

        return $this->redirect($url.(null !== $section ? '#section-'.$section : ''));
    }

    #[Route('/projects/{project_id}/wiki/{id}/preview', name: 'wiki_preview', methods: ['POST', 'PUT', 'PATCH'])]
    public function preview(Request $request, string $project_id, string $id): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        $this->authorize('edit_wiki_pages', $project);
        $page = $this->wikis->findPage($wiki, $id);
        if (null !== $page && !$this->wikis->isEditable($page)) {
            $this->deny();
        }
        $content = $request->request->all('content');
        $text = (string) ($content['text'] ?? $request->request->get('text', ''));
        if ('' === trim($text)) {
            return new Response('<p class="empty-preview">'.htmlspecialchars($this->l('label_nothing_to_preview')).'</p>');
        }
        $attachments = $page ? $this->wikis->attachmentsOf($page) : [];

        return new Response($this->formatter->format($text, $project, array_filter(['headings' => true, 'object' => $page, 'attachments' => $attachments])));
    }

    // ------------------------------------------------------------------ rename / protect

    #[Route('/projects/{project_id}/wiki/{id}/rename', name: 'wiki_rename', methods: ['GET', 'POST'])]
    public function rename(Request $request, string $project_id, string $id): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        if (!$this->auth->allowedTo('rename_wiki_pages', $project) && !$this->auth->allowedTo('manage_wiki', $project)) {
            $this->deny();
        }
        $page = $this->findExistingPage($wiki, $id);
        if ($this->wikis->pageFoundWithRedirect()) {
            return $this->redirectToPage($page, 'wiki_rename');
        }
        if (!$this->wikis->isEditable($page)) {
            $this->deny();
        }
        $originalTitle = $page->prettyTitle();
        $errors = [];
        $redirectExisting = true;
        if ($request->isMethod('POST')) {
            $params = $request->request->all('wiki_page');
            $redirectExisting = '0' !== (string) ($params['redirect_existing_links'] ?? '1');
            $this->wikis->track($page, $redirectExisting);
            $this->applyPageAttributes($page, $wiki, $params);
            $errors = $this->wikis->savePage($page);
            if ([] === $errors) {
                $this->flashNotice();

                return $this->redirectToRoute('wiki_show', ['project_id' => $page->wiki->project->identifier, 'id' => $page->title]);
            }
        }
        if ($request->isXmlHttpRequest() && $request->query->has('wiki_page')) {
            // parent page options when the target wiki changes
            $target = $this->em->find(Wiki::class, (int) ($request->query->all('wiki_page')['wiki_id'] ?? 0)) ?? $wiki;
            $options = '<option value=""></option>'.$this->pageOptions($this->wikis->pages($target), null, array_merge([$page], $this->wikis->descendants($page)));

            return new Response("$('#wiki_page_parent_id').html(".json_encode($options).');', 200, ['Content-Type' => 'text/javascript']);
        }
        $excluded = array_merge([$page], $this->wikis->descendants($page));

        return $this->render('wiki/rename.html.twig', $this->vars($project, $wiki, [
            'page' => $page,
            'original_title' => $originalTitle,
            'errors' => $errors,
            'redirect_existing_links' => $redirectExisting,
            'is_start_page' => $this->wikis->isStartPage($page),
            'can_manage_wiki' => $this->auth->allowedTo('manage_wiki', $project),
            'can_move' => $this->auth->allowedTo('rename_wiki_pages', $project),
            'wiki_options' => $this->wikiOptions($page),
            'parent_options' => $this->pageOptions($this->wikis->pages($page->wiki), $page->parent, $excluded),
            'ancestors' => $this->wikis->ancestors($page),
            'body_css' => 'controller-wiki action-rename',
            'page_title' => $originalTitle,
        ]));
    }

    #[Route('/projects/{project_id}/wiki/{id}/protect', name: 'wiki_protect', methods: ['POST'])]
    public function protect(Request $request, string $project_id, string $id): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        $this->authorize('protect_wiki_pages', $project);
        $page = $this->findExistingPage($wiki, $id);
        $page->protected = \in_array((string) ($request->request->get('protected') ?? $request->query->get('protected')), ['1', 'true'], true);
        $this->em->flush();

        return $this->redirectToRoute('wiki_show', ['project_id' => $project->identifier, 'id' => $page->title]);
    }

    // ------------------------------------------------------------------ history / diff / annotate

    #[Route('/projects/{project_id}/wiki/{id}/history', name: 'wiki_history', methods: ['GET'])]
    public function history(Request $request, string $project_id, string $id): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        $this->authorize('view_wiki_edits', $project);
        $page = $this->findExistingPage($wiki, $id);
        if ($this->wikis->pageFoundWithRedirect()) {
            return $this->redirectToPage($page, 'wiki_history');
        }
        if (null === $page->content) {
            throw $this->createNotFoundException();
        }
        $count = $this->wikis->versionsCount($page->content);
        $pager = $this->paginate($request, $count);
        $versions = $this->wikis->versions($page->content, $pager['offset'], $pager['limit'] + 1);

        return $this->render('wiki/history.html.twig', $this->vars($project, $wiki, [
            'page' => $page,
            'versions' => \array_slice($versions, 0, $pager['limit'] + 1),
            'version_count' => $count,
            'pager' => $pager,
            'ancestors' => $this->wikis->ancestors($page),
            'body_css' => 'controller-wiki action-history',
            'page_title' => $this->l('label_history').' - '.$page->prettyTitle(),
        ]));
    }

    #[Route('/projects/{project_id}/wiki/{id}/{version}/diff', name: 'wiki_diff_version', requirements: ['version' => '\d+'], methods: ['GET'])]
    #[Route('/projects/{project_id}/wiki/{id}/diff', name: 'wiki_diff', methods: ['GET'])]
    public function diff(Request $request, string $project_id, string $id, ?string $version = null): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        $this->authorize('view_wiki_edits', $project);
        $page = $this->findExistingPage($wiki, $id);
        if ($this->wikis->pageFoundWithRedirect()) {
            return $this->redirectToPage($page, 'wiki_diff');
        }
        $version ??= $request->query->get('version');
        $from = $request->query->get('version_from');
        $diff = $this->wikis->diff($page, null !== $version && '' !== $version ? (int) $version : null, null !== $from && '' !== $from ? (int) $from : null);
        if (null === $diff) {
            throw $this->createNotFoundException();
        }

        return $this->render('wiki/diff.html.twig', $this->vars($project, $wiki, [
            'page' => $page,
            'diff' => $diff,
            'ancestors' => $this->wikis->ancestors($page),
            'body_css' => 'controller-wiki action-diff',
            'page_title' => $page->prettyTitle(),
        ]));
    }

    #[Route('/projects/{project_id}/wiki/{id}/{version}/annotate', name: 'wiki_annotate', requirements: ['version' => '\d+'], methods: ['GET'])]
    public function annotate(string $project_id, string $id, string $version): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        $this->authorize('view_wiki_edits', $project);
        $page = $this->findExistingPage($wiki, $id);
        if ($this->wikis->pageFoundWithRedirect()) {
            return $this->redirectToPage($page, 'wiki_annotate', ['version' => $version]);
        }
        $annotate = $this->wikis->annotate($page, (int) $version);
        if (null === $annotate) {
            throw $this->createNotFoundException();
        }
        $colors = [];
        foreach ($annotate['lines'] as $line) {
            $colors[$line[0]] ??= \count($colors) % 12;
        }

        return $this->render('wiki/annotate.html.twig', $this->vars($project, $wiki, [
            'page' => $page,
            'annotate' => $annotate,
            'colors' => $colors,
            'ancestors' => $this->wikis->ancestors($page),
            'body_css' => 'controller-wiki action-annotate',
            'page_title' => $page->prettyTitle(),
        ]));
    }

    // ------------------------------------------------------------------ destroy

    #[Route('/projects/{project_id}/wiki/{id}.{_format}', name: 'wiki_destroy_api', requirements: ['_format' => self::FMT], methods: ['DELETE'])]
    #[Route('/projects/{project_id}/wiki/{id}', name: 'wiki_destroy', methods: ['DELETE'])]
    public function destroy(Request $request, string $project_id, string $id): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        $this->authorize('delete_wiki_pages', $project);
        $page = $this->findExistingPage($wiki, $id);
        if (!$this->wikis->isEditable($page)) {
            $this->deny();
        }
        $isApi = $this->isApi($request);
        $descendants = $this->wikis->descendants($page);
        if ([] !== $descendants) {
            $todo = (string) $request->request->get('todo', $request->query->get('todo', ''));
            switch ($todo) {
                case 'nullify':
                    break;
                case 'destroy':
                    foreach (array_reverse($descendants) as $d) {
                        $this->wikis->destroyPage($d);
                    }
                    break;
                case 'reassign':
                    $target = $this->em->find(WikiPage::class, (int) $request->request->get('reassign_to_id'));
                    if (null === $target || $target->wiki->id !== $wiki->id || \in_array($target, $descendants, true) || $target === $page) {
                        return $this->renderDestroy($project, $wiki, $page, $descendants);
                    }
                    foreach ($this->wikis->children($page) as $child) {
                        $child->parent = $target;
                    }
                    $this->em->flush();
                    break;
                default:
                    if (!$isApi) {
                        return $this->renderDestroy($project, $wiki, $page, $descendants);
                    }
            }
        }
        $this->events->dispatch(new ContentEvent('wiki_page', 'deleted', $page, $this->user(), ['project' => $project, 'title' => $page->title]));
        $this->wikis->destroyPage($page);
        if ($isApi) {
            return $this->api->noContent();
        }
        $this->flashNotice('notice_successful_delete');

        return $this->redirectToRoute('wiki_index_by_title', ['project_id' => $project->identifier]);
    }

    #[Route('/projects/{project_id}/wiki/{id}/{version}', name: 'wiki_destroy_version', requirements: ['version' => '\d+'], methods: ['DELETE'])]
    public function destroyVersion(Request $request, string $project_id, string $id, string $version): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        $this->authorize('delete_wiki_pages', $project);
        $page = $this->findExistingPage($wiki, $id);
        if (!$this->wikis->isEditable($page)) {
            $this->deny();
        }
        $v = $page->content ? $this->wikis->version($page->content, (int) $version) : null;
        if (null === $v) {
            throw $this->createNotFoundException();
        }
        $this->wikis->destroyVersion($page, $v);
        $referer = (string) $request->headers->get('referer');
        if ('' !== $referer && parse_url($referer, \PHP_URL_HOST) === $request->getHost()) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('wiki_history', ['project_id' => $project->identifier, 'id' => $page->title]);
    }

    #[Route('/projects/{project_id}/wiki/{id}/add_attachment', name: 'wiki_add_attachment', methods: ['POST'])]
    public function addAttachment(Request $request, string $project_id, string $id): Response
    {
        [$project, $wiki] = $this->findWiki($project_id);
        $this->authorize('edit_wiki_pages', $project);
        $page = $this->findExistingPage($wiki, $id);
        if (!$this->wikis->isEditable($page)) {
            $this->deny();
        }
        [$files, $errors] = $this->attachments->fromRequest($request, $this->user());
        $this->attachments->attachTo($files, 'WikiPage', (int) $page->id);
        $this->em->flush();
        if ([] !== $errors) {
            $this->addFlash('warning', implode(', ', $errors));
        }
        if ([] !== $files) {
            $this->events->dispatch(new ContentEvent('wiki_page', 'updated', $page, $this->user(), ['project' => $project, 'attachments' => $files]));
        }

        return $this->redirectToRoute('wiki_show', ['project_id' => $project->identifier, 'id' => $page->title]);
    }

    /** WikisController#destroy: deletes the project's wiki (a new empty wiki is created) */
    #[Route('/projects/{id}/wiki/destroy', name: 'wikis_destroy', methods: ['GET', 'POST'], priority: 20)]
    public function destroyWiki(Request $request, string $id): Response
    {
        $project = $this->findProject($id);
        $this->authorize('manage_wiki', $project);
        $wiki = $this->wikis->wikiFor($project, false);
        if ($request->isMethod('POST') && $request->request->get('confirm') && null !== $wiki) {
            $this->wikis->destroyWiki($wiki);
            $this->wikis->createDefault($project);
            $this->em->flush();

            return $this->redirectToRoute('project_show', ['id' => $project->identifier]);
        }

        return $this->render('wikis/destroy.html.twig', ['project' => $project, 'main_menu_selected' => 'wiki', 'body_css' => 'controller-wikis action-destroy', 'page_title' => $this->l('label_confirmation')]);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: Project, 1: Wiki} */
    private function findWiki(string $projectId): array
    {
        $project = $this->findProject($projectId);
        $wiki = $this->wikis->wikiFor($project);
        if (null === $wiki) {
            throw $this->createNotFoundException();
        }

        return [$project, $wiki];
    }

    private function findExistingPage(Wiki $wiki, string $title): WikiPage
    {
        $page = $this->wikis->findPage($wiki, $title);
        if (null === $page) {
            throw $this->createNotFoundException();
        }

        return $page;
    }

    private function redirectToPage(WikiPage $page, string $route, array $extra = []): Response
    {
        $project = $page->wiki->project;
        if (!$this->auth->isProjectVisible($project)) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute($route, ['project_id' => $project->identifier, 'id' => $page->title] + $extra);
    }

    /** safe_attributes of WikiPage: parent_id/parent_title/title/wiki_id (new page or rename permission), is_start_page (manage_wiki) */
    private function applyPageAttributes(WikiPage $page, Wiki $wiki, array $params): void
    {
        $project = $wiki->project;
        $canRename = null === $page->id || $this->auth->allowedTo('rename_wiki_pages', $project);
        if ($canRename) {
            if (isset($params['wiki_id']) && (int) $params['wiki_id'] !== $page->wiki->id) {
                $target = $this->em->find(Wiki::class, (int) $params['wiki_id']);
                if ($target && $this->auth->allowedTo('rename_wiki_pages', $target->project)) {
                    $page->wiki = $target;
                }
            }
            if (isset($params['title']) && '' !== trim((string) $params['title'])) {
                $page->title = Wiki::titleize((string) $params['title']);
            } elseif (isset($params['title'])) {
                $page->title = '';
            }
            if (\array_key_exists('parent_id', $params)) {
                $parent = '' === (string) $params['parent_id'] ? null : $this->em->find(WikiPage::class, (int) $params['parent_id']);
                $page->parent = $parent;
            }
            if (\array_key_exists('parent_title', $params)) {
                $t = trim((string) $params['parent_title']);
                $page->parent = '' === $t ? null : $this->wikis->findPage($page->wiki, $t);
            }
        }
        if (isset($params['is_start_page']) && \in_array((string) $params['is_start_page'], ['1', 'true'], true) && $this->auth->allowedTo('manage_wiki', $project)) {
            $this->wikis->requestStartPage($page);
        }
    }

    private function renderEdit(Project $project, Wiki $wiki, WikiPage $page, string $text, ?string $comments, ?int $version, ?int $section, ?string $sectionHash, array $errors = [], bool $conflict = false): Response
    {
        $excluded = array_merge([$page], $this->wikis->descendants($page));
        $allPages = $this->wikis->pages($wiki);
        $canSetParent = null === $page->id || $this->auth->allowedTo('rename_wiki_pages', $project);

        $response = $this->render('wiki/edit.html.twig', $this->vars($project, $wiki, [
            'page' => $page,
            'text' => $text,
            'comments' => $comments,
            'version' => $version,
            'section' => $section,
            'section_hash' => $sectionHash,
            'errors' => $errors,
            'show_parent' => $canSetParent && [] !== $allPages,
            'parent_options' => $this->pageOptions($allPages, $page->parent, $excluded),
            'attachments' => $this->wikis->attachmentsOf($page),
            'attachments_deletable' => $this->auth->allowedTo('delete_wiki_pages_attachments', $project) && $this->wikis->isEditable($page),
            'cancel_url' => null !== $page->id
                ? $this->generateUrl('wiki_show', ['project_id' => $project->identifier, 'id' => $page->title])
                : ($page->parent ? $this->generateUrl('wiki_show', ['project_id' => $project->identifier, 'id' => $page->parent->title]) : $this->generateUrl('wiki_index_by_title', ['project_id' => $project->identifier])),
            'ancestors' => $this->wikis->ancestors($page),
            'body_css' => 'controller-wiki action-edit',
            'page_title' => $page->prettyTitle(),
        ]));
        if ([] !== $errors) {
            $response->setStatusCode(422);
        }

        return $response;
    }

    private function renderDestroy(Project $project, Wiki $wiki, WikiPage $page, array $descendants): Response
    {
        $excluded = array_merge([$page], $descendants);
        $reassignable = array_values(array_filter($this->wikis->pages($wiki), static fn (WikiPage $p) => !\in_array($p, $excluded, true)));

        return $this->render('wiki/destroy.html.twig', $this->vars($project, $wiki, [
            'page' => $page,
            'descendants_count' => \count($descendants),
            'reassignable_options' => [] === $reassignable ? '' : $this->pageOptions($reassignable, null, [], true),
            'ancestors' => $this->wikis->ancestors($page),
            'body_css' => 'controller-wiki action-destroy',
            'page_title' => $page->prettyTitle(),
        ]));
    }

    private function vars(Project $project, Wiki $wiki, array $vars): array
    {
        return $vars + [
            'project' => $project,
            'wiki' => $wiki,
            'main_menu_selected' => 'wiki',
            'sidebar_page' => $this->wikis->sidebar($wiki),
            'sidebar_editable' => $this->auth->allowedTo('edit_wiki_pages', $project) && $this->wikis->isEditable($this->wikis->findOrNewPage($wiki, 'Sidebar')),
        ];
    }

    /**
     * Pages grouped by parent id rendered as nested lists (render_page_hierarchy).
     *
     * @param list<WikiPage> $pages
     */
    private function hierarchy(array $pages, bool $timestamp, ?callable $href = null): string
    {
        $byParent = [];
        $ids = [];
        foreach ($pages as $p) {
            $ids[$p->id] = true;
        }
        foreach ($pages as $p) {
            $parentId = $p->parent && isset($ids[$p->parent->id]) ? $p->parent->id : 0;
            $byParent[$parentId][] = $p;
        }
        $title = $timestamp ? fn (WikiPage $p) => $p->content?->updatedOn ? $this->l('label_updated_time', ['value' => $this->i18n->distanceOfTimeInWords($p->content->updatedOn)]) : null : null;

        return $this->formatter->pageHierarchy($byParent, 0, $title, $href);
    }

    /**
     * wiki_page_options_for_select
     *
     * @param list<WikiPage> $pages
     * @param list<WikiPage> $excluded
     */
    private function pageOptions(array $pages, ?WikiPage $selected, array $excluded = [], bool $flat = false): string
    {
        $pages = array_values(array_filter($pages, static fn (WikiPage $p) => !\in_array($p, $excluded, true)));
        $ids = [];
        foreach ($pages as $p) {
            $ids[$p->id] = true;
        }
        $byParent = [];
        foreach ($pages as $p) {
            $byParent[$p->parent && isset($ids[$p->parent->id]) ? $p->parent->id : 0][] = $p;
        }
        $out = '';
        $walk = function (int $parent, int $level) use (&$walk, &$out, $byParent, $selected): void {
            foreach ($byParent[$parent] ?? [] as $page) {
                $indent = $level > 0 ? str_repeat('&nbsp;', $level * 2).'&#187; ' : '';
                $out .= '<option value="'.$page->id.'"'.($selected && $selected->id === $page->id ? ' selected="selected"' : '').'>'.$indent.htmlspecialchars($page->prettyTitle()).'</option>';
                $walk((int) $page->id, $level + 1);
            }
        };
        $walk(0, 0);
        unset($flat);

        return $out;
    }

    /** wiki_page_wiki_options_for_select: wikis of projects where the user can rename pages */
    private function wikiOptions(WikiPage $page): string
    {
        $wikis = $this->em->createQuery('SELECT w, p FROM App\Entity\Wiki w JOIN w.project p ORDER BY p.lft ASC, p.name ASC')->getResult();
        $out = '';
        foreach ($wikis as $w) {
            if ($w->id !== $page->wiki->id && (!$this->auth->allowedTo('rename_wiki_pages', $w->project) || $w->project->isArchived())) {
                continue;
            }
            $level = \count($w->project->getAncestors());
            $indent = $level > 0 ? str_repeat('&nbsp;', $level * 2).'&#187; ' : '';
            $out .= '<option value="'.$w->id.'"'.($w->id === $page->wiki->id ? ' selected="selected"' : '').'>'.$indent.htmlspecialchars($w->project->name).'</option>';
        }

        return $out;
    }

    private function apiAttachment(Request $request, Attachment $a): array
    {
        $base = $request->getSchemeAndHttpHost();

        return [
            'id' => $a->id,
            'filename' => $a->filename,
            'filesize' => $a->getFilesize(),
            'content_type' => $a->contentType,
            'description' => $a->description,
            'content_url' => $base.'/attachments/download/'.$a->id.'/'.rawurlencode($a->filename),
            'author' => ['id' => $a->author->id, 'name' => $a->author->getName()],
            'created_on' => ApiRenderer::time($a->createdOn),
        ];
    }
}

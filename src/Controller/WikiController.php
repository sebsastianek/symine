<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\WikiApiPresenter;
use App\Service\Wiki\WikiExport;
use App\Service\Wiki\WikiFinder;
use App\Service\Wiki\WikiNewPageForm;
use App\Service\Wiki\WikiPageAttributes;
use App\Service\Wiki\WikiPageForm;
use App\Service\Wiki\WikiPageRemover;
use App\Service\Wiki\WikiPages;
use App\Service\Wiki\WikiPageView;
use App\Service\Wiki\WikiRenameForm;
use App\Service\WikiService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's WikiController and WikisController. */
final class WikiController extends AbstractController
{
    private const string FMT = 'json|xml';

    public function __construct(
        private readonly WikiService $wikis,
        private readonly ApiRenderer $api,
        private readonly WikiApiPresenter $presenter,
        private readonly WikiFinder $finder,
        private readonly WikiPages $pages,
        private readonly WikiPageView $views,
        private readonly WikiPageForm $form,
        private readonly WikiNewPageForm $newPageForm,
        private readonly WikiRenameForm $renameForm,
        private readonly WikiPageAttributes $attributes,
        private readonly WikiPageRemover $remover,
        private readonly WikiExport $exports,
    ) {
    }

    // ------------------------------------------------------------------ index

    #[Route('/projects/{project_id}/wiki/index.{_format}', name: 'wiki_index_api', requirements: ['_format' => self::FMT], methods: ['GET'], priority: 10)]
    #[Route('/projects/{project_id}/wiki/index', name: 'wiki_index_by_title', methods: ['GET'], priority: 10)]
    public function index(Request $request, string $project_id): Response
    {
        $wiki = $this->finder->wiki($project_id);
        $this->authorize('view_wiki_pages', $wiki->project);
        if ($this->isApi($request)) {
            return $this->api->render($request, $this->presenter->index($wiki), 200, WikiApiPresenter::XML_ATTRIBUTES);
        }

        return $this->render('wiki/index.html.twig', $this->pages->index($wiki) + [
            'main_menu_selected' => 'wiki',
            'body_css' => 'controller-wiki action-index',
            'page_title' => $this->l('label_index_by_title'),
        ]);
    }

    #[Route('/projects/{project_id}/wiki/date_index', name: 'wiki_date_index', methods: ['GET'], priority: 10)]
    public function dateIndex(string $project_id): Response
    {
        $wiki = $this->finder->wiki($project_id);
        $this->authorize('view_wiki_pages', $wiki->project);

        return $this->render('wiki/date_index.html.twig', $this->pages->dateIndex($wiki) + [
            'main_menu_selected' => 'wiki',
            'body_css' => 'controller-wiki action-date_index',
            'page_title' => $this->l('label_index_by_date'),
        ]);
    }

    #[Route('/projects/{project_id}/wiki/export.{_format}', name: 'wiki_export_format', requirements: ['_format' => 'html|zip|pdf'], methods: ['GET'], priority: 10)]
    #[Route('/projects/{project_id}/wiki/export', name: 'wiki_export', methods: ['GET'], priority: 10)]
    public function export(Request $request, string $project_id, string $_format = 'html', ?\App\Pdf\PdfExport $pdfExport = null): Response
    {
        $wiki = $this->finder->wiki($project_id);
        $this->authorize('export_wiki_pages', $wiki->project);
        if ('pdf' === $_format && null !== $pdfExport) {
            return $pdfExport->wikiPages($wiki, $wiki->project);
        }
        if ('zip' === $_format) {
            return $this->exports->zip($wiki);
        }

        return new Response($this->renderView('wiki/export_multiple.html.twig', $this->pages->export($wiki)), 200, ['Content-Type' => 'text/html', 'Content-Disposition' => 'attachment; filename="wiki.html"']);
    }

    // ------------------------------------------------------------------ new

    #[Route('/projects/{project_id}/wiki/new', name: 'wiki_new', methods: ['GET', 'POST'], priority: 10)]
    public function new(Request $request, string $project_id): Response
    {
        $wiki = $this->finder->wiki($project_id);
        $this->authorize('edit_wiki_pages', $wiki->project);
        $form = $this->newPageForm->handle($wiki, $request);
        if ($request->isMethod('POST') && [] === $form['errors']) {
            $path = $this->generateUrl('wiki_show', $this->newPageForm->target($form));
            if ($request->isXmlHttpRequest()) {
                return new Response('window.location = '.json_encode($path).';', 200, ['Content-Type' => 'text/javascript']);
            }

            return $this->redirect($path);
        }
        $vars = $form + ['main_menu_selected' => 'wiki', 'body_css' => 'controller-wiki action-new', 'page_title' => $this->l('label_wiki_page_new')];
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
        $wiki = $this->finder->wiki($project_id);
        $project = $wiki->project;
        $this->authorize('view_wiki_pages', $project);
        $page = $this->wikis->findOrNewPage($wiki, $id);
        if ($this->wikis->pageFoundWithRedirect()) {
            return $this->redirectToRoute('wiki_show', $this->finder->routeOf($page));
        }
        $version ??= $request->query->has('version') && '' !== $request->query->get('version') ? (string) $request->query->get('version') : null;
        if (null !== $version && !$this->auth->allowedTo('view_wiki_edits', $project)) {
            $this->deny();
        }
        $isApi = $this->isApi($request);
        $revision = $this->views->revision($page, $version);
        if (null === $revision) {
            if (null === $version && !$isApi && null === $_format && $this->views->isWritable($page)) {
                return $this->edit($request, $project_id, $page->title);
            }
            throw $this->createNotFoundException();
        }
        $this->callHook('controller_wiki_show_before_render', ['content' => $revision->content, 'format' => $_format, 'project' => $project]);

        if ('pdf' === $_format && null !== $pdfExport) {
            // respond_to html/api only: unknown format
            return $this->auth->allowedTo('export_wiki_pages', $project) ? $pdfExport->wikiPage($page, $project) : new Response('', 406);
        }
        if ('txt' === $_format) {
            $this->authorize('export_wiki_pages', $project);

            return new Response($this->views->text($revision), 200, ['Content-Type' => 'text/plain', 'Content-Disposition' => 'attachment; filename="'.$page->title.'.txt"']);
        }
        if ('html' === $_format) {
            $this->authorize('export_wiki_pages', $project);

            return new Response($this->renderView('wiki/export.html.twig', $this->views->export($wiki, $revision)), 200, ['Content-Type' => 'text/html', 'Content-Disposition' => 'attachment; filename="'.$page->title.'.html"']);
        }
        if ($isApi) {
            return $this->api->render($request, ['wiki_page' => $this->presenter->page($request, $wiki, $revision)], 200, WikiApiPresenter::XML_ATTRIBUTES);
        }

        return $this->render('wiki/show.html.twig', $this->views->show($wiki, $revision) + [
            'main_menu_selected' => 'wiki',
            'body_css' => 'controller-wiki action-show',
            'page_title' => $page->prettyTitle(),
        ]);
    }

    // ------------------------------------------------------------------ edit / update

    #[Route('/projects/{project_id}/wiki/{id}/edit', name: 'wiki_edit', methods: ['GET'])]
    public function edit(Request $request, string $project_id, string $id): Response
    {
        $wiki = $this->finder->wiki($project_id);
        $this->authorize('edit_wiki_pages', $wiki->project);
        $page = $this->wikis->findOrNewPage($wiki, $id);
        if ($this->wikis->pageFoundWithRedirect()) {
            return $this->redirectToRoute('wiki_edit', $this->finder->routeOf($page));
        }
        if (!$this->wikis->isEditable($page)) {
            $this->deny();
        }

        return $this->render('wiki/edit.html.twig', $this->form->edit($wiki, $page, $request) + [
            'main_menu_selected' => 'wiki',
            'body_css' => 'controller-wiki action-edit',
            'page_title' => $page->prettyTitle(),
        ]);
    }

    #[Route('/projects/{project_id}/wiki/{id}.{_format}', name: 'wiki_update_api', requirements: ['_format' => self::FMT], methods: ['PUT', 'PATCH'])]
    #[Route('/projects/{project_id}/wiki/{id}', name: 'wiki_update', methods: ['PUT', 'PATCH', 'POST'])]
    public function update(Request $request, string $project_id, string $id): Response
    {
        $wiki = $this->finder->wiki($project_id);
        $project = $wiki->project;
        $this->authorize('edit_wiki_pages', $project);
        $isApi = $this->isApi($request);
        $page = $this->wikis->findOrNewPage($wiki, $id);
        if (!$this->wikis->isEditable($page)) {
            $this->deny();
        }
        $result = $this->form->submit($wiki, $page, $request);
        if ($result->conflict) {
            if ($isApi) {
                return new Response('', 409);
            }
            $vars = $this->form->conflicting($project_id, $id, $result);
            $this->addFlash('error', $this->l('notice_locking_conflict'));

            return $this->render('wiki/edit.html.twig', $vars + [
                'main_menu_selected' => 'wiki',
                'body_css' => 'controller-wiki action-edit',
                'page_title' => $vars['page']->prettyTitle(),
            ]);
        }
        if (!$result->isSaved()) {
            if ($isApi) {
                return $this->api->errors($request, $result->errors);
            }

            return $this->render('wiki/edit.html.twig', $this->form->rejected($wiki, $page, $result) + [
                'main_menu_selected' => 'wiki',
                'body_css' => 'controller-wiki action-edit',
                'page_title' => $page->prettyTitle(),
            ], new Response(null, 422));
        }
        if ([] !== $result->attachmentErrors && !$isApi) {
            $this->addFlash('warning', implode(', ', $result->attachmentErrors));
        }
        $this->callHook('controller_wiki_edit_after_save', ['params' => $request->request->all(), 'page' => $page, 'project' => $project]);

        if ($isApi) {
            if ($result->created) {
                $response = $this->show($request, $project_id, $page->title, null, ApiRenderer::format($request));
                $response->setStatusCode(201);
                $response->headers->set('Location', $this->generateUrl('wiki_show', ['project_id' => $project->identifier, 'id' => $page->title]));

                return $response;
            }

            return $this->api->noContent();
        }
        $url = $this->generateUrl('wiki_show', ['project_id' => $project->identifier, 'id' => $page->title]);

        return $this->redirect($url.(null !== $result->section ? '#section-'.$result->section : ''));
    }

    #[Route('/projects/{project_id}/wiki/{id}/preview', name: 'wiki_preview', methods: ['POST', 'PUT', 'PATCH'])]
    public function preview(Request $request, string $project_id, string $id): Response
    {
        $wiki = $this->finder->wiki($project_id);
        $this->authorize('edit_wiki_pages', $wiki->project);
        $page = $this->wikis->findPage($wiki, $id);
        if (null !== $page && !$this->wikis->isEditable($page)) {
            $this->deny();
        }

        return new Response($this->views->preview($wiki, $page, $request));
    }

    // ------------------------------------------------------------------ rename / protect

    #[Route('/projects/{project_id}/wiki/{id}/rename', name: 'wiki_rename', methods: ['GET', 'POST'])]
    public function rename(Request $request, string $project_id, string $id): Response
    {
        $wiki = $this->finder->wiki($project_id);
        if (!$this->auth->allowedTo('rename_wiki_pages', $wiki->project) && !$this->auth->allowedTo('manage_wiki', $wiki->project)) {
            $this->deny();
        }
        $page = $this->finder->page($wiki, $id);
        if ($this->wikis->pageFoundWithRedirect()) {
            return $this->redirectToRoute('wiki_rename', $this->finder->routeOf($page));
        }
        if (!$this->wikis->isEditable($page)) {
            $this->deny();
        }
        $originalTitle = $page->prettyTitle();
        $errors = [];
        if ($request->isMethod('POST')) {
            $errors = $this->renameForm->submit($wiki, $page, $request);
            if ([] === $errors) {
                $this->flashNotice();

                return $this->redirectToRoute('wiki_show', ['project_id' => $page->wiki->project->identifier, 'id' => $page->title]);
            }
        }
        if ($request->isXmlHttpRequest() && $request->query->has('wiki_page')) {
            // parent page options when the target wiki changes
            return new Response("$('#wiki_page_parent_id').html(".json_encode($this->renameForm->parentOptions($wiki, $page, $request)).');', 200, ['Content-Type' => 'text/javascript']);
        }

        return $this->render('wiki/rename.html.twig', $this->renameForm->view($wiki, $page, $originalTitle, $request, $errors) + [
            'main_menu_selected' => 'wiki',
            'body_css' => 'controller-wiki action-rename',
            'page_title' => $originalTitle,
        ]);
    }

    #[Route('/projects/{project_id}/wiki/{id}/protect', name: 'wiki_protect', methods: ['POST'])]
    public function protect(Request $request, string $project_id, string $id): Response
    {
        $wiki = $this->finder->wiki($project_id);
        $project = $wiki->project;
        $this->authorize('protect_wiki_pages', $project);
        $page = $this->finder->page($wiki, $id);
        $this->attributes->protect($page, $project, \in_array((string) ($request->request->get('protected') ?? $request->query->get('protected')), ['1', 'true'], true));

        return $this->redirectToRoute('wiki_show', ['project_id' => $project->identifier, 'id' => $page->title]);
    }

    // ------------------------------------------------------------------ history / diff / annotate

    #[Route('/projects/{project_id}/wiki/{id}/history', name: 'wiki_history', methods: ['GET'])]
    public function history(Request $request, string $project_id, string $id): Response
    {
        $wiki = $this->finder->wiki($project_id);
        $this->authorize('view_wiki_edits', $wiki->project);
        $page = $this->finder->page($wiki, $id);
        if ($this->wikis->pageFoundWithRedirect()) {
            return $this->redirectToRoute('wiki_history', $this->finder->routeOf($page));
        }
        $pager = $this->paginate($request, $this->wikis->versionsCount($this->finder->content($page)));

        return $this->render('wiki/history.html.twig', $this->views->history($wiki, $page, $pager) + [
            'main_menu_selected' => 'wiki',
            'body_css' => 'controller-wiki action-history',
            'page_title' => $this->l('label_history').' - '.$page->prettyTitle(),
        ]);
    }

    #[Route('/projects/{project_id}/wiki/{id}/{version}/diff', name: 'wiki_diff_version', requirements: ['version' => '\d+'], methods: ['GET'])]
    #[Route('/projects/{project_id}/wiki/{id}/diff', name: 'wiki_diff', methods: ['GET'])]
    public function diff(Request $request, string $project_id, string $id, ?string $version = null): Response
    {
        $wiki = $this->finder->wiki($project_id);
        $this->authorize('view_wiki_edits', $wiki->project);
        $page = $this->finder->page($wiki, $id);
        if ($this->wikis->pageFoundWithRedirect()) {
            return $this->redirectToRoute('wiki_diff', $this->finder->routeOf($page));
        }

        return $this->render('wiki/diff.html.twig', $this->views->diff($wiki, $page, $version ?? $request->query->get('version'), $request->query->get('version_from')) + [
            'main_menu_selected' => 'wiki',
            'body_css' => 'controller-wiki action-diff',
            'page_title' => $page->prettyTitle(),
        ]);
    }

    #[Route('/projects/{project_id}/wiki/{id}/{version}/annotate', name: 'wiki_annotate', requirements: ['version' => '\d+'], methods: ['GET'])]
    public function annotate(string $project_id, string $id, string $version): Response
    {
        $wiki = $this->finder->wiki($project_id);
        $this->authorize('view_wiki_edits', $wiki->project);
        $page = $this->finder->page($wiki, $id);
        if ($this->wikis->pageFoundWithRedirect()) {
            return $this->redirectToRoute('wiki_annotate', $this->finder->routeOf($page, ['version' => $version]));
        }

        return $this->render('wiki/annotate.html.twig', $this->views->annotate($wiki, $page, (int) $version) + [
            'main_menu_selected' => 'wiki',
            'body_css' => 'controller-wiki action-annotate',
            'page_title' => $page->prettyTitle(),
        ]);
    }

    // ------------------------------------------------------------------ destroy

    #[Route('/projects/{project_id}/wiki/{id}.{_format}', name: 'wiki_destroy_api', requirements: ['_format' => self::FMT], methods: ['DELETE'])]
    #[Route('/projects/{project_id}/wiki/{id}', name: 'wiki_destroy', methods: ['DELETE'])]
    public function destroy(Request $request, string $project_id, string $id): Response
    {
        $wiki = $this->finder->wiki($project_id);
        $this->authorize('delete_wiki_pages', $wiki->project);
        $page = $this->finder->page($wiki, $id);
        if (!$this->wikis->isEditable($page)) {
            $this->deny();
        }
        if (!$this->remover->remove($wiki, $page, $request)) {
            return $this->render('wiki/destroy.html.twig', $this->views->destroy($wiki, $page) + [
                'main_menu_selected' => 'wiki',
                'body_css' => 'controller-wiki action-destroy',
                'page_title' => $page->prettyTitle(),
            ]);
        }
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }
        $this->flashNotice('notice_successful_delete');

        return $this->redirectToRoute('wiki_index_by_title', ['project_id' => $wiki->project->identifier]);
    }

    #[Route('/projects/{project_id}/wiki/{id}/{version}', name: 'wiki_destroy_version', requirements: ['version' => '\d+'], methods: ['DELETE'])]
    public function destroyVersion(Request $request, string $project_id, string $id, string $version): Response
    {
        $wiki = $this->finder->wiki($project_id);
        $this->authorize('delete_wiki_pages', $wiki->project);
        $page = $this->finder->page($wiki, $id);
        if (!$this->wikis->isEditable($page)) {
            $this->deny();
        }
        $this->wikis->destroyVersion($page, $this->finder->version($page, (int) $version));
        $referer = (string) $request->headers->get('referer');
        if ('' !== $referer && parse_url($referer, \PHP_URL_HOST) === $request->getHost()) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('wiki_history', ['project_id' => $wiki->project->identifier, 'id' => $page->title]);
    }

    #[Route('/projects/{project_id}/wiki/{id}/add_attachment', name: 'wiki_add_attachment', methods: ['POST'])]
    public function addAttachment(Request $request, string $project_id, string $id): Response
    {
        $wiki = $this->finder->wiki($project_id);
        $this->authorize('edit_wiki_pages', $wiki->project);
        $page = $this->finder->page($wiki, $id);
        if (!$this->wikis->isEditable($page)) {
            $this->deny();
        }
        $errors = $this->form->addAttachments($page, $request);
        if ([] !== $errors) {
            $this->addFlash('warning', implode(', ', $errors));
        }

        return $this->redirectToRoute('wiki_show', ['project_id' => $wiki->project->identifier, 'id' => $page->title]);
    }

    /** WikisController#destroy: deletes the project's wiki (a new empty wiki is created) */
    #[Route('/projects/{id}/wiki/destroy', name: 'wikis_destroy', methods: ['GET', 'POST'], priority: 20)]
    public function destroyWiki(Request $request, string $id): Response
    {
        $project = $this->findProject($id);
        $this->authorize('manage_wiki', $project);
        $wiki = $this->wikis->wikiFor($project, false);
        if ($request->isMethod('POST') && $request->request->get('confirm') && null !== $wiki) {
            $this->wikis->recreate($wiki, $project);

            return $this->redirectToRoute('project_show', ['id' => $project->identifier]);
        }

        return $this->render('wikis/destroy.html.twig', ['project' => $project, 'main_menu_selected' => 'wiki', 'body_css' => 'controller-wikis action-destroy', 'page_title' => $this->l('label_confirmation')]);
    }
}

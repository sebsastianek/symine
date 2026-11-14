<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\DocumentApiPresenter;
use App\Service\Content\Documents\DocumentFinder;
use App\Service\Content\Documents\DocumentForm;
use App\Service\Content\Documents\DocumentPages;
use App\Service\Content\Documents\DocumentService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of DocumentsController (documents_controller.rb). */
final class DocumentsController extends AbstractController
{
    public function __construct(
        private readonly ApiRenderer $api,
        private readonly DocumentFinder $finder,
        private readonly DocumentService $documents,
        private readonly DocumentPages $pages,
        private readonly DocumentForm $form,
        private readonly DocumentApiPresenter $presenter,
    ) {
    }

    #[Route('/projects/{project_id}/documents.{_format}', name: 'project_documents', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function index(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('view_documents', $project);

        if ($this->isApi($request)) {
            $count = $this->documents->count($project);
            $pager = $this->paginate($request, $count);

            return $this->api->render($request, $this->presenter->index($this->documents->page($project, $pager['offset'], $pager['limit']), $count, $pager));
        }

        return $this->render('documents/index.html.twig', $this->pages->index($project, $request->query->get('sort_by')) + $this->form->view($this->documents->build($project)) + [
            'main_menu_selected' => 'documents', 'page_title' => $this->l('label_document_plural'),
            'body_css' => 'controller-documents action-index',
        ]);
    }

    #[Route('/projects/{project_id}/documents/new', name: 'document_new', methods: ['GET'])]
    public function new(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('add_documents', $project);
        $document = $this->documents->build($project);
        $values = $this->form->prefill($document, $request);

        return $this->render('documents/new.html.twig', ['project' => $project] + $this->form->view($document, [], $values) + [
            'main_menu_selected' => 'documents', 'page_title' => $this->l('label_document_new'),
            'body_css' => 'controller-documents action-new',
        ]);
    }

    #[Route('/projects/{project_id}/documents.{_format}', name: 'document_create', methods: ['POST'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function create(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('add_documents', $project);
        $document = $this->documents->build($project);
        $result = $this->form->create($document, $request);
        if ($this->isApi($request)) {
            if (!$result->isSaved()) {
                return $this->api->errors($request, $result->errors);
            }
            $response = $this->api->render($request, ['document' => $this->presenter->withAttachments($request, $document)], 201);
            $response->headers->set('Location', $this->generateUrl('document_show', ['id' => $document->id], 0));

            return $response;
        }
        if (!$result->isSaved()) {
            return $this->render('documents/new.html.twig', ['project' => $project] + $this->form->view($document, $result->errors, $result->customFieldValues) + [
                'main_menu_selected' => 'documents', 'page_title' => $this->l('label_document_new'),
                'body_css' => 'controller-documents action-new',
            ], new Response(null, 422));
        }
        if ($result->unsavedAttachments > 0) {
            $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => $result->unsavedAttachments]));
        }
        $this->flashNotice('notice_successful_create');

        return $this->redirectToRoute('project_documents', ['project_id' => $project->identifier]);
    }

    #[Route('/documents/{id}.{_format}', name: 'document_show', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function show(Request $request, int $id): Response
    {
        $document = $this->finder->find($id);
        $this->authorize('view_documents', $document->project);
        if ($this->isApi($request)) {
            return $this->api->render($request, ['document' => $this->presenter->shown($request, $document)]);
        }

        return $this->render('documents/show.html.twig', $this->pages->show($document) + [
            'main_menu_selected' => 'documents', 'page_title' => $document->title,
            'body_css' => 'controller-documents action-show',
        ]);
    }

    #[Route('/documents/{id}/edit', name: 'document_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $document = $this->finder->find($id);
        $this->authorize('edit_documents', $document->project);

        return $this->render('documents/edit.html.twig', ['project' => $document->project] + $this->form->view($document) + [
            'main_menu_selected' => 'documents', 'page_title' => $document->title,
            'body_css' => 'controller-documents action-edit',
        ]);
    }

    #[Route('/documents/{id}.{_format}', name: 'document_update', methods: ['PUT', 'PATCH'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function update(Request $request, int $id): Response
    {
        $document = $this->finder->find($id);
        $this->authorize('edit_documents', $document->project);
        $result = $this->form->update($document, $request);
        if ($this->isApi($request)) {
            return $result->isSaved() ? $this->api->noContent() : $this->api->errors($request, $result->errors);
        }
        if (!$result->isSaved()) {
            return $this->render('documents/edit.html.twig', ['project' => $document->project] + $this->form->view($document, $result->errors, $result->customFieldValues) + [
                'main_menu_selected' => 'documents', 'page_title' => $document->title,
                'body_css' => 'controller-documents action-edit',
            ], new Response(null, 422));
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('document_show', ['id' => $document->id]);
    }

    #[Route('/documents/{id}.{_format}', name: 'document_destroy', methods: ['DELETE'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function destroy(Request $request, int $id): Response
    {
        $document = $this->finder->find($id);
        $project = $document->project;
        $this->authorize('delete_documents', $project);
        $this->documents->destroy($document);
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }
        $this->flashNotice('notice_successful_delete');

        return $this->redirectToRoute('project_documents', ['project_id' => $project->identifier]);
    }

    #[Route('/documents/{id}/add_attachment', name: 'document_add_attachment', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addAttachment(Request $request, int $id): Response
    {
        $document = $this->finder->find($id);
        if (!$this->auth->allowedTo('add_documents', $document->project) && !$this->auth->allowedTo('edit_documents', $document->project)) {
            $this->deny();
        }
        $unsaved = $this->form->addAttachments($document, $request);
        if ($unsaved > 0) {
            $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => $unsaved]));
        }

        return $this->redirectToRoute('document_show', ['id' => $document->id]);
    }
}

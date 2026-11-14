<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\AttachmentApiPresenter;
use App\Api\IssueApiPresenter;
use App\Service\Content\Attachments\AttachmentAccess;
use App\Service\Content\Attachments\AttachmentContainers;
use App\Service\Content\Attachments\AttachmentFinder;
use App\Service\Content\Attachments\AttachmentForm;
use App\Service\Content\Attachments\AttachmentPreview;
use App\Service\Content\Attachments\AttachmentRemover;
use App\Service\Content\Attachments\AttachmentResponder;
use App\Service\Content\Attachments\AttachmentScripts;
use App\Service\Content\Attachments\AttachmentUploader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's AttachmentsController: preview, download, thumbnails, uploads, edit and delete. */
final class AttachmentsController extends AbstractController
{
    public function __construct(
        private readonly ApiRenderer $api,
        private readonly IssueApiPresenter $presenter,
        private readonly AttachmentApiPresenter $uploadPresenter,
        private readonly AttachmentFinder $finder,
        private readonly AttachmentAccess $access,
        private readonly AttachmentContainers $containers,
        private readonly AttachmentPreview $preview,
        private readonly AttachmentResponder $responder,
        private readonly AttachmentForm $form,
        private readonly AttachmentUploader $uploader,
        private readonly AttachmentRemover $remover,
        private readonly AttachmentScripts $scripts,
    ) {
    }

    // ------------------------------------------------------------------------------------------

    #[Route('/attachments/{id}/{filename}', name: 'attachment_show', requirements: ['id' => '\d+', 'filename' => '.+'], defaults: ['filename' => null], methods: ['GET'])]
    public function show(Request $request, int $id, ?string $filename = null): Response
    {
        $a = $this->finder->find($id, $filename);
        $this->access->requireVisible($a);

        return $this->render('attachments/show.html.twig', $this->preview->view($a, $request) + [
            'page_title' => $a->filename,
            'body_css' => 'controller-attachments action-show',
        ]);
    }

    #[Route('/attachments/{id}.{_format}', name: 'attachment_api_show', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['GET'])]
    public function apiShow(Request $request, int $id): Response
    {
        $a = $this->finder->find($id);
        $this->access->requireVisible($a);

        return $this->api->render($request, ['attachment' => $this->presenter->attachment($a)]);
    }

    #[Route('/attachments/download/{id}/{filename}', name: 'attachment_download', requirements: ['id' => '\d+', 'filename' => '.+'], defaults: ['filename' => null], methods: ['GET'])]
    public function download(Request $request, int $id, ?string $filename = null): Response
    {
        $a = $this->finder->find($id, $filename);
        $this->access->requireVisible($a);

        return $this->responder->download($request, $a);
    }

    #[Route('/attachments/thumbnail/{id}/{size}', name: 'attachment_thumbnail', requirements: ['id' => '\d+', 'size' => '\d+'], defaults: ['size' => null], methods: ['GET'])]
    public function thumbnail(Request $request, int $id, ?int $size = null): Response
    {
        $a = $this->finder->find($id);
        $this->access->requireVisible($a);

        return $this->responder->thumbnail($a, $size);
    }

    #[Route('/attachments/{id}.{_format}', name: 'attachment_update', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['PATCH', 'PUT'])]
    public function update(Request $request, int $id): Response
    {
        $a = $this->finder->find($id);
        $this->access->requireEditable($a);
        $errors = $this->form->update($a, $request);
        if ([] !== $errors) {
            return $this->api->errors($request, $errors);
        }

        return $this->api->noContent();
    }

    #[Route('/attachments/{id}.{_format}', name: 'attachment_destroy', requirements: ['id' => '\d+', '_format' => 'html|js|json|xml'], defaults: ['_format' => 'html'], methods: ['DELETE'])]
    public function destroy(Request $request, int $id, string $_format = 'html'): Response
    {
        $a = $this->finder->find($id);
        $this->access->requireDeletable($a);
        $project = $this->remover->remove($a);

        if ($this->isApi($request)) {
            return $this->api->noContent();
        }
        if ('js' === $_format || $request->isXmlHttpRequest()) {
            return new Response($this->scripts->removed($request), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }
        $ref = (string) $request->headers->get('referer');
        if ('' !== $ref) {
            return $this->redirect($ref);
        }

        return $project ? $this->redirectToRoute('project_show', ['id' => $project->identifier]) : $this->redirectToRoute('home');
    }

    // ------------------------------------------------------------------------------------------
    // Edit all / download all
    // ------------------------------------------------------------------------------------------

    #[Route('/attachments/{object_type}/{object_id}/edit', name: 'attachments_edit_all', requirements: ['object_type' => 'issues|wiki_pages|documents|messages|news|projects|versions', 'object_id' => '\d+'], methods: ['GET'])]
    public function editAll(Request $request, string $object_type, int $object_id): Response
    {
        $atts = $this->finder->editable($object_type, $object_id);

        return $this->render('attachments/edit_all.html.twig', $this->form->view($request, $object_type, $object_id, $atts) + [
            'page_title' => $this->l('label_edit_attachments'),
            'body_css' => 'controller-attachments action-edit_all',
        ]);
    }

    #[Route('/attachments/{object_type}/{object_id}', name: 'attachments_update_all', requirements: ['object_type' => 'issues|wiki_pages|documents|messages|news|projects|versions', 'object_id' => '\d+'], methods: ['PATCH', 'POST'])]
    public function updateAll(Request $request, string $object_type, int $object_id): Response
    {
        $atts = $this->finder->editable($object_type, $object_id);
        $errors = $this->form->updateAll($atts, $request);
        if ([] === $errors) {
            return $this->redirectBackOr($request, $this->containers->url($atts[0]) ?? $this->generateUrl('home'));
        }

        return $this->render('attachments/edit_all.html.twig', $this->form->view($request, $object_type, $object_id, $atts, $errors) + [
            'page_title' => $this->l('label_edit_attachments'),
            'body_css' => 'controller-attachments action-edit_all',
        ]);
    }

    #[Route('/attachments/{object_type}/{object_id}/download', name: 'attachments_download_all', requirements: ['object_type' => 'issues|wiki_pages|documents|messages|news|projects|versions', 'object_id' => '\d+'], methods: ['GET'])]
    public function downloadAll(Request $request, string $object_type, int $object_id): Response
    {
        $atts = $this->finder->downloadable($object_type, $object_id);
        $maxSize = $this->responder->bulkDownloadRefusal($atts);
        if (null !== $maxSize) {
            $this->flashError($this->l('error_bulk_download_size_too_big', ['max_size' => $maxSize]));

            return $this->redirect($this->containers->url($atts[0]) ?? $this->generateUrl('home'));
        }

        return $this->responder->archive($atts, $object_type, $object_id);
    }

    // ------------------------------------------------------------------------------------------
    // Upload
    // ------------------------------------------------------------------------------------------

    #[Route('/uploads.{_format}', name: 'uploads', requirements: ['_format' => 'json|xml|js'], defaults: ['_format' => 'json'], methods: ['POST'])]
    public function upload(Request $request, string $_format = 'json'): Response
    {
        $this->uploader->requireAllowed();
        if (!$this->uploader->accepts($request)) {
            return new Response('', 406);
        }
        [$attachment, $errors] = $this->uploader->upload($request);
        if ('js' === $_format) {
            return new Response(null === $attachment ? $this->scripts->refused($request, $errors) : $this->scripts->uploaded($request, $attachment), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }
        if (null === $attachment) {
            return $this->api->errors($request, $errors);
        }

        return $this->api->render($request, $this->uploadPresenter->upload($attachment), 201);
    }
}

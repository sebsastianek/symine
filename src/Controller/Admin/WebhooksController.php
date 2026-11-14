<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Service\Admin\WebhookManager;
use App\Service\Admin\Webhooks\WebhookAccess;
use App\Service\Admin\Webhooks\WebhookForm;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine 7's WebhooksController (/webhooks: webhooks of the current user, all of them for admins). */
final class WebhooksController extends AbstractController
{
    public function __construct(
        private readonly WebhookManager $webhooks,
        private readonly WebhookAccess $access,
        private readonly WebhookForm $form,
    ) {
    }

    #[Route('/webhooks', name: 'webhooks_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->access->check(false);

        return $this->render('admin/webhooks/index.html.twig', [
            'webhooks' => $this->webhooks->ownedBy($this->user()),
            'page_title' => $this->l('label_webhook_plural'),
            'body_css' => 'controller-webhooks action-index',
        ]);
    }

    #[Route('/webhooks/new', name: 'webhook_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->access->check(false);

        return $this->render('admin/webhooks/new.html.twig', $this->form->view('new', $this->form->blank($request), $request));
    }

    #[Route('/webhooks', name: 'webhook_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->access->check(false);
        $submission = $this->form->create($request);
        if (!$submission->isSaved()) {
            return $this->render('admin/webhooks/new.html.twig', $this->form->view('new', $submission->webhook, $request, $submission->errors), new Response(null, 422));
        }
        $this->flashNotice('notice_successful_create');

        return $this->redirectBackOr($request, $this->generateUrl('webhooks_index'));
    }

    #[Route('/webhooks/{id}/edit', name: 'webhook_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        $this->access->check(true);

        return $this->render('admin/webhooks/edit.html.twig', $this->form->view('edit', $this->access->find($id), $request));
    }

    #[Route('/webhooks/{id}', name: 'webhook_update', requirements: ['id' => '\d+'], methods: ['PATCH', 'PUT', 'POST'])]
    public function update(Request $request, int $id): Response
    {
        $this->access->check(true);
        $submission = $this->form->update($this->access->find($id), $request);
        if (!$submission->isSaved()) {
            return $this->render('admin/webhooks/edit.html.twig', $this->form->view('edit', $submission->webhook, $request, $submission->errors, $submission->submitted), new Response(null, 422));
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectBackOr($request, $this->generateUrl('webhooks_index'));
    }

    #[Route('/webhooks/{id}', name: 'webhook_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(Request $request, int $id): Response
    {
        $this->access->check(true);
        $this->webhooks->delete($this->access->find($id));
        $this->flashNotice('notice_successful_delete');

        return $this->redirectBackOr($request, $this->generateUrl('webhooks_index'));
    }
}

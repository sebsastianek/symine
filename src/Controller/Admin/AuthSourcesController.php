<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Service\Admin\AuthSources\AuthSourceForm;
use App\Service\Admin\AuthSources\AuthSources;
use App\Service\Admin\AuthSources\LdapUserSuggestions;
use App\Service\LdapService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's AuthSourcesController (LDAP authentication modes). */
final class AuthSourcesController extends AbstractController
{
    public function __construct(
        private readonly AuthSources $sources,
        private readonly AuthSourceForm $form,
        private readonly LdapUserSuggestions $suggestions,
        private readonly LdapService $ldap,
    ) {
    }

    #[Route('/auth_sources', name: 'auth_sources_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin();

        return $this->render('admin/auth_sources/index.html.twig', $this->sources->view($request));
    }

    #[Route('/auth_sources/new', name: 'auth_source_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin();

        return $this->render('admin/auth_sources/new.html.twig', $this->form->view($this->form->blank($request)), new Response(null, 200, ['Cache-Control' => 'no-store']));
    }

    #[Route('/auth_sources', name: 'auth_source_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $submission = $this->form->create($request);
        if (!$submission->isSaved()) {
            return $this->render('admin/auth_sources/new.html.twig', $this->form->view($submission->source, $submission->errors), new Response(null, 422, ['Cache-Control' => 'no-store']));
        }
        $this->flashNotice('notice_successful_create');

        return $this->redirectToRoute('auth_sources_index');
    }

    #[Route('/auth_sources/{id}/edit', name: 'auth_source_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->requireAdmin();

        return $this->render('admin/auth_sources/edit.html.twig', $this->form->view($this->sources->find($id)), new Response(null, 200, ['Cache-Control' => 'no-store']));
    }

    #[Route('/auth_sources/{id}', name: 'auth_source_update', requirements: ['id' => '\d+'], methods: ['PATCH', 'PUT', 'POST'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $submission = $this->form->update($this->sources->find($id), $request);
        if (!$submission->isSaved()) {
            $response = $this->render('admin/auth_sources/edit.html.twig', $this->form->view($submission->source, $submission->errors), new Response(null, 422, ['Cache-Control' => 'no-store']));
            $this->form->discard($submission->source);

            return $response;
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('auth_sources_index');
    }

    #[Route('/auth_sources/{id}/test_connection', name: 'auth_source_test_connection', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function testConnection(int $id): Response
    {
        $this->requireAdmin();
        $source = $this->sources->find($id);
        try {
            $this->ldap->testConnection($source);
            $this->flashNotice('notice_successful_connection');
        } catch (\Throwable $e) {
            $this->flashError($this->l('error_unable_to_connect', ['value' => $e->getMessage()]));
        }

        return $this->redirectToRoute('auth_sources_index');
    }

    #[Route('/auth_sources/{id}', name: 'auth_source_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->requireAdmin();
        if ($this->sources->remove($this->sources->find($id))) {
            $this->flashNotice('notice_successful_delete');
        } else {
            $this->flashError($this->l('error_can_not_delete_auth_source'));
        }

        return $this->redirectToRoute('auth_sources_index');
    }

    #[Route('/auth_sources/autocomplete_for_new_user', name: 'auth_source_autocomplete_for_new_user', methods: ['GET'])]
    public function autocompleteForNewUser(Request $request): JsonResponse
    {
        $this->requireAdmin();

        return new JsonResponse($this->suggestions->matching((string) $request->query->get('term', '')));
    }
}

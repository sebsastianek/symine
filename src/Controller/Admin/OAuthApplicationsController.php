<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Entity\OauthApplication;
use App\OAuth\Admin\ApplicationForm;
use App\OAuth\Admin\Applications;
use App\OAuth\OAuthServer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Redmine's Oauth2ApplicationsController (< Doorkeeper::ApplicationsController): administration of the
 * OAuth2 client applications, available to administrators when the REST API is enabled.
 */
final class OAuthApplicationsController extends AbstractController
{
    public function __construct(
        private readonly Applications $applications,
        private readonly ApplicationForm $form,
        private readonly OAuthServer $server,
    ) {
    }

    #[Route('/oauth/applications', name: 'oauth_applications', methods: ['GET'])]
    public function index(): Response
    {
        $this->applications->authenticateAdmin();

        return $this->render('oauth/applications/index.html.twig', [
            'applications' => $this->applications->all(),
            'admin_menu_selected' => 'applications',
            'page_title' => $this->l('label_oauth_application_plural'),
            'body_css' => 'controller-oauth2_applications action-index',
        ]);
    }

    #[Route('/oauth/applications/new', name: 'new_oauth_application', methods: ['GET'])]
    public function new(): Response
    {
        $this->applications->authenticateAdmin();

        return $this->render('oauth/applications/new.html.twig', $this->form->view('new', new OauthApplication()));
    }

    #[Route('/oauth/applications', name: 'oauth_applications_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->applications->authenticateAdmin();
        $submission = $this->form->create($request);
        if (!$submission->isSaved()) {
            return $this->render('oauth/applications/new.html.twig', $this->form->view('new', $submission->application, $submission->errors));
        }
        $this->addFlash('notice', $this->l('doorkeeper.flash.applications.create.notice'));
        $this->applications->rememberSecret($request, (string) $submission->secret);

        return $this->redirectToRoute('oauth_application', ['id' => $submission->application->id]);
    }

    #[Route('/oauth/applications/{id}', name: 'oauth_application', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $this->applications->authenticateAdmin();
        $app = $this->applications->find($id);

        return $this->render('oauth/applications/show.html.twig', [
            'application' => $app,
            'secret' => $this->applications->takeSecret($request),
            'admin_menu_selected' => 'applications',
            'page_title' => $app->name.' - '.$this->l('label_oauth_application_plural'),
            'body_css' => 'controller-oauth2_applications action-show',
        ]);
    }

    #[Route('/oauth/applications/{id}/edit', name: 'edit_oauth_application', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->applications->authenticateAdmin();

        return $this->render('oauth/applications/edit.html.twig', $this->form->view('edit', $this->applications->find($id)));
    }

    #[Route('/oauth/applications/{id}', name: 'oauth_application_update', requirements: ['id' => '\d+'], methods: ['PATCH', 'PUT'])]
    public function update(Request $request, int $id): Response
    {
        $this->applications->authenticateAdmin();
        $submission = $this->form->update($this->applications->find($id), $request);
        if (!$submission->isSaved()) {
            return $this->render('oauth/applications/edit.html.twig', $this->form->view('edit', $submission->application, $submission->errors));
        }
        $this->addFlash('notice', $this->l('doorkeeper.flash.applications.update.notice'));

        return $this->redirectToRoute('oauth_application', ['id' => $submission->application->id]);
    }

    #[Route('/oauth/applications/{id}', name: 'oauth_application_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->applications->authenticateAdmin();
        $this->server->destroyApplication($this->applications->find($id));
        $this->addFlash('notice', $this->l('doorkeeper.flash.applications.destroy.notice'));

        return $this->redirectToRoute('oauth_applications');
    }
}

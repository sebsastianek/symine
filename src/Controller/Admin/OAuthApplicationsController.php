<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Entity\OauthApplication;
use App\OAuth\OAuthServer;
use App\Security\AccessControl;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Redmine's Oauth2ApplicationsController (< Doorkeeper::ApplicationsController): administration of the
 * OAuth2 client applications, available to administrators when the REST API is enabled.
 */
final class OAuthApplicationsController extends AbstractController
{
    private const string FLASH_SECRET = 'oauth_application_secret';

    public function __construct(private readonly OAuthServer $server)
    {
    }

    #[Route('/oauth/applications', name: 'oauth_applications', methods: ['GET'])]
    public function index(): Response
    {
        $this->authenticateAdmin();

        return $this->render('oauth/applications/index.html.twig', [
            'applications' => $this->em->getRepository(OauthApplication::class)->findBy([], ['createdAt' => 'ASC', 'id' => 'ASC']),
            'admin_menu_selected' => 'applications',
            'page_title' => $this->l('label_oauth_application_plural'),
            'body_css' => 'controller-oauth2_applications action-index',
        ]);
    }

    #[Route('/oauth/applications/new', name: 'new_oauth_application', methods: ['GET'])]
    public function new(): Response
    {
        $this->authenticateAdmin();

        return $this->renderForm('new', new OauthApplication(), []);
    }

    #[Route('/oauth/applications', name: 'oauth_applications_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->authenticateAdmin();
        $app = new OauthApplication();
        $this->applyParams($app, $request);
        $app->uid = OAuthServer::generateToken();
        $errors = $this->server->applicationErrors($app);
        if ([] !== $errors) {
            return $this->renderForm('new', $app, $errors);
        }
        $secret = $this->server->renewSecret($app);
        $this->em->persist($app);
        $this->em->flush();
        $this->addFlash('notice', $this->i18n->l('doorkeeper.flash.applications.create.notice'));
        $request->getSession()->set(self::FLASH_SECRET, $secret);

        return $this->redirectToRoute('oauth_application', ['id' => $app->id]);
    }

    #[Route('/oauth/applications/{id}', name: 'oauth_application', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $this->authenticateAdmin();
        $app = $this->find($id);
        $secret = $request->getSession()->get(self::FLASH_SECRET);
        $request->getSession()->remove(self::FLASH_SECRET);

        return $this->render('oauth/applications/show.html.twig', [
            'application' => $app,
            'secret' => \is_string($secret) && '' !== $secret ? $secret : null,
            'admin_menu_selected' => 'applications',
            'page_title' => $app->name.' - '.$this->l('label_oauth_application_plural'),
            'body_css' => 'controller-oauth2_applications action-show',
        ]);
    }

    #[Route('/oauth/applications/{id}/edit', name: 'edit_oauth_application', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->authenticateAdmin();

        return $this->renderForm('edit', $this->find($id), []);
    }

    #[Route('/oauth/applications/{id}', name: 'oauth_application_update', requirements: ['id' => '\d+'], methods: ['PATCH', 'PUT'])]
    public function update(Request $request, int $id): Response
    {
        $this->authenticateAdmin();
        $app = $this->find($id);
        $this->applyParams($app, $request);
        $errors = $this->server->applicationErrors($app);
        if ([] !== $errors) {
            return $this->renderForm('edit', $app, $errors);
        }
        $app->updatedAt = new \DateTimeImmutable();
        $this->em->flush();
        $this->addFlash('notice', $this->i18n->l('doorkeeper.flash.applications.update.notice'));

        return $this->redirectToRoute('oauth_application', ['id' => $app->id]);
    }

    #[Route('/oauth/applications/{id}', name: 'oauth_application_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->authenticateAdmin();
        $this->server->destroyApplication($this->find($id));
        $this->addFlash('notice', $this->i18n->l('doorkeeper.flash.applications.destroy.notice'));

        return $this->redirectToRoute('oauth_applications');
    }

    // ------------------------------------------------------------------------------------

    /** Redmine's admin_authenticator: REST API enabled and administrator */
    private function authenticateAdmin(): void
    {
        $this->requireLogin();
        if (!$this->settings->restApiEnabled() || !$this->user()->admin) {
            $this->deny();
        }
    }

    private function find(int $id): OauthApplication
    {
        $app = $this->em->find(OauthApplication::class, $id);
        if (null === $app) {
            throw $this->createNotFoundException();
        }

        return $app;
    }

    /** Oauth2ApplicationsController#application_params: public permissions are always granted */
    private function applyParams(OauthApplication $app, Request $request): void
    {
        $p = $request->request->all('doorkeeper_application');
        if (\array_key_exists('name', $p)) {
            $app->name = trim((string) $p['name']);
        }
        if (\array_key_exists('redirect_uri', $p)) {
            $uri = $p['redirect_uri'];
            $app->redirectUri = \is_array($uri) ? implode("\n", array_map('strval', $uri)) : (string) $uri;
        }
        if (\array_key_exists('confidential', $p)) {
            $app->confidential = \in_array((string) $p['confidential'], ['1', 'true', 'on'], true);
        }
        $given = $p['scopes'] ?? [];
        $given = \is_array($given) ? array_map('strval', $given) : (preg_split('/\s+/', (string) $given) ?: []);
        $scopes = OAuthServer::defaultScopes();
        foreach ($given as $s) {
            if (!\in_array($s, $scopes, true)) {
                $scopes[] = $s;
            }
        }
        $app->scopes = implode(' ', OAuthServer::scopesFromString(implode(' ', $scopes)));
    }

    private function renderForm(string $action, OauthApplication $app, array $errors): Response
    {
        $byModule = [];
        foreach (AccessControl::permissions() as $name => $def) {
            $byModule[(string) ($def['module'] ?? '')][] = ['name' => $name, 'public' => !empty($def['public'])];
        }
        ksort($byModule, \SORT_STRING);

        return $this->render('oauth/applications/'.$action.'.html.twig', [
            'application' => $app,
            'errors' => $errors,
            'permissions_by_module' => $byModule,
            'app_scopes' => $app->getScopes(),
            'admin_menu_selected' => 'applications',
            'page_title' => ('new' === $action ? $this->i18n->l('doorkeeper.applications.new.title') : $app->name).' - '.$this->l('label_oauth_application_plural'),
            'body_css' => 'controller-oauth2_applications action-'.$action,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\OAuth\Admin;

use App\Entity\OauthApplication;
use App\I18n\Localizer;
use App\OAuth\OAuthServer;
use App\Security\AccessControl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The form of an OAuth2 client application (Oauth2ApplicationsController#new, #create, #edit, #update):
 * the doorkeeper_application[...] parameters, saving, and what the form shows.
 */
final class ApplicationForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OAuthServer $server,
        private readonly Localizer $i18n,
    ) {
    }

    /** Creates the application with a new client id and secret */
    public function create(Request $request): ApplicationSubmission
    {
        $app = new OauthApplication();
        $this->applyParams($app, $request);
        $app->uid = OAuthServer::generateToken();
        $errors = $this->server->applicationErrors($app);
        if ([] !== $errors) {
            return new ApplicationSubmission($app, $errors);
        }
        $secret = $this->server->renewSecret($app);
        $this->em->persist($app);
        $this->em->flush();

        return new ApplicationSubmission($app, [], $secret);
    }

    public function update(OauthApplication $app, Request $request): ApplicationSubmission
    {
        $this->applyParams($app, $request);
        $errors = $this->server->applicationErrors($app);
        if ([] === $errors) {
            $app->updatedAt = new \DateTimeImmutable();
            $this->em->flush();
        }

        return new ApplicationSubmission($app, $errors);
    }

    /**
     * Variables of the form page ("new" or "edit").
     *
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    public function view(string $action, OauthApplication $app, array $errors = []): array
    {
        $byModule = [];
        foreach (AccessControl::permissions() as $name => $def) {
            $byModule[(string) ($def['module'] ?? '')][] = ['name' => $name, 'public' => !empty($def['public'])];
        }
        ksort($byModule, \SORT_STRING);

        return [
            'application' => $app,
            'errors' => $errors,
            'permissions_by_module' => $byModule,
            'app_scopes' => $app->getScopes(),
            'admin_menu_selected' => 'applications',
            'page_title' => ('new' === $action ? $this->i18n->l('doorkeeper.applications.new.title') : $app->name).' - '.$this->i18n->l('label_oauth_application_plural'),
            'body_css' => 'controller-oauth2_applications action-'.$action,
        ];
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
}

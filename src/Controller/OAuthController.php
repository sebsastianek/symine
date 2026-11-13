<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\OauthApplication;
use App\OAuth\OAuthServer;
use App\OAuth\PreAuthorization;
use App\Service\AccountService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * OAuth2 provider endpoints (Doorkeeper's AuthorizationsController, TokensController, TokenInfoController
 * and AuthorizedApplicationsController as used by Redmine 6+).
 */
final class OAuthController extends AbstractController
{
    public function __construct(
        private readonly OAuthServer $server,
        private readonly AccountService $accounts,
    ) {
    }

    // --- AuthorizationsController -------------------------------------------------------

    #[Route('/oauth/authorize', name: 'oauth_authorization', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $user = $this->resourceOwner();
        $pre = $this->server->preAuthorize($request->query->all());
        if (!$pre->isAuthorizable()) {
            return $this->renderError($pre);
        }
        if ($this->server->canAuthorizeWithoutConsent($pre, $user)) {
            return $this->redirectOrRender($pre, $this->issueCode($pre));
        }

        return $this->render('oauth/authorizations/new.html.twig', [
            'pre_auth' => $pre,
            'page_title' => $this->i18n->l('doorkeeper.authorizations.new.title'),
            'body_css' => 'controller-authorizations action-new',
        ]);
    }

    #[Route('/oauth/authorize', name: 'oauth_authorization_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->resourceOwner();
        $pre = $this->server->preAuthorize($request->request->all());

        return $this->redirectOrRender($pre, $pre->isAuthorizable() ? $this->issueCode($pre) : null);
    }

    #[Route('/oauth/authorize', name: 'oauth_authorization_destroy', methods: ['DELETE'])]
    public function destroy(Request $request): Response
    {
        $this->resourceOwner();
        $pre = $this->server->preAuthorize($request->request->all());
        if ($pre->isAuthorizable()) {
            // CodeRequest#deny
            $pre->error = 'access_denied';
        }

        return $this->redirectOrRender($pre, null);
    }

    #[Route('/oauth/authorize/native', name: 'native_oauth_authorization', methods: ['GET'])]
    public function native(Request $request): Response
    {
        $this->resourceOwner();

        return $this->render('oauth/authorizations/show.html.twig', [
            'code' => (string) $request->query->get('code', ''),
            'page_title' => $this->l('label_oauth_authorized_application_plural'),
            'body_css' => 'controller-authorizations action-show',
        ]);
    }

    // --- TokensController / TokenInfoController ---------------------------------------------

    #[Route('/oauth/token', name: 'oauth_token', methods: ['POST'])]
    public function token(Request $request): Response
    {
        $r = $this->server->tokenRequest($request);
        $response = new JsonResponse($r['body'], $r['status'], $r['headers']);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');

        return $response;
    }

    #[Route('/oauth/revoke', name: 'oauth_revoke', methods: ['POST'])]
    public function revoke(Request $request): Response
    {
        $r = $this->server->revoke($request);

        return new JsonResponse((object) $r['body'], $r['status'], ['Content-Type' => 'application/json; charset=utf-8']);
    }

    #[Route('/oauth/token/info', name: 'oauth_token_info', methods: ['GET'])]
    public function tokenInfo(Request $request): Response
    {
        $plain = OAuthServer::bearerToken($request);
        $token = null === $plain ? null : $this->server->authenticate($plain);
        if (null !== $token && $token->isAccessible()) {
            $app = null === $token->applicationId ? null : $this->em->find(OauthApplication::class, $token->applicationId);

            return new JsonResponse([
                'resource_owner_id' => $token->resourceOwnerId,
                'scope' => $token->getScopes(),
                'expires_in' => $token->expiresInSeconds(),
                'application' => ['uid' => $app?->uid],
                'created_at' => $token->createdAt->getTimestamp(),
            ], 200, ['Content-Type' => 'application/json; charset=utf-8']);
        }
        // OAuth::InvalidTokenResponse.new (reason :unknown, state :unauthorized)
        $description = $this->server->errorDescription('invalid_token', null, 'unknown');

        return new JsonResponse(['error' => 'invalid_token', 'error_description' => $description, 'state' => 'unauthorized'], 401, $this->server->errorHeaders('invalid_token', $description));
    }

    // --- AuthorizedApplicationsController ---------------------------------------------------

    #[Route('/oauth/authorized_applications', name: 'oauth_authorized_applications', methods: ['GET'])]
    public function authorizedApplications(): Response
    {
        $user = $this->resourceOwner();

        return $this->render('oauth/authorized_applications/index.html.twig', [
            'applications' => $this->server->authorizedApplications($user),
            'user' => $user,
            'deletable' => $this->accounts->ownAccountDeletable($user),
            'atom_token' => $this->accounts->tokenOf($user, 'feeds'),
            'api_token' => $this->accounts->tokenOf($user, 'api'),
            'page_title' => $this->l('label_oauth_authorized_application_plural').' - '.$this->l('label_my_account'),
            'body_css' => 'controller-authorized_applications action-index',
        ]);
    }

    #[Route('/oauth/authorized_applications/{id}', name: 'oauth_authorized_application', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function revokeApplication(int $id): Response
    {
        $user = $this->resourceOwner();
        $this->server->revokeTokensAndGrantsFor($id, $user);
        $this->addFlash('notice', $this->i18n->l('doorkeeper.flash.authorized_applications.destroy.notice'));

        return $this->redirectToRoute('oauth_authorized_applications');
    }

    // ------------------------------------------------------------------------------------------

    /** Redmine's resource_owner_authenticator: require_login, then the REST API must be enabled */
    private function resourceOwner(): \App\Entity\User
    {
        $this->requireLogin();
        if (!$this->settings->restApiEnabled()) {
            $this->deny();
        }

        return $this->user();
    }

    /** CodeRequest#authorize: issues the grant, returns the code */
    private function issueCode(PreAuthorization $pre): string
    {
        return $this->server->issueGrant($pre, $this->user());
    }

    private function renderError(PreAuthorization $pre): Response
    {
        $body = $this->server->errorBody((string) $pre->error, $pre->state, $pre->missingParam);

        return $this->render('oauth/authorizations/error.html.twig', [
            'error_description' => $body['error_description'],
            'page_title' => $this->i18n->l('doorkeeper.authorizations.error.title'),
            'body_css' => 'controller-authorizations action-new',
        ], new Response('', OAuthServer::errorStatus((string) $pre->error)));
    }

    /** AuthorizationsController#redirect_or_render ($code: issued code, null for an error response) */
    private function redirectOrRender(PreAuthorization $pre, ?string $code): Response
    {
        if (null !== $code) {
            $body = ['code' => $code, 'state' => $pre->state];
            if (OAuthServer::isOob($pre->redirectUri)) {
                return $this->redirect($this->generateUrl('native_oauth_authorization', ['code' => $code], UrlGeneratorInterface::ABSOLUTE_URL));
            }
            if ('form_post' === $pre->responseMode) {
                return $this->render('oauth/authorizations/form_post.html.twig', ['redirect_uri' => $pre->redirectUri, 'body' => array_filter($body, static fn ($v) => null !== $v)]);
            }

            return new RedirectResponse(OAuthServer::uriWith((string) $pre->redirectUri, $body, $pre->responseOnFragment()));
        }
        $error = (string) $pre->error;
        $body = $this->server->errorBody($error, $pre->state, $pre->missingParam);
        if (OAuthServer::isRedirectable($error, $pre->redirectUri, $pre->missingParam) && null !== $pre->redirectUri && '' !== $pre->redirectUri) {
            if ('form_post' === $pre->responseMode) {
                return $this->render('oauth/authorizations/form_post.html.twig', ['redirect_uri' => $pre->redirectUri, 'body' => $body]);
            }

            return new RedirectResponse(OAuthServer::uriWith($pre->redirectUri, $body, $pre->responseOnFragment()));
        }

        return new JsonResponse($body, OAuthServer::errorStatus($error));
    }
}

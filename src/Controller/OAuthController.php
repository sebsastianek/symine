<?php

declare(strict_types=1);

namespace App\Controller;

use App\OAuth\AuthorizationEndpoint;
use App\OAuth\OAuthServer;
use App\OAuth\ResourceOwner;
use App\OAuth\TokenInfo;
use App\Service\Account\MySidebar;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OAuth2 provider endpoints (Doorkeeper's AuthorizationsController, TokensController, TokenInfoController
 * and AuthorizedApplicationsController as used by Redmine 6+).
 */
final class OAuthController extends AbstractController
{
    public function __construct(
        private readonly OAuthServer $server,
        private readonly ResourceOwner $resourceOwner,
        private readonly AuthorizationEndpoint $authorizations,
        private readonly TokenInfo $tokenInfo,
        private readonly MySidebar $sidebar,
    ) {
    }

    // --- AuthorizationsController -------------------------------------------------------

    #[Route('/oauth/authorize', name: 'oauth_authorization', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $user = $this->resourceOwner->authenticate();
        $pre = $this->server->preAuthorize($request->query->all());
        if (!$pre->isAuthorizable()) {
            return $this->render('oauth/authorizations/error.html.twig', $this->authorizations->errorView($pre) + [
                'page_title' => $this->i18n->l('doorkeeper.authorizations.error.title'),
                'body_css' => 'controller-authorizations action-new',
            ], new Response('', OAuthServer::errorStatus((string) $pre->error)));
        }
        if ($this->server->canAuthorizeWithoutConsent($pre, $user)) {
            $result = $this->authorizations->authorize($pre, $user);

            return $result->response ?? $this->render('oauth/authorizations/form_post.html.twig', $result->formPost);
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
        $user = $this->resourceOwner->authenticate();
        $result = $this->authorizations->authorize($this->server->preAuthorize($request->request->all()), $user);

        return $result->response ?? $this->render('oauth/authorizations/form_post.html.twig', $result->formPost);
    }

    #[Route('/oauth/authorize', name: 'oauth_authorization_destroy', methods: ['DELETE'])]
    public function destroy(Request $request): Response
    {
        $this->resourceOwner->authenticate();
        $result = $this->authorizations->deny($this->server->preAuthorize($request->request->all()));

        return $result->response ?? $this->render('oauth/authorizations/form_post.html.twig', $result->formPost);
    }

    #[Route('/oauth/authorize/native', name: 'native_oauth_authorization', methods: ['GET'])]
    public function native(Request $request): Response
    {
        $this->resourceOwner->authenticate();

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
        $r = $this->tokenInfo->show($request);

        return new JsonResponse($r['body'], $r['status'], $r['headers']);
    }

    // --- AuthorizedApplicationsController ---------------------------------------------------

    #[Route('/oauth/authorized_applications', name: 'oauth_authorized_applications', methods: ['GET'])]
    public function authorizedApplications(): Response
    {
        $user = $this->resourceOwner->authenticate();

        return $this->render('oauth/authorized_applications/index.html.twig', [
            'applications' => $this->server->authorizedApplications($user),
            'page_title' => $this->l('label_oauth_authorized_application_plural').' - '.$this->l('label_my_account'),
            'body_css' => 'controller-authorized_applications action-index',
        ] + $this->sidebar->vars($user));
    }

    #[Route('/oauth/authorized_applications/{id}', name: 'oauth_authorized_application', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function revokeApplication(int $id): Response
    {
        $user = $this->resourceOwner->authenticate();
        $this->server->revokeTokensAndGrantsFor($id, $user);
        $this->addFlash('notice', $this->i18n->l('doorkeeper.flash.authorized_applications.destroy.notice'));

        return $this->redirectToRoute('oauth_authorized_applications');
    }
}

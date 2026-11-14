<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The outcome of an authorization request (Doorkeeper's AuthorizationsController): the resource owner grants or
 * denies the access, and the client application gets the code or the error the way it asked for it.
 */
final class AuthorizationEndpoint
{
    public function __construct(
        private readonly OAuthServer $server,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** CodeRequest#authorize: issues the grant when the request is valid, answers with its error otherwise */
    public function authorize(PreAuthorization $pre, User $owner): AuthorizationResult
    {
        return $this->answer($pre, $pre->isAuthorizable() ? $this->server->issueGrant($pre, $owner) : null);
    }

    /** CodeRequest#deny */
    public function deny(PreAuthorization $pre): AuthorizationResult
    {
        if ($pre->isAuthorizable()) {
            $pre->error = 'access_denied';
        }

        return $this->answer($pre, null);
    }

    /** @return array{error_description: string} what the error page shows when the request cannot be authorized */
    public function errorView(PreAuthorization $pre): array
    {
        return ['error_description' => $this->server->errorBody((string) $pre->error, $pre->state, $pre->missingParam)['error_description']];
    }

    /** AuthorizationsController#redirect_or_render ($code: issued code, null for an error response) */
    private function answer(PreAuthorization $pre, ?string $code): AuthorizationResult
    {
        if (null !== $code) {
            $body = ['code' => $code, 'state' => $pre->state];
            if (OAuthServer::isOob($pre->redirectUri)) {
                return new AuthorizationResult(new RedirectResponse($this->urls->generate('native_oauth_authorization', ['code' => $code], UrlGeneratorInterface::ABSOLUTE_URL)));
            }
            if ('form_post' === $pre->responseMode) {
                return new AuthorizationResult(null, ['redirect_uri' => $pre->redirectUri, 'body' => array_filter($body, static fn ($v) => null !== $v)]);
            }

            return new AuthorizationResult(new RedirectResponse(OAuthServer::uriWith((string) $pre->redirectUri, $body, $pre->responseOnFragment())));
        }
        $error = (string) $pre->error;
        $body = $this->server->errorBody($error, $pre->state, $pre->missingParam);
        if (OAuthServer::isRedirectable($error, $pre->redirectUri, $pre->missingParam) && null !== $pre->redirectUri && '' !== $pre->redirectUri) {
            if ('form_post' === $pre->responseMode) {
                return new AuthorizationResult(null, ['redirect_uri' => $pre->redirectUri, 'body' => $body]);
            }

            return new AuthorizationResult(new RedirectResponse(OAuthServer::uriWith($pre->redirectUri, $body, $pre->responseOnFragment())));
        }

        return new AuthorizationResult(new JsonResponse($body, OAuthServer::errorStatus($error)));
    }
}

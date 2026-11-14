<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\OauthApplication;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/** Doorkeeper's TokenInfoController: what the bearer of an access token learns about it (GET /oauth/token/info). */
final class TokenInfo
{
    public function __construct(
        private readonly OAuthServer $server,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array{body: array<string, mixed>, status: int, headers: array<string, string>} */
    public function show(Request $request): array
    {
        $plain = OAuthServer::bearerToken($request);
        $token = null === $plain ? null : $this->server->authenticate($plain);
        if (null !== $token && $token->isAccessible()) {
            $app = null === $token->applicationId ? null : $this->em->find(OauthApplication::class, $token->applicationId);

            return [
                'body' => [
                    'resource_owner_id' => $token->resourceOwnerId,
                    'scope' => $token->getScopes(),
                    'expires_in' => $token->expiresInSeconds(),
                    'application' => ['uid' => $app?->uid],
                    'created_at' => $token->createdAt->getTimestamp(),
                ],
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            ];
        }
        // OAuth::InvalidTokenResponse.new (reason :unknown, state :unauthorized)
        $description = $this->server->errorDescription('invalid_token', null, 'unknown');

        return [
            'body' => ['error' => 'invalid_token', 'error_description' => $description, 'state' => 'unauthorized'],
            'status' => 401,
            'headers' => $this->server->errorHeaders('invalid_token', $description),
        ];
    }
}

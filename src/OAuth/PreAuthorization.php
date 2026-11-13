<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\OauthApplication;

/** Doorkeeper::OAuth::PreAuthorization: parameters of an authorization request and validation result. */
final class PreAuthorization
{
    public ?string $clientId = null;
    public ?string $responseType = null;
    public ?string $responseMode = null;
    public ?string $redirectUri = null;
    public ?string $scope = null;
    public ?string $state = null;
    public ?string $codeChallenge = null;
    public ?string $codeChallengeMethod = null;

    public ?OauthApplication $client = null;
    /** Error name (invalid_request, invalid_client, ...) or null when authorizable */
    public ?string $error = null;
    public ?string $missingParam = null;

    public function isAuthorizable(): bool
    {
        return null === $this->error;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return OAuthServer::scopesFromString($this->scope);
    }

    public function responseOnFragment(): bool
    {
        return 'fragment' === $this->responseMode;
    }
}

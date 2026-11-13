<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** An expired or revoked OAuth2 access token (Doorkeeper InvalidTokenResponse reason). */
final class OAuthTokenException extends AuthenticationException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The access token is '.$reason);
    }

    public function getMessageKey(): string
    {
        return 'Invalid access token.';
    }
}

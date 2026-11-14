<?php

declare(strict_types=1);

namespace App\OAuth;

use Symfony\Component\HttpFoundation\Response;

/**
 * The answer of the authorization endpoint to the client application: a redirect to its redirect URI or a JSON
 * error, or - with response_mode=form_post - the fields of the form that posts the answer to the redirect URI.
 */
final readonly class AuthorizationResult
{
    /** @param array{redirect_uri?: string, body?: array<string, mixed>} $formPost variables of oauth/authorizations/form_post.html.twig, when there is no response */
    public function __construct(
        public ?Response $response = null,
        public array $formPost = [],
    ) {
    }
}

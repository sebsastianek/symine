<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/** Redirects to /login?back_url=... (HTML) or answers 401 (API), like Redmine's require_login. */
final class LoginEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (ApiRequestMatcher::isApiRequest($request) || $request->isXmlHttpRequest()) {
            $r = new JsonResponse(null, 401);
            $r->headers->set('WWW-Authenticate', 'Basic realm="Redmine API"');

            return $r;
        }

        return new RedirectResponse($this->urls->generate('signin', ['back_url' => $request->getUri()]));
    }
}

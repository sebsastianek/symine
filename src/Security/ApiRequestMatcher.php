<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/**
 * Matches REST API / feed requests carrying credentials (API key, HTTP basic, OAuth2 Bearer token), which are
 * authenticated statelessly - same rules as Redmine's ApplicationController#find_current_user.
 */
final class ApiRequestMatcher implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        // repository management web service (SysController): authenticated by the sys_api_key
        if (str_starts_with($request->getPathInfo(), '/sys/')) {
            return false;
        }
        $format = self::format($request);
        if (null === $format) {
            return false;
        }
        if ('atom' === $format || 'ics' === $format) {
            return $request->query->has('key');
        }
        // ApplicationController#find_current_user: API requests (json/xml) never use the session,
        // they are anonymous unless they carry API credentials
        if ('json' === $format || 'xml' === $format) {
            return true;
        }

        return $request->query->has('key')
            || $request->headers->has('X-Redmine-API-Key')
            || str_starts_with((string) $request->headers->get('Authorization'), 'Basic ')
            || null !== \App\OAuth\OAuthServer::bearerToken($request);
    }

    public static function format(Request $request): ?string
    {
        if (preg_match('/\.(json|xml|atom|ics)$/', $request->getPathInfo(), $m)) {
            return $m[1];
        }
        $f = $request->query->get('format');

        return \in_array($f, ['json', 'xml', 'atom'], true) ? $f : null;
    }

    public static function isApiRequest(Request $request): bool
    {
        return \in_array(self::format($request), ['json', 'xml'], true);
    }
}

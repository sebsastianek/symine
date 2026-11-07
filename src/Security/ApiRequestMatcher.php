<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/**
 * Matches REST API / feed requests carrying credentials (API key, HTTP basic), which are
 * authenticated statelessly - same rules as Redmine's ApplicationController#find_current_user.
 */
final class ApiRequestMatcher implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        $format = self::format($request);
        if (null === $format) {
            return false;
        }
        if ('atom' === $format || 'ics' === $format) {
            return $request->query->has('key');
        }

        return $request->query->has('key')
            || $request->headers->has('X-Redmine-API-Key')
            || str_starts_with((string) $request->headers->get('Authorization'), 'Basic ');
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

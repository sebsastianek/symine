<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Api\ApiRenderer;
use App\Security\ApiRequestMatcher;
use App\Service\MembersManager;
use Symfony\Component\HttpFoundation\Request;

/** Reads what the members actions need from a request: the expected answer, the list being displayed, the membership submitted. */
final class MembersRequest
{
    /** The action is answered with JavaScript (*.js.erb): ".js" route or an Ajax request accepting JavaScript */
    public static function wantsJs(Request $request): bool
    {
        return str_ends_with($request->getPathInfo(), '.js')
            || ($request->isXmlHttpRequest() && str_contains((string) $request->headers->get('Accept'), 'javascript'));
    }

    /**
     * Request parameters (query string and form) describing the members list: filters, page and per_page
     *
     * @return array<string, string>
     */
    public static function listParams(Request $request): array
    {
        $params = array_replace($request->request->all(), $request->query->all());
        $out = MembersManager::listParams($params);
        if (\is_scalar($params['per_page'] ?? null) && '' !== (string) $params['per_page']) {
            $out['per_page'] = (string) $params['per_page'];
        }

        return $out;
    }

    /**
     * params[:membership]: from the body of an API request, from the form otherwise
     *
     * @return array<string, mixed>
     */
    public static function membership(Request $request): array
    {
        return ApiRequestMatcher::isApiRequest($request)
            ? (array) (ApiRenderer::payload($request)['membership'] ?? [])
            : (array) ($request->request->all()['membership'] ?? []);
    }
}

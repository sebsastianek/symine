<?php

declare(strict_types=1);

namespace App\Service\Admin\Workflows;

use Symfony\Component\HttpFoundation\Request;

/** Where a workflow update sends the browser back to: the page it was made from (redirect_to_referer_or). */
final class RefererUrl
{
    /** The referer when it names no other host than this site, $default otherwise */
    public function orDefault(Request $request, string $default): string
    {
        $referer = (string) $request->headers->get('referer');
        if ('' !== $referer) {
            $host = parse_url($referer, \PHP_URL_HOST);
            if (null === $host || $host === $request->getHost()) {
                return $referer;
            }
        }

        return $default;
    }
}

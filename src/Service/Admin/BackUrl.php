<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Symfony\Component\HttpFoundation\Request;

/**
 * Where an administration action sends the browser back to once it is done: the page it came from, as long
 * as that page belongs to this site (Redmine's redirect_to_referer_or).
 */
final class BackUrl
{
    /**
     * The referer when it is a page of this site, null otherwise.
     *
     * @param string|null $except part of a URL the referer must not contain (the page of what was just deleted)
     */
    public function localReferer(Request $request, ?string $except = null): ?string
    {
        $referer = (string) $request->headers->get('referer');
        if ('' === $referer || parse_url($referer, \PHP_URL_HOST) !== $request->getHost()) {
            return null;
        }
        if (null !== $except && str_contains($referer, $except)) {
            return null;
        }

        return $referer;
    }
}

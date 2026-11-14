<?php

declare(strict_types=1);

namespace App\Service\Timelog;

use Symfony\Component\HttpFoundation\Request;

/** Where the time log sends the user back to when the request gives no back_url: the page it came from. */
final class TimelogBackUrl
{
    /** The back_url parameter of the request ('' when there is none) */
    public static function param(Request $request): string
    {
        return (string) ($request->request->get('back_url') ?? $request->query->get('back_url') ?? '');
    }

    /**
     * After a deletion without back_url: the referring page, unless it is a page of a deleted entry
     * (null: the caller falls back to back_url or to its default).
     */
    public static function afterDestroy(Request $request): ?string
    {
        if ('' === self::param($request) && ($ref = $request->headers->get('referer'))) {
            $path = parse_url($ref, \PHP_URL_PATH);
            $q = parse_url($ref, \PHP_URL_QUERY);
            if (\is_string($path) && !preg_match('#/time_entries/\d+#', $path)) {
                return $path.($q ? '?'.$q : '');
            }
        }

        return null;
    }

    /** The back_url of the context menu links: the local back_url given, else the path of the referring page */
    public static function ofContextMenu(Request $request): string
    {
        $back = self::param($request);
        if ('' === $back || !str_starts_with($back, '/')) {
            $ref = $request->headers->get('referer');
            $back = $ref ? (string) (parse_url($ref, \PHP_URL_PATH).((string) parse_url($ref, \PHP_URL_QUERY) ? '?'.parse_url($ref, \PHP_URL_QUERY) : '')) : '';
        }

        return $back;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Project;

use Symfony\Component\HttpFoundation\Request;

/**
 * The format Rails would answer a request with when its URL has no format suffix: the one negotiated from the
 * Accept header (ActionDispatch::Http::MimeNegotiation), for the actions that only exist in one format.
 */
final class RequestFormat
{
    /** Format negotiated by Rails from the Accept header (ActionDispatch::Http::MimeNegotiation#formats) */
    public static function negotiated(Request $request): string
    {
        $accept = trim((string) $request->headers->get('Accept', ''));
        $browserLike = (bool) preg_match('#,\s*\*/\*|\*/\*\s*,#', $accept);
        if ('' === $accept || ($browserLike && !$request->isXmlHttpRequest())) {
            return 'html';
        }
        $types = [];
        foreach (explode(',', $accept) as $i => $part) {
            $segments = array_map('trim', explode(';', $part));
            $q = 1.0;
            foreach (\array_slice($segments, 1) as $param) {
                if (str_starts_with($param, 'q=')) {
                    $q = (float) substr($param, 2);
                }
            }
            $types[] = [strtolower($segments[0]), $q, $i];
        }
        usort($types, static fn ($a, $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);
        $first = $types[0][0] ?? '*/*';

        return match ($first) {
            'text/html', 'application/xhtml+xml' => 'html',
            'text/javascript', 'application/javascript', 'application/x-javascript' => 'js',
            '*/*' => 'all',
            default => 'other',
        };
    }

    /** respond_to { format.js } accepts the request */
    public static function acceptsJs(Request $request): bool
    {
        $accept = trim((string) $request->headers->get('Accept', ''));
        $browserLike = (bool) preg_match('#,\s*\*/\*|\*/\*\s*,#', $accept);
        if ('' === $accept || ($browserLike && !$request->isXmlHttpRequest())) {
            return false; // html
        }
        foreach (explode(',', $accept) as $part) {
            $type = strtolower(trim(explode(';', $part)[0]));
            if (\in_array($type, ['text/javascript', 'application/javascript', 'application/x-javascript', '*/*'], true)) {
                return true;
            }
        }

        return false;
    }
}

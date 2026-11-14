<?php

declare(strict_types=1);

namespace App\Service\Scm\Http;

use App\Service\Scm\RepositoryService;
use App\Service\Scm\ScmNotFound;
use App\Service\Scm\ScmTwigExtension;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * The files the repository browser sends: the raw content of a file (repositories#raw) and the differences
 * between two revisions as a patch (repositories#diff, format=diff).
 */
final class RepositoryDownloads
{
    public function __construct(private readonly RepositoryService $repos)
    {
    }

    /** @throws ScmNotFound when the content cannot be read */
    public function file(RepositoryLocation $at): Response
    {
        $content = $this->repos->cat($at->repository, $at->path, $at->rev);
        if (null === $content) {
            throw new ScmNotFound(page: true);
        }
        $name = basename($at->path);
        $type = ScmTwigExtension::mimeType($at->path);
        if ('application/javascript' === $type) {
            $type = 'text/plain';
        }
        $response = new Response($content, 200, ['Content-Type' => $type ?? 'application/octet-stream']);
        $disposition = 'application/pdf' === $type ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition($disposition, $name, preg_replace('/[^\x20-\x7e]/', '_', $name) ?: 'file'));
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /** @throws ScmNotFound when there is nothing to compare */
    public function patch(RepositoryLocation $at): Response
    {
        $diff = null !== $at->rev ? $this->repos->diff($at->repository, $at->path, $at->rev, $at->revTo) : null;
        if (null === $diff) {
            throw new ScmNotFound(page: true);
        }
        $filename = 'changeset_r'.$at->rev.(null !== $at->revTo ? '_r'.$at->revTo : '');
        $response = new Response(implode('', $diff), 200, ['Content-Type' => 'text/x-patch']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename.'.diff', (string) preg_replace('/[^\x20-\x7e]/', '_', $filename.'.diff')));

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Tracking\Previews\PreviewSubjectFinder;
use App\Service\Tracking\Previews\TextPreview;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's PreviewsController (wiki toolbar "Preview" tab). */
final class PreviewsController extends AbstractController
{
    public function __construct(
        private readonly PreviewSubjectFinder $finder,
        private readonly TextPreview $preview,
    ) {
    }

    #[Route('/issues/preview', name: 'preview_issue', methods: ['GET', 'POST', 'PUT', 'PATCH'])]
    public function issue(Request $request): Response
    {
        $project = $this->finder->project($request);
        $issue = $this->finder->issue($request);

        return new Response($this->preview->html($request, $project ?? $issue?->project, $issue));
    }

    #[Route('/news/preview', name: 'preview_news', methods: ['GET', 'POST', 'PUT', 'PATCH'])]
    public function news(Request $request): Response
    {
        $project = $this->finder->project($request);
        $news = $this->finder->news($request);

        return new Response($this->preview->html($request, $project ?? $news?->project, $news));
    }

    #[Route('/preview/text', name: 'preview_text', methods: ['GET', 'POST', 'PUT', 'PATCH'])]
    public function text(Request $request): Response
    {
        return new Response($this->preview->html($request, null));
    }
}

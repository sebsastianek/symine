<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Tracking\AutoComplete\IssueAutoComplete;
use App\Service\Tracking\AutoComplete\WikiPageAutoComplete;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's AutoCompletesController#issues (issue id / subject autocompletion). */
final class AutoCompletesController extends AbstractController
{
    public function __construct(
        private readonly IssueAutoComplete $issues,
        private readonly WikiPageAutoComplete $wikiPages,
    ) {
    }

    #[Route('/issues/auto_complete', name: 'issues_auto_complete', methods: ['GET'])]
    public function issues(Request $request): JsonResponse
    {
        return new JsonResponse($this->issues->suggest($request));
    }

    /** AutoCompletesController#wiki_pages ([[ autocompletion in wiki textareas). */
    #[Route('/wiki_pages/auto_complete', name: 'wiki_pages_auto_complete', methods: ['GET'])]
    public function wikiPages(Request $request): JsonResponse
    {
        return new JsonResponse($this->wikiPages->suggest($request));
    }
}

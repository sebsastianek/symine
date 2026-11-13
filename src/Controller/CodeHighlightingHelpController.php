<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SyntaxHighlighting;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of HelpController#show_code_highlighting (layout-less list of the supported languages). */
final class CodeHighlightingHelpController extends AbstractController
{
    #[Route('/help/code_highlighting', name: 'help_code_highlighting', methods: ['GET'])]
    public function show(SyntaxHighlighting $highlighter): Response
    {
        return new Response($this->container->get('twig')->render('help/code_highlighting_languages.html.twig', [
            'available_lexers' => $highlighter->lexers(),
            'asset_base' => $this->requestStack->getCurrentRequest()?->getBasePath() ?? '',
        ]), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

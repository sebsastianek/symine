<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Content\Help\WikiSyntaxHelp;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of HelpController#show_wiki_syntax (templates/help/wiki_syntax/<format>/<lang>/*, layout-less). */
final class WikiSyntaxHelpController extends AbstractController
{
    #[Route('/help/wiki_syntax/{type}', name: 'help_wiki_syntax', requirements: ['type' => 'detailed'], defaults: ['type' => null], methods: ['GET'])]
    public function show(WikiSyntaxHelp $help, ?string $type = null): Response
    {
        return new Response($this->renderView($help->template($type), $help->view()), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

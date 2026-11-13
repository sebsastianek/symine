<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of HelpController#show_wiki_syntax (templates/help/wiki_syntax/<format>/<lang>/*, layout-less). */
final class WikiSyntaxHelpController extends AbstractController
{
    #[Route('/help/wiki_syntax/{type}', name: 'help_wiki_syntax', requirements: ['type' => 'detailed'], defaults: ['type' => null], methods: ['GET'])]
    public function show(?string $type = null): Response
    {
        $format = (string) $this->settings->get('text_formatting');
        if (!\in_array($format, ['textile', 'common_mark'], true)) {
            $format = 'common_mark';
        }
        $prefix = null === $type ? '' : $type.'_';
        $twig = $this->container->get('twig');
        $lang = strtolower(str_replace('_', '-', $this->i18n->locale()));
        $template = "help/wiki_syntax/$format/$lang/wiki_syntax_$prefix$format.html.twig";
        if (!$twig->getLoader()->exists($template)) {
            $template = "help/wiki_syntax/$format/en/wiki_syntax_$prefix$format.html.twig";
        }
        $router = $this->container->get('router');
        $codeHighlighting = null !== $router->getRouteCollection()->get('help_code_highlighting')
            ? $this->generateUrl('help_code_highlighting')
            : $this->requestStack->getCurrentRequest()?->getBasePath().'/help/code_highlighting';

        return new Response($twig->render($template, [
            'asset_base' => $this->requestStack->getCurrentRequest()?->getBasePath() ?? '',
            'code_highlighting_url' => $codeHighlighting,
            'mermaid_version' => null,
        ]), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}

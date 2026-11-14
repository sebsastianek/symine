<?php

declare(strict_types=1);

namespace App\Service\Content\Help;

use App\I18n\Localizer;
use App\Service\Settings;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

/**
 * Chooses the wiki syntax help page (HelpController#show_wiki_syntax): the one of the text formatting of the
 * site, in the language of the user when it was translated, in English otherwise.
 */
final class WikiSyntaxHelp
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly Environment $twig,
        private readonly RouterInterface $router,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @param string|null $type "detailed" for the full reference, null for the short one */
    public function template(?string $type): string
    {
        $format = (string) $this->settings->get('text_formatting');
        if (!\in_array($format, ['textile', 'common_mark'], true)) {
            $format = 'common_mark';
        }
        $prefix = null === $type ? '' : $type.'_';
        $lang = strtolower(str_replace('_', '-', $this->i18n->locale()));
        $template = "help/wiki_syntax/$format/$lang/wiki_syntax_$prefix$format.html.twig";
        if (!$this->twig->getLoader()->exists($template)) {
            $template = "help/wiki_syntax/$format/en/wiki_syntax_$prefix$format.html.twig";
        }

        return $template;
    }

    /** @return array<string, mixed> */
    public function view(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $codeHighlighting = null !== $this->router->getRouteCollection()->get('help_code_highlighting')
            ? $this->router->generate('help_code_highlighting')
            : $request?->getBasePath().'/help/code_highlighting';

        return [
            'asset_base' => $request?->getBasePath() ?? '',
            'code_highlighting_url' => $codeHighlighting,
            'mermaid_version' => null,
        ];
    }
}

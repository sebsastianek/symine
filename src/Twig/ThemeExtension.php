<?php

declare(strict_types=1);

namespace App\Twig;

use App\I18n\Localizer;
use App\Theme\Theme;
use App\Theme\ThemeContext;
use App\Theme\ThemeOption;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Theme helpers of the templates (Redmine::Themes::Helper, plus the Symine theme options) */
final class ThemeExtension extends AbstractExtension
{
    public function __construct(private readonly ThemeContext $context, private readonly Localizer $i18n)
    {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            // the active theme (Redmine's current_theme)
            new TwigFunction('current_theme', $this->context->theme(...)),
            new TwigFunction('theme_context', fn (): ThemeContext => $this->context),
            new TwigFunction('theme_html_attributes', $this->context->htmlAttributes(...), $safe),
            new TwigFunction('theme_body_classes', $this->context->bodyClasses(...)),
            new TwigFunction('theme_stylesheets', $this->context->stylesheets(...)),
            new TwigFunction('theme_javascripts', $this->context->javascripts(...)),
            new TwigFunction('theme_favicon', $this->context->favicon(...)),
            // stylesheet_path('scm'): the stylesheet of the theme when it provides one of that name
            new TwigFunction('stylesheet_path', fn (string $name): string => $this->context->coreStylesheet($name) ?? '/assets/'.$name.'.css'),
            // theme_asset('images/logo.svg'): URL of a file of the theme or of its parents
            new TwigFunction('theme_asset', $this->context->assetUrl(...)),
            // theme_option('density'): value in effect of an option of the theme
            new TwigFunction('theme_option', $this->context->option(...)),
            new TwigFunction('theme_option_image', $this->optionImage(...)),
            new TwigFunction('theme_text', $this->text(...)),
        ];
    }

    /** URL of the picture of an image option, null when none is set */
    public function optionImage(string $name): ?string
    {
        $theme = $this->context->theme();
        $option = $theme->option($name);
        if (null === $option || ThemeOption::TYPE_IMAGE !== $option->type) {
            return null;
        }

        return $this->context->imageUrl($theme, $option, (string) $this->context->option($name));
    }

    /**
     * A label of a theme manifest in the language of the user.
     *
     * @param array<string, string> $labels
     */
    public function text(array $labels, string $fallback = ''): string
    {
        return ThemeOption::text($labels, $this->i18n->locale(), $fallback);
    }
}

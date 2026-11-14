<?php

declare(strict_types=1);

namespace App\Theme;

use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The appearance in effect for the current request: which theme, with which option values.
 *
 * Theme, by decreasing priority: the one forced by the controller (theme customisation page), the one an
 * administrator is previewing (session), the one the user picked (when allowed), the site theme.
 * Option values: the defaults of the theme, overridden by the site values, overridden by the personal values
 * of the user (preferences) or of the visitor (cookie) for the options users may set.
 */
final class ThemeContext implements ResetInterface
{
    public const string SESSION_PREVIEW = 'theme_preview';
    public const string COOKIE = 'symine_appearance';

    /** Core stylesheets of the base layout, in order */
    private const array CORE_STYLESHEETS = ['jquery/jquery-ui-1.13.2', 'tribute-5.1.3', 'application', 'dropdown', 'jstoolbar', 'responsive'];

    /** Core stylesheets a theme takes over with "replaces_core" */
    private const array REPLACEABLE = ['application', 'responsive'];

    private ?Theme $forced = null;
    /** @var array{request: ?Request, user: ?int, theme: Theme, options: array<string, string>, personal: array<string, string>}|null */
    private ?array $state = null;
    /** @var array<string, array{url: ?string, icons: array<string, int>}> sprites of the themes, by theme and sprite name */
    private array $sprites = [];

    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly ThemeSettings $settings,
        private readonly CurrentUser $currentUser,
        private readonly RequestStack $requests,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function reset(): void
    {
        $this->forced = null;
        $this->state = null;
    }

    /** Forgets what was resolved (after the settings or the preferences changed during the request) */
    public function refresh(): void
    {
        $this->state = null;
    }

    /** Renders the rest of the request with the given theme, whatever the site and the user chose */
    public function force(Theme $theme): void
    {
        $this->forced = $theme;
        $this->state = null;
    }

    public function theme(): Theme
    {
        return $this->state()['theme'];
    }

    /** The theme of the site: used for what is not shown to the current user only (e-mails) */
    public function siteTheme(): Theme
    {
        return $this->settings->siteTheme();
    }

    /** The theme an administrator is trying out, if any */
    public function previewed(): ?Theme
    {
        $request = $this->requests->getMainRequest();
        if (null === $request || !$request->hasPreviousSession() || !$this->currentUser->get()->admin) {
            return null;
        }
        $id = $request->getSession()->get(self::SESSION_PREVIEW);

        return \is_string($id) ? $this->registry->get($id) : null;
    }

    /** @return array<string, string> value in effect of every option of the theme */
    public function options(): array
    {
        return $this->state()['options'];
    }

    public function option(string $name): ?string
    {
        return $this->state()['options'][$name] ?? null;
    }

    /** @return array<string, string> the values the current user set for themself */
    public function personalOptions(): array
    {
        return $this->state()['personal'];
    }

    /** Whether the current visitor can change something about the appearance */
    public function customisable(): bool
    {
        $user = $this->currentUser->get();

        return ($this->settings->usersMayCustomise() && [] !== $this->theme()->userOptions())
            || ($user->isLogged() && $this->settings->usersMayChooseTheme() && \count($this->registry->all()) > 1);
    }

    /**
     * The themes the current user may switch to; empty when there is no choice (not logged in, not allowed,
     * a single theme installed).
     *
     * @return array<string, Theme>
     */
    public function themeChoices(): array
    {
        if (!$this->currentUser->get()->isLogged() || !$this->settings->usersMayChooseTheme()) {
            return [];
        }
        $themes = $this->registry->all();

        return \count($themes) > 1 ? $themes : [];
    }

    /** The theme the current user picked for themself, null when they follow the site */
    public function chosenTheme(): ?Theme
    {
        return $this->settings->userTheme($this->currentUser->get());
    }

    /** Options offered in the quick switcher of the top menu */
    public function quickOptions(): array
    {
        if (!$this->settings->usersMayCustomise()) {
            return [];
        }

        return array_filter($this->theme()->userOptions(), static fn (ThemeOption $o) => $o->quick);
    }

    // --- markup ---------------------------------------------------------------

    /** Attributes of <html>, with a leading space; empty for the default theme so its markup stays Redmine's */
    public function htmlAttributes(): string
    {
        $theme = $this->theme();
        if ($theme->isDefault() || $theme->legacy) {
            return '';
        }
        $attributes = ['data-theme' => $theme->id];
        $style = [];
        $personal = $this->personalOptions();
        foreach ($theme->allOptions() as $name => $option) {
            $value = $this->options()[$name];
            $attribute = $option->attribute();
            if (null !== $attribute) {
                $attributes[$attribute] = (string) $option->attributeValue($value);
            }
            // personal values that are not a declared choice cannot be part of the stylesheet of the theme
            if (isset($personal[$name]) && null !== $option->token && !$option->isPreset($value)) {
                $css = $this->cssValue($theme, $option, $value);
                if (null !== $css) {
                    $style[] = '--'.$option->token.':'.$css;
                }
            }
        }
        if ([] !== $style) {
            $attributes['style'] = implode(';', $style);
        }
        $html = '';
        foreach ($attributes as $name => $value) {
            $html .= ' '.$name.'="'.htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE).'"';
        }

        return $html;
    }

    /** CSS classes of <body> (ApplicationHelper#body_css_classes) */
    public function bodyClasses(): string
    {
        $theme = $this->theme();

        return implode(' ', array_filter([$theme->bodyClass(), ...$theme->bodyClasses]));
    }

    /**
     * URL of a core stylesheet of the layout: the one of the theme when a Redmine theme provides a stylesheet
     * of that name (ApplicationHelper#stylesheet_link_tag), null when the theme replaces it altogether.
     */
    public function coreStylesheet(string $name): ?string
    {
        $theme = $this->theme();
        if ($theme->legacy && !$theme->isDefault() && is_file($theme->path.'/stylesheets/'.$name.'.css')) {
            return $this->assetUrl('stylesheets/'.$name.'.css');
        }
        if ($theme->replacesCore && \in_array($name, self::REPLACEABLE, true)) {
            return null;
        }

        return '/assets/'.$name.'.css';
    }

    /** @return list<string> URLs of the stylesheets of the base layout, core ones included */
    public function stylesheets(): array
    {
        $theme = $this->theme();
        $urls = [];
        foreach (self::CORE_STYLESHEETS as $name) {
            $url = $this->coreStylesheet($name);
            if (null !== $url) {
                $urls[] = $url;
            }
        }
        if ($theme->isDefault() || $theme->legacy) {
            return $urls;
        }
        // controls of the appearance options (quick switcher), before the theme so that it can restyle them
        $urls[] = '/assets/appearance.css';
        foreach ($theme->stylesheets as $sheet) {
            $urls[] = $this->fileUrl($sheet['theme'], $sheet['file'], $theme->directories[$sheet['theme']].'/'.$sheet['file']);
        }
        $urls[] = $this->urls->generate('theme_generated_css', ['id' => $theme->id, 'v' => substr($this->settings->siteVersion($theme), 0, 12)]);

        return $urls;
    }

    /** @return list<string> URLs of the scripts of the theme (Redmine::Themes::Helper#heads_for_theme) */
    public function javascripts(): array
    {
        $theme = $this->theme();
        $urls = [];
        foreach ($theme->javascripts as $script) {
            $urls[] = $this->fileUrl($script['theme'], $script['file'], $theme->directories[$script['theme']].'/'.$script['file']);
        }

        return $urls;
    }

    /** URL of the favicon of the theme (first file of its favicon/ directory), if it has one */
    public function favicon(): ?string
    {
        $theme = $this->theme();
        foreach ($theme->lineage as $id) {
            $files = glob(($theme->directories[$id] ?? '').'/favicon/*') ?: [];
            sort($files);
            foreach ($files as $file) {
                if (is_file($file)) {
                    return $this->fileUrl($id, 'favicon/'.basename($file), $file);
                }
            }
        }

        return null;
    }

    /** URL of a file of the theme (or of its parents), null when there is no such file */
    public function assetUrl(string $relative): ?string
    {
        $found = $this->theme()->locate($relative);

        return null === $found ? null : $this->fileUrl($found['theme'], $relative, $found['path']);
    }

    /**
     * URL of the SVG sprite to take an icon from: the images/<sprite>.svg of the theme when it defines the icon
     * (IconsHelper#sprite_source), the core sprite otherwise.
     */
    public function spriteUrl(string $icon, string $sprite = 'icons'): string
    {
        $theme = $this->state()['theme'];
        if ($theme->isDefault()) {
            return '/assets/'.$sprite.'.svg';
        }
        $key = $theme->id.'/'.$sprite;
        if (!isset($this->sprites[$key])) {
            $this->sprites[$key] = ['url' => null, 'icons' => []];
            $found = $theme->locate('images/'.$sprite.'.svg');
            if (null !== $found) {
                preg_match_all('/id=[\'"]icon--([^\'"]+)[\'"]/', (string) file_get_contents($found['path']), $m);
                $this->sprites[$key] = ['url' => $this->fileUrl($found['theme'], 'images/'.$sprite.'.svg', $found['path']), 'icons' => array_flip($m[1])];
            }
        }

        return isset($this->sprites[$key]['icons'][$icon]) ? $this->sprites[$key]['url'] : '/assets/'.$sprite.'.svg';
    }

    /** CSS value of a value-type option, as written to stylesheets and to the style attribute */
    public function cssValue(Theme $theme, ThemeOption $option, string $value): ?string
    {
        return match ($option->type) {
            ThemeOption::TYPE_COLOR => $option->isPreset($value) ? $option->presets[$value]['value'] : CssValue::color($value),
            ThemeOption::TYPE_LENGTH => CssValue::length($value, $option->units),
            ThemeOption::TYPE_NUMBER => is_numeric($value) ? $value : null,
            ThemeOption::TYPE_TEXT => '' === $value ? null : CssValue::string($value),
            ThemeOption::TYPE_IMAGE => $this->imageCssValue($theme, $option, $value),
            default => null,
        };
    }

    /** URL of the picture of an image option: an uploaded file, or the default one shipped with the theme */
    public function imageUrl(Theme $theme, ThemeOption $option, string $value): ?string
    {
        if ('' === $value) {
            return null;
        }
        if ($value === $option->default && str_contains($value, '/')) {
            $found = $theme->locate($value);

            return null === $found ? null : $this->fileUrl($found['theme'], $value, $found['path']);
        }

        return is_file($this->settings->uploadDirectory($theme->id).'/'.$value)
            ? $this->urls->generate('theme_upload', ['id' => $theme->id, 'file' => $value])
            : null;
    }

    private function imageCssValue(Theme $theme, ThemeOption $option, string $value): ?string
    {
        $url = $this->imageUrl($theme, $option, $value);

        return null === $url ? null : CssValue::url($url);
    }

    private function fileUrl(string $themeId, string $relative, string $path): string
    {
        return $this->urls->generate('theme_asset', ['id' => $themeId, 'path' => $relative, 'v' => base_convert((string) (@filemtime($path) ?: 0), 10, 36)]);
    }

    // --- resolution -----------------------------------------------------------

    /** @return array<string, mixed>|null personal values of a visitor who is not logged in, for the given theme */
    public function cookieOptions(Theme $theme): ?array
    {
        $raw = $this->requests->getMainRequest()?->cookies->get(self::COOKIE);
        if (!\is_string($raw) || '' === $raw || \strlen($raw) > 2000) {
            return null;
        }
        $data = json_decode($raw, true);

        return \is_array($data) && \is_array($data[$theme->id] ?? null) ? $data[$theme->id] : null;
    }

    /** @return array{request: ?Request, user: ?int, theme: Theme, options: array<string, string>, personal: array<string, string>} */
    private function state(): array
    {
        $request = $this->requests->getMainRequest();
        $user = $this->currentUser->get();
        if (null !== $this->state && $this->state['request'] === $request && $this->state['user'] === $user->id) {
            return $this->state;
        }

        $theme = $this->forced ?? $this->previewed() ?? $this->settings->userTheme($user) ?? $this->settings->siteTheme();
        $personal = [] === $theme->userOptions() ? [] : $this->settings->userOptions($user, $theme, $user->isLogged() ? null : $this->cookieOptions($theme));

        return $this->state = [
            'request' => $request,
            'user' => $user->id,
            'theme' => $theme,
            'options' => $personal + $this->settings->siteOptions($theme),
            'personal' => $personal,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Theme\Form;

use App\Theme\Theme;
use App\Theme\ThemeContext;
use App\Theme\ThemeOption;
use App\Theme\ThemeSettings;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * The form of Administration > Themes > Customize: the site values of the options of a theme, its pictures
 * and its custom CSS.
 *
 * Fields: options[<name>], options_custom[<name>] (colour picked when "custom" is selected), images[<name>]
 * (upload), remove_image[<name>], default_image[<name>], custom_css, reset.
 */
final class SiteAppearanceForm
{
    public function __construct(private readonly ThemeSettings $settings, private readonly ThemeContext $context)
    {
    }

    /** Whether the request asks to forget everything customised for the theme */
    public function isReset(Request $request): bool
    {
        return $request->request->has('reset');
    }

    public function reset(Theme $theme): void
    {
        $this->settings->resetSite($theme);
        $this->context->refresh();
    }

    /**
     * Saves what was submitted; what cannot be accepted is left as it was.
     *
     * @return array<string, string> option name => rejected value (or the name of the rejected file)
     */
    public function submit(Theme $theme, Request $request): array
    {
        $submitted = $request->request->all('options');
        $custom = $request->request->all('options_custom');
        $files = $request->files->all('images');
        $remove = $request->request->all('remove_image');
        $default = $request->request->all('default_image');

        $values = [];
        $rejected = [];
        foreach ($theme->allOptions() as $name => $option) {
            if (ThemeOption::TYPE_IMAGE !== $option->type) {
                if (isset($submitted[$name]) || ThemeOption::TYPE_TOGGLE === $option->type) {
                    $values[$name] = $option->fromForm($submitted[$name] ?? null, $custom[$name] ?? null);
                }
                continue;
            }
            $file = $files[$name] ?? null;
            if ($file instanceof UploadedFile) {
                $stored = $file->isValid() ? $this->settings->storeImage($theme, $option, $file->getPathname()) : null;
                if (null === $stored) {
                    $rejected[$name] = $file->getClientOriginalName();
                } else {
                    $values[$name] = $stored;
                }
            } elseif (isset($remove[$name])) {
                $values[$name] = '';
            } elseif (isset($default[$name])) {
                $values[$name] = $option->default;
            }
        }

        $css = $request->request->get('custom_css');
        $rejected += $this->settings->saveSiteOptions($theme, $values, \is_string($css) ? $css : null);
        $this->context->refresh();

        return $rejected;
    }

    /**
     * What the form shows: the saved values, or the custom CSS as it was typed when the form is shown again.
     *
     * @param array<string, string> $rejected
     *
     * @return array<string, mixed>
     */
    public function view(Theme $theme, Request $request, array $rejected = []): array
    {
        return [
            'edited_theme' => $theme,
            'values' => $this->settings->siteOptions($theme),
            'rejected' => $rejected,
            'custom_css' => $request->isMethod('POST') ? (string) $request->request->get('custom_css') : $this->settings->customCss($theme),
            'is_site_theme' => $this->settings->siteTheme()->id === $theme->id,
            'max_image_size' => ThemeSettings::MAX_IMAGE_SIZE,
        ];
    }
}

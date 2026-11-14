<?php

declare(strict_types=1);

namespace App\Theme;

use App\Entity\User;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the appearance choices are kept, without any schema change:
 *
 *  - the site theme is Redmine's "ui_theme" setting ("" = default theme);
 *  - what administrators customised is the "ui_theme_config" setting:
 *      user_theme: users may pick their own theme, user_options: users may set the personal options (both on
 *      unless administrators turn them off),
 *      themes: {<id>: {options: {<name>: <value>}, custom_css: "..."}};
 *  - what a user chose is in the "others" hash of user_preferences:
 *      theme: "<id>" (absent = site theme), theme_options: {<id>: {<name>: <value>}}.
 *
 * Stored values are always validated against the current definition of the theme when they are read,
 * so a theme update can never leave an unusable value in effect.
 */
final class ThemeSettings
{
    public const string SETTING = 'ui_theme_config';
    public const string PREF_THEME = 'theme';
    public const string PREF_OPTIONS = 'theme_options';

    /** Size limit of the pictures uploaded for image options */
    public const int MAX_IMAGE_SIZE = 1048576;

    public function __construct(
        private readonly Settings $settings,
        private readonly ThemeRegistry $registry,
        private readonly EntityManagerInterface $em,
        #[Autowire('%symine.files_path%')] private readonly string $filesPath,
    ) {
    }

    // --- site -----------------------------------------------------------------

    public function siteTheme(): Theme
    {
        return $this->registry->get($this->settings->string('ui_theme')) ?? $this->registry->default();
    }

    public function setSiteTheme(Theme $theme): void
    {
        $this->settings->setMany(['ui_theme' => $theme->settingValue()]);
    }

    /** Whether users may pick a theme of their own (they may, unless administrators turned it off) */
    public function usersMayChooseTheme(): bool
    {
        return (bool) ($this->config()['user_theme'] ?? true);
    }

    /** Whether users may set the personal options (colour scheme, density, ...) of the theme they use */
    public function usersMayCustomise(): bool
    {
        return (bool) ($this->config()['user_options'] ?? true);
    }

    public function setUserPermissions(bool $chooseTheme, bool $customise): void
    {
        $config = $this->config();
        $config['user_theme'] = $chooseTheme;
        $config['user_options'] = $customise;
        $this->saveConfig($config);
    }

    /** @return array<string, string> value of every option of the theme for the site: stored values over the defaults */
    public function siteOptions(Theme $theme): array
    {
        return $this->validated($theme, $this->config()['themes'][$theme->id]['options'] ?? [], $theme->allOptions()) + $theme->defaults();
    }

    /**
     * Saves the site values of the options of a theme.
     *
     * @param array<string, mixed> $values option name => submitted value; options left out keep their value
     *
     * @return array<string, string> option name => rejected value
     */
    public function saveSiteOptions(Theme $theme, array $values, ?string $customCss = null): array
    {
        $config = $this->config();
        $stored = $this->validated($theme, $config['themes'][$theme->id]['options'] ?? [], $theme->allOptions());
        $rejected = [];
        foreach ($theme->allOptions() as $name => $option) {
            if (!\array_key_exists($name, $values)) {
                continue;
            }
            $value = $option->normalize($values[$name]);
            if (null === $value) {
                $rejected[$name] = \is_scalar($values[$name]) ? (string) $values[$name] : '';
                continue;
            }
            if ($value === $option->default) {
                unset($stored[$name]);
            } else {
                $stored[$name] = $value;
            }
        }
        $config['themes'][$theme->id]['options'] = $stored;
        if (null !== $customCss) {
            $config['themes'][$theme->id]['custom_css'] = self::cleanCss($customCss);
        }
        $this->saveConfig($config);

        return $rejected;
    }

    /** Forgets everything customised for a theme, uploaded pictures included */
    public function resetSite(Theme $theme): void
    {
        $config = $this->config();
        unset($config['themes'][$theme->id]);
        $this->saveConfig($config);
        foreach (glob($this->uploadDirectory($theme->id).'/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function customCss(Theme $theme): string
    {
        return (string) ($this->config()['themes'][$theme->id]['custom_css'] ?? '');
    }

    /** Changes whenever the generated stylesheet of the theme would change */
    public function siteVersion(Theme $theme): string
    {
        return hash('xxh128', serialize([$theme, $this->config()['themes'][$theme->id] ?? []]));
    }

    // --- uploaded pictures ----------------------------------------------------

    public function uploadDirectory(string $themeId): string
    {
        return rtrim($this->filesPath, '/').'/themes/'.$themeId;
    }

    /**
     * Stores the picture of an image option; the stored name carries a hash of the content so URLs can be cached forever.
     *
     * @return string|null name to save as the value of the option, null when the file is not an accepted picture
     */
    public function storeImage(Theme $theme, ThemeOption $option, string $path): ?string
    {
        if (!is_file($path) || filesize($path) > self::MAX_IMAGE_SIZE) {
            return null;
        }
        $extension = match (@getimagesize($path)[2] ?? null) {
            \IMAGETYPE_PNG => 'png',
            \IMAGETYPE_JPEG => 'jpg',
            \IMAGETYPE_GIF => 'gif',
            \IMAGETYPE_WEBP => 'webp',
            default => null,
        };
        if (null === $extension) {
            return null;
        }
        $dir = $this->uploadDirectory($theme->id);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return null;
        }
        $prefix = str_replace('_', '-', $option->name).'-';
        foreach (glob($dir.'/'.$prefix.'*') ?: [] as $old) {
            @unlink($old);
        }
        $name = $prefix.substr((string) hash_file('xxh128', $path), 0, 12).'.'.$extension;

        return copy($path, $dir.'/'.$name) ? $name : null;
    }

    // --- users ----------------------------------------------------------------

    /** The theme a user picked, when users may pick one and it is still installed */
    public function userTheme(User $user): ?Theme
    {
        if (!$user->isLogged() || !$this->usersMayChooseTheme()) {
            return null;
        }
        $id = $user->getPref()->get(self::PREF_THEME);

        return \is_string($id) && '' !== $id ? $this->registry->get($id) : null;
    }

    public function setUserTheme(User $user, ?Theme $theme): void
    {
        $pref = $user->getPref();
        $others = \is_array($pref->others) ? $pref->others : [];
        if (null === $theme) {
            unset($others[self::PREF_THEME]);
        } else {
            $others[self::PREF_THEME] = $theme->id;
        }
        $pref->others = $others;
        $this->em->persist($pref);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed>|null $anonymous values kept in the cookie of a visitor who is not logged in
     *
     * @return array<string, string> the personal values of a user for the theme (only the options users may set)
     */
    public function userOptions(User $user, Theme $theme, ?array $anonymous = null): array
    {
        if (!$this->usersMayCustomise()) {
            return [];
        }
        $stored = $user->isLogged() ? ($user->getPref()->get(self::PREF_OPTIONS)[$theme->id] ?? []) : ($anonymous ?? []);

        return $this->validated($theme, $stored, $theme->userOptions());
    }

    /**
     * Saves personal values of a logged-in user; a null value goes back to the site value.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, string> option name => rejected value
     */
    public function saveUserOptions(User $user, Theme $theme, array $values): array
    {
        $pref = $user->getPref();
        $all = $pref->get(self::PREF_OPTIONS);
        $all = \is_array($all) ? $all : [];
        [$stored, $rejected] = $this->applyUserValues($theme, $this->validated($theme, $all[$theme->id] ?? [], $theme->userOptions()), $values);
        if ([] === $stored) {
            unset($all[$theme->id]);
        } else {
            $all[$theme->id] = $stored;
        }
        $pref->set(self::PREF_OPTIONS, $all);
        $this->em->persist($pref);
        $this->em->flush();

        return $rejected;
    }

    /**
     * The values to keep for a visitor after applying $values to $current.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $values
     *
     * @return array{array<string, string>, array<string, string>} [values, rejected]
     */
    public function applyUserValues(Theme $theme, array $current, array $values): array
    {
        $stored = $this->validated($theme, $current, $theme->userOptions());
        $rejected = [];
        foreach ($theme->userOptions() as $name => $option) {
            if (!\array_key_exists($name, $values)) {
                continue;
            }
            if (null === $values[$name]) {
                unset($stored[$name]);
                continue;
            }
            $value = $option->normalize($values[$name]);
            if (null === $value) {
                $rejected[$name] = \is_scalar($values[$name]) ? (string) $values[$name] : '';
                continue;
            }
            $stored[$name] = $value;
        }

        return [$stored, $rejected];
    }

    // --- internals ------------------------------------------------------------

    /**
     * @param array<string, ThemeOption> $options
     *
     * @return array<string, string> the stored values that are still valid
     */
    private function validated(Theme $theme, mixed $stored, array $options): array
    {
        $values = [];
        foreach (\is_array($stored) ? $stored : [] as $name => $value) {
            $option = $options[$name] ?? null;
            $value = $option?->normalize($value);
            if (null !== $value) {
                $values[(string) $name] = $value;
            }
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = $this->settings->get(self::SETTING);
        if (\is_string($config) && '' !== $config) {
            $config = json_decode($config, true);
        }

        return \is_array($config) ? $config : [];
    }

    /** @param array<string, mixed> $config */
    private function saveConfig(array $config): void
    {
        $config['themes'] = array_filter($config['themes'] ?? [], static fn ($t) => \is_array($t) && [] !== array_filter($t));
        $this->settings->set(self::SETTING, $config);
        $this->em->flush();
    }

    /** Custom CSS is served as a stylesheet of its own: only what could end it early is removed */
    private static function cleanCss(string $css): string
    {
        $css = str_replace(["\r\n", "\r"], "\n", $css);

        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', mb_substr($css, 0, 100000)));
    }
}

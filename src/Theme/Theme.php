<?php

declare(strict_types=1);

namespace App\Theme;

/**
 * An installed theme, with its parent themes already merged in.
 *
 * Two kinds of themes exist:
 *  - Redmine themes (no theme.yaml): a directory with stylesheets/application.css that replaces Redmine's
 *    application.css, plus optional javascripts/theme.js, images/ and favicon/ (Redmine::Themes::Theme);
 *  - Symine themes (theme.yaml): stylesheets layered over the core ones, colour schemes, typed options,
 *    template overrides and inheritance.
 *
 * The built-in "default" theme stands for Redmine's stock look (Setting.ui_theme = "").
 */
final readonly class Theme
{
    public const string DEFAULT_ID = 'default';

    /** Name of the manifest that makes a directory a Symine theme */
    public const string MANIFEST = 'theme.yaml';

    /** Value of the colour scheme option that follows the operating system */
    public const string SCHEME_AUTO = 'auto';

    /** Name of the built-in option holding the colour scheme */
    public const string OPTION_COLOR_SCHEME = 'color_scheme';

    /**
     * @param list<string>                                    $lineage      ids from this theme up to its root parent
     * @param array<string, string>                           $directories  theme id => absolute directory, for the lineage
     * @param list<array{theme: string, file: string}>        $stylesheets  in load order (parents first)
     * @param list<array{theme: string, file: string}>        $javascripts  in load order (parents first)
     * @param list<string>                                    $bodyClasses
     * @param array<string, string>                           $tokens       CSS custom property (without "--") => value
     * @param array<string, ColorScheme>                      $colorSchemes
     * @param array{light: ?string, dark: ?string}            $autoSchemes  schemes used by "auto"
     * @param array<string, ThemeOption>                      $options
     * @param list<string>                                    $swatch       colours shown on the theme card
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $path,
        public bool $legacy,
        public string $description = '',
        public string $version = '',
        public string $author = '',
        public string $homepage = '',
        public ?string $parent = null,
        public bool $replacesCore = false,
        public array $lineage = [],
        public array $directories = [],
        public array $stylesheets = [],
        public array $javascripts = [],
        public array $bodyClasses = [],
        public array $tokens = [],
        public array $colorSchemes = [],
        public string $defaultColorScheme = self::SCHEME_AUTO,
        public array $autoSchemes = ['light' => null, 'dark' => null],
        public array $options = [],
        public array $swatch = [],
        public ?string $preview = null,
    ) {
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_ID, 'Default', '', true, 'The stock Redmine interface.', lineage: [self::DEFAULT_ID]);
    }

    public function isDefault(): bool
    {
        return self::DEFAULT_ID === $this->id;
    }

    /** Value stored in Redmine's ui_theme setting ("" for the default theme) */
    public function settingValue(): string
    {
        return $this->isDefault() ? '' : $this->id;
    }

    /** CSS class added to <body> (ApplicationHelper#body_css_classes: 'theme-' + name with spaces replaced) */
    public function bodyClass(): string
    {
        return $this->isDefault() ? '' : 'theme-'.str_replace(' ', '_', $this->name);
    }

    public function hasColorSchemes(): bool
    {
        return \count($this->colorSchemes) > 1;
    }

    /**
     * Every option, the built-in colour scheme option first when the theme has several schemes.
     *
     * @return array<string, ThemeOption>
     */
    public function allOptions(): array
    {
        $options = $this->options;
        if ($this->hasColorSchemes()) {
            $choices = [];
            if (null !== $this->autoSchemes['light'] && null !== $this->autoSchemes['dark']) {
                $choices[self::SCHEME_AUTO] = ['label' => ['en' => 'Match system'], 'description' => [], 'tokens' => [], 'schemes' => [], 'swatch' => null];
            }
            foreach ($this->colorSchemes as $scheme) {
                $choices[$scheme->id] = ['label' => $scheme->label, 'description' => [], 'tokens' => [], 'schemes' => [], 'swatch' => null];
            }
            $default = isset($choices[$this->defaultColorScheme]) ? $this->defaultColorScheme : (string) array_key_first($choices);
            $options = [self::OPTION_COLOR_SCHEME => new ThemeOption(
                name: self::OPTION_COLOR_SCHEME,
                type: ThemeOption::TYPE_CHOICE,
                label: ['en' => 'Color scheme', 'pl' => 'Schemat kolorów'],
                default: $default,
                user: true,
                quick: true,
                choices: $choices,
            )] + $options;
        }

        return $options;
    }

    public function option(string $name): ?ThemeOption
    {
        return $this->allOptions()[$name] ?? null;
    }

    /** @return array<string, ThemeOption> options users may set for themselves */
    public function userOptions(): array
    {
        return array_filter($this->allOptions(), static fn (ThemeOption $o) => $o->user);
    }

    /** @return array<string, string> default value of every option */
    public function defaults(): array
    {
        return array_map(static fn (ThemeOption $o) => $o->default, $this->allOptions());
    }

    /**
     * Absolute path of a theme file, looked up in this theme then in its parents.
     *
     * @return array{theme: string, path: string}|null
     */
    public function locate(string $relative): ?array
    {
        foreach ($this->lineage as $id) {
            $dir = $this->directories[$id] ?? null;
            if (null !== $dir && is_file($dir.'/'.$relative)) {
                return ['theme' => $id, 'path' => $dir.'/'.$relative];
            }
        }

        return null;
    }

    /** @return list<string> template directories, this theme first then its parents */
    public function templateDirectories(): array
    {
        $dirs = [];
        foreach ($this->lineage as $id) {
            $dir = ($this->directories[$id] ?? '').'/templates';
            if (isset($this->directories[$id]) && is_dir($dir)) {
                $dirs[] = $dir;
            }
        }

        return $dirs;
    }
}

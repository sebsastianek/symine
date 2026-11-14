<?php

declare(strict_types=1);

namespace App\Theme;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads theme directories into a normalised definition, merges a definition over its parent's and
 * builds the Theme. Every problem of a manifest is reported at once (InvalidThemeException::$errors).
 */
final class ThemeManifest
{
    private const array KEYS = [
        'name', 'description', 'version', 'author', 'homepage', 'parent', 'replaces_core', 'stylesheets', 'javascripts',
        'body_classes', 'tokens', 'color_schemes', 'default_color_scheme', 'auto_color_schemes', 'options', 'swatch', 'preview',
    ];

    private const array OPTION_KEYS = [
        'type', 'label', 'description', 'group', 'default', 'user', 'quick', 'choices', 'presets', 'token', 'tokens', 'schemes', 'min', 'max', 'step', 'units',
    ];

    /** Directories of a theme that are never served nor scanned for assets */
    public const array PRIVATE_DIRECTORIES = ['templates', 'src'];

    /**
     * Normalised definition of the theme in $dir, not merged with its parent.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidThemeException
     */
    public static function read(string $id, string $dir): array
    {
        if (!is_file($dir.'/'.Theme::MANIFEST)) {
            return self::readRedmineTheme($id, $dir);
        }
        try {
            $raw = Yaml::parseFile($dir.'/'.Theme::MANIFEST) ?? [];
        } catch (ParseException $e) {
            throw new InvalidThemeException($id, [Theme::MANIFEST.': '.$e->getMessage()]);
        }
        if (!\is_array($raw) || ([] !== $raw && array_is_list($raw))) {
            throw new InvalidThemeException($id, [Theme::MANIFEST.' must be a mapping']);
        }

        $errors = [];
        foreach (array_diff(array_keys($raw), self::KEYS) as $unknown) {
            $errors[] = \sprintf('unknown key "%s"', $unknown);
        }

        $def = ['id' => $id, 'path' => $dir, 'legacy' => false, 'present' => array_keys($raw)];
        foreach (['name', 'description', 'version', 'author', 'homepage', 'preview'] as $key) {
            $value = $raw[$key] ?? null;
            if (null !== $value && !\is_scalar($value)) {
                $errors[] = "$key must be a string";
                $value = null;
            }
            $def[$key] = null === $value ? null : trim((string) $value);
        }
        $def['name'] = $def['name'] ?: self::humanize($id);

        $parent = $raw['parent'] ?? null;
        if (null !== $parent && (!\is_string($parent) || !self::isThemeId($parent) || $parent === $id)) {
            $errors[] = 'parent must be the id of another theme';
            $parent = null;
        }
        $def['parent'] = $parent;
        $def['replaces_core'] = isset($raw['replaces_core']) ? (bool) $raw['replaces_core'] : null;

        foreach (['stylesheets' => 'css', 'javascripts' => 'js'] as $key => $extension) {
            if (\array_key_exists($key, $raw)) {
                $def[$key] = self::files($raw[$key], $dir, $key, $extension, $errors);
            } else {
                $conventional = 'css' === $extension ? 'stylesheets/application.css' : 'javascripts/theme.js';
                $def[$key] = is_file($dir.'/'.$conventional) ? [$conventional] : [];
            }
        }
        if (null !== $def['preview'] && !self::isAssetPath($def['preview'], $dir)) {
            $errors[] = \sprintf('preview: file "%s" not found', $def['preview']);
            $def['preview'] = null;
        }

        $def['body_classes'] = [];
        foreach ((array) ($raw['body_classes'] ?? []) as $class) {
            if (\is_string($class) && preg_match('/^[A-Za-z_][\w-]*$/', $class)) {
                $def['body_classes'][] = $class;
            } else {
                $errors[] = 'body_classes must be a list of CSS class names';
            }
        }

        $def['swatch'] = [];
        foreach ((array) ($raw['swatch'] ?? []) as $color) {
            $color = \is_string($color) ? CssValue::color($color) : null;
            if (null === $color) {
                $errors[] = 'swatch must be a list of colours';
            } else {
                $def['swatch'][] = $color;
            }
        }

        $def['tokens'] = self::tokens($raw['tokens'] ?? [], 'tokens', $errors);

        $def['color_schemes'] = [];
        if (isset($raw['color_schemes']) && !self::isMap($raw['color_schemes'])) {
            $errors[] = 'color_schemes must be a mapping of scheme id to definition';
        } else {
            foreach ($raw['color_schemes'] ?? [] as $schemeId => $scheme) {
                $schemeId = (string) $schemeId;
                if (!CssValue::isIdentifier($schemeId) || Theme::SCHEME_AUTO === $schemeId) {
                    $errors[] = \sprintf('color_schemes: "%s" is not a valid scheme id', $schemeId);
                    continue;
                }
                $scheme = self::isMap($scheme) ? $scheme : [];
                foreach (array_diff(array_keys($scheme), ['label', 'dark', 'tokens']) as $unknown) {
                    $errors[] = \sprintf('color_schemes.%s: unknown key "%s"', $schemeId, $unknown);
                }
                $def['color_schemes'][$schemeId] = [
                    'label' => isset($scheme['label']) ? self::label($scheme['label'], "color_schemes.$schemeId.label", $errors) : null,
                    'dark' => isset($scheme['dark']) ? (bool) $scheme['dark'] : null,
                    'tokens' => self::tokens($scheme['tokens'] ?? [], "color_schemes.$schemeId.tokens", $errors),
                ];
            }
        }
        $def['default_color_scheme'] = isset($raw['default_color_scheme']) ? (string) $raw['default_color_scheme'] : null;
        $def['auto_color_schemes'] = null;
        if (isset($raw['auto_color_schemes'])) {
            $auto = $raw['auto_color_schemes'];
            if (self::isMap($auto) && [] === array_diff(array_keys($auto), ['light', 'dark'])) {
                $def['auto_color_schemes'] = ['light' => isset($auto['light']) ? (string) $auto['light'] : null, 'dark' => isset($auto['dark']) ? (string) $auto['dark'] : null];
            } else {
                $errors[] = 'auto_color_schemes must be a mapping with the keys "light" and "dark"';
            }
        }

        $def['options'] = [];
        if (isset($raw['options']) && !self::isMap($raw['options'])) {
            $errors[] = 'options must be a mapping of option name to definition';
        } else {
            foreach ($raw['options'] ?? [] as $name => $option) {
                $name = (string) $name;
                if (null === $option) {
                    // removes an option inherited from the parent theme
                    $def['options'][$name] = null;
                    continue;
                }
                if (!preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $name) || Theme::OPTION_COLOR_SCHEME === $name) {
                    $errors[] = \sprintf('options: "%s" is not a valid option name (lowercase letters, digits and underscores)', $name);
                    continue;
                }
                $normalized = self::option($name, $option, $dir, $errors);
                if (null !== $normalized) {
                    $def['options'][$name] = $normalized;
                }
            }
        }

        if ([] !== $errors) {
            throw new InvalidThemeException($id, $errors);
        }

        return $def;
    }

    /**
     * Redmine theme: stylesheets/*.css replace the core stylesheets of the same name, javascripts/theme.js is loaded.
     *
     * @return array<string, mixed>
     */
    private static function readRedmineTheme(string $id, string $dir): array
    {
        if (!is_file($dir.'/stylesheets/application.css')) {
            throw new InvalidThemeException($id, ['a theme needs a '.Theme::MANIFEST.' or a stylesheets/application.css']);
        }
        $stylesheets = array_map(static fn (string $f) => 'stylesheets/'.basename($f), glob($dir.'/stylesheets/*.css') ?: []);
        sort($stylesheets);

        return [
            'id' => $id, 'path' => $dir, 'legacy' => true, 'present' => [],
            // Redmine::Themes::Theme#name: the directory name humanized
            'name' => self::humanize($id), 'description' => null, 'version' => null, 'author' => null, 'homepage' => null, 'preview' => null,
            'parent' => null, 'replaces_core' => null,
            'stylesheets' => $stylesheets,
            'javascripts' => is_file($dir.'/javascripts/theme.js') ? ['javascripts/theme.js'] : [],
            'body_classes' => [], 'swatch' => [], 'tokens' => [], 'color_schemes' => [], 'default_color_scheme' => null, 'auto_color_schemes' => null, 'options' => [],
        ];
    }

    /**
     * Definition of a theme merged over the (already merged) definition of its parent.
     *
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $child
     *
     * @return array<string, mixed>
     */
    public static function merge(array $parent, array $child): array
    {
        $merged = $child;
        $merged['lineage'] = [$child['id'], ...($parent['lineage'] ?? [$parent['id']])];
        $merged['directories'] = [$child['id'] => $child['path']] + ($parent['directories'] ?? [$parent['id'] => $parent['path']]);
        $merged['replaces_core'] = $child['replaces_core'] ?? $parent['replaces_core'] ?? null;
        foreach (['stylesheets', 'javascripts'] as $key) {
            $merged[$key.'_chain'] = [
                ...($parent[$key.'_chain'] ?? self::chain($parent, $key)),
                ...self::chain($child, $key),
            ];
        }
        $merged['body_classes'] = array_values(array_unique([...$parent['body_classes'], ...$child['body_classes']]));
        $merged['swatch'] = $child['swatch'] ?: $parent['swatch'];
        $merged['tokens'] = $child['tokens'] + $parent['tokens'];
        $merged['color_schemes'] = $parent['color_schemes'];
        foreach ($child['color_schemes'] as $schemeId => $scheme) {
            $base = $parent['color_schemes'][$schemeId] ?? ['label' => null, 'dark' => null, 'tokens' => []];
            $merged['color_schemes'][$schemeId] = [
                'label' => $scheme['label'] ?? $base['label'],
                'dark' => $scheme['dark'] ?? $base['dark'],
                'tokens' => $scheme['tokens'] + $base['tokens'],
            ];
        }
        $merged['default_color_scheme'] = $child['default_color_scheme'] ?? $parent['default_color_scheme'];
        $merged['auto_color_schemes'] = $child['auto_color_schemes'] ?? $parent['auto_color_schemes'];
        $merged['options'] = $parent['options'];
        foreach ($child['options'] as $name => $option) {
            if (null === $option) {
                unset($merged['options'][$name]);
            } else {
                $merged['options'][$name] = $option;
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $def a definition, merged with its parents when it has some
     *
     * @throws InvalidThemeException
     */
    public static function build(array $def): Theme
    {
        $errors = [];
        $id = $def['id'];

        $schemes = [];
        foreach ($def['color_schemes'] as $schemeId => $scheme) {
            $schemes[$schemeId] = new ColorScheme($schemeId, $scheme['label'] ?? ['en' => self::humanize($schemeId)], (bool) ($scheme['dark'] ?? false), $scheme['tokens']);
        }

        $auto = ['light' => null, 'dark' => null];
        foreach ($schemes as $scheme) {
            $auto[$scheme->dark ? 'dark' : 'light'] ??= $scheme->id;
        }
        foreach ($def['auto_color_schemes'] ?? [] as $kind => $schemeId) {
            if (null === $schemeId) {
                continue;
            }
            if (!isset($schemes[$schemeId])) {
                $errors[] = \sprintf('auto_color_schemes.%s: unknown colour scheme "%s"', $kind, $schemeId);
            } else {
                $auto[$kind] = $schemeId;
            }
        }
        $hasAuto = null !== $auto['light'] && null !== $auto['dark'];
        $defaultScheme = $def['default_color_scheme'] ?? ($hasAuto ? Theme::SCHEME_AUTO : (string) (array_key_first($schemes) ?? ''));
        if ([] !== $schemes && !isset($schemes[$defaultScheme]) && !(Theme::SCHEME_AUTO === $defaultScheme && $hasAuto)) {
            $errors[] = \sprintf('default_color_scheme: unknown colour scheme "%s"', $defaultScheme);
        }

        $options = [];
        foreach ($def['options'] as $name => $o) {
            if (null === $o) {
                continue;
            }
            foreach ($o['choices'] as $choiceId => $choice) {
                foreach (array_keys($choice['schemes']) as $schemeId) {
                    if (!isset($schemes[$schemeId])) {
                        $errors[] = \sprintf('options.%s.choices.%s.schemes: unknown colour scheme "%s"', $name, $choiceId, $schemeId);
                    }
                }
            }
            foreach ($o['presets'] as $presetId => $preset) {
                foreach (array_keys($preset['schemes']) as $schemeId) {
                    if (!isset($schemes[$schemeId])) {
                        $errors[] = \sprintf('options.%s.presets.%s.schemes: unknown colour scheme "%s"', $name, $presetId, $schemeId);
                    }
                }
            }
            foreach (array_keys($o['schemes']) as $schemeId) {
                if (!isset($schemes[$schemeId])) {
                    $errors[] = \sprintf('options.%s.schemes: unknown colour scheme "%s"', $name, $schemeId);
                }
            }
            $options[$name] = new ThemeOption(
                name: $name, type: $o['type'], label: $o['label'], default: $o['default'], user: $o['user'], quick: $o['quick'],
                description: $o['description'], group: $o['group'], choices: $o['choices'], presets: $o['presets'], token: $o['token'],
                tokens: $o['tokens'], schemes: $o['schemes'], min: $o['min'], max: $o['max'], step: $o['step'], units: $o['units'],
            );
        }

        if ([] !== $errors) {
            throw new InvalidThemeException($id, $errors);
        }

        return new Theme(
            id: $id,
            name: $def['name'],
            path: $def['path'],
            legacy: $def['legacy'],
            description: (string) $def['description'],
            version: (string) $def['version'],
            author: (string) $def['author'],
            homepage: (string) $def['homepage'],
            parent: $def['parent'],
            replacesCore: (bool) $def['replaces_core'],
            lineage: $def['lineage'] ?? [$id],
            directories: $def['directories'] ?? [$id => $def['path']],
            stylesheets: $def['stylesheets_chain'] ?? self::chain($def, 'stylesheets'),
            javascripts: $def['javascripts_chain'] ?? self::chain($def, 'javascripts'),
            bodyClasses: $def['body_classes'],
            tokens: $def['tokens'],
            colorSchemes: $schemes,
            defaultColorScheme: $defaultScheme,
            autoSchemes: $auto,
            options: $options,
            swatch: $def['swatch'],
            preview: $def['preview'],
        );
    }

    public static function isThemeId(string $id): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $id) && Theme::DEFAULT_ID !== $id;
    }

    /** String#humanize: "my_theme" => "My theme" */
    public static function humanize(string $id): string
    {
        return ucfirst(strtolower(trim(str_replace('_', ' ', $id))));
    }

    /**
     * @param array<string, mixed> $def
     *
     * @return list<array{theme: string, file: string}>
     */
    private static function chain(array $def, string $key): array
    {
        return array_map(static fn (string $file) => ['theme' => $def['id'], 'file' => $file], $def[$key]);
    }

    /**
     * @param list<string> $errors
     *
     * @return list<string>
     */
    private static function files(mixed $value, string $dir, string $key, string $extension, array &$errors): array
    {
        $files = [];
        foreach ((array) $value as $file) {
            if (!\is_string($file) || !str_ends_with($file, '.'.$extension) || !self::isAssetPath($file, $dir)) {
                $errors[] = \sprintf('%s: "%s" must be an existing .%s file of the theme, relative to its directory', $key, \is_scalar($file) ? $file : '?', $extension);
                continue;
            }
            $files[] = $file;
        }

        return $files;
    }

    /** Whether $file is a servable file of the theme directory */
    public static function isAssetPath(string $file, string $dir): bool
    {
        if (!preg_match('#^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.@-]*)+$#', $file) || str_contains($file, '..')) {
            return false;
        }

        return !\in_array(strtok($file, '/'), self::PRIVATE_DIRECTORIES, true) && is_file($dir.'/'.$file);
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, string>
     */
    private static function tokens(mixed $value, string $path, array &$errors): array
    {
        if (null === $value || [] === $value) {
            return [];
        }
        if (!self::isMap($value)) {
            $errors[] = "$path must be a mapping of CSS custom property name (without \"--\") to value";

            return [];
        }
        $tokens = [];
        foreach ($value as $name => $v) {
            $name = (string) $name;
            if (!CssValue::isIdentifier($name)) {
                $errors[] = \sprintf('%s: "%s" is not a valid custom property name (write it without the leading "--")', $path, $name);
                continue;
            }
            if (!\is_scalar($v) || !CssValue::isSafeDeclarationValue((string) $v)) {
                $errors[] = \sprintf('%s.%s: not a valid CSS value', $path, $name);
                continue;
            }
            $tokens[$name] = trim((string) $v);
        }

        return $tokens;
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, string>
     */
    private static function label(mixed $value, string $path, array &$errors): array
    {
        if (\is_scalar($value)) {
            return ['en' => (string) $value];
        }
        if (self::isMap($value) && [] === array_filter($value, static fn ($v) => !\is_scalar($v))) {
            return array_map('strval', $value);
        }
        $errors[] = "$path must be a string or a mapping of locale to string";

        return [];
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function option(string $name, mixed $raw, string $dir, array &$errors): ?array
    {
        $path = "options.$name";
        if (!self::isMap($raw)) {
            $errors[] = "$path must be a mapping";

            return null;
        }
        $before = \count($errors);
        foreach (array_diff(array_keys($raw), self::OPTION_KEYS) as $unknown) {
            $errors[] = \sprintf('%s: unknown key "%s"', $path, $unknown);
        }
        $type = (string) ($raw['type'] ?? '');
        if (!\in_array($type, ThemeOption::TYPES, true)) {
            $errors[] = \sprintf('%s.type must be one of: %s', $path, implode(', ', ThemeOption::TYPES));

            return null;
        }

        $o = [
            'type' => $type,
            'label' => isset($raw['label']) ? self::label($raw['label'], "$path.label", $errors) : ['en' => self::humanize($name)],
            'description' => isset($raw['description']) ? self::label($raw['description'], "$path.description", $errors) : [],
            'group' => \is_scalar($raw['group'] ?? '') ? (string) ($raw['group'] ?? '') : '',
            'user' => (bool) ($raw['user'] ?? false),
            'quick' => (bool) ($raw['quick'] ?? false),
            'choices' => [], 'presets' => [], 'token' => null, 'tokens' => [], 'schemes' => [],
            'min' => isset($raw['min']) && is_numeric($raw['min']) ? (float) $raw['min'] : null,
            'max' => isset($raw['max']) && is_numeric($raw['max']) ? (float) $raw['max'] : null,
            'step' => isset($raw['step']) && is_numeric($raw['step']) ? (float) $raw['step'] : null,
            'units' => ['px'],
        ];

        if (isset($raw['token'])) {
            if (\is_string($raw['token']) && CssValue::isIdentifier($raw['token'])) {
                $o['token'] = $raw['token'];
            } else {
                $errors[] = "$path.token must be a CSS custom property name, without the leading \"--\"";
            }
        }
        $valueTypes = [ThemeOption::TYPE_COLOR, ThemeOption::TYPE_LENGTH, ThemeOption::TYPE_NUMBER];
        if (\in_array($type, $valueTypes, true) && null === $o['token']) {
            $errors[] = "$path.token is required for the type \"$type\"";
        }
        if ($o['quick'] && !\in_array($type, [ThemeOption::TYPE_CHOICE, ThemeOption::TYPE_TOGGLE], true)) {
            $errors[] = "$path.quick is only available for choice and toggle options";
        }
        if ($o['user'] && ThemeOption::TYPE_IMAGE === $type) {
            $errors[] = "$path.user: images can only be set by administrators";
        }

        if (ThemeOption::TYPE_CHOICE === $type) {
            if (!self::isMap($raw['choices'] ?? null) || [] === $raw['choices']) {
                $errors[] = "$path.choices must be a non-empty mapping of choice id to label or definition";
            } else {
                foreach ($raw['choices'] as $choiceId => $choice) {
                    $choiceId = (string) $choiceId;
                    if (!CssValue::isIdentifier($choiceId)) {
                        $errors[] = \sprintf('%s.choices: "%s" is not a valid choice id', $path, $choiceId);
                        continue;
                    }
                    $choice = self::isMap($choice) ? $choice : ['label' => $choice];
                    foreach (array_diff(array_keys($choice), ['label', 'description', 'tokens', 'schemes', 'swatch']) as $unknown) {
                        $errors[] = \sprintf('%s.choices.%s: unknown key "%s"', $path, $choiceId, $unknown);
                    }
                    $schemes = [];
                    foreach (self::isMap($choice['schemes'] ?? null) ? $choice['schemes'] : [] as $schemeId => $tokens) {
                        $schemes[(string) $schemeId] = self::tokens($tokens, "$path.choices.$choiceId.schemes.$schemeId", $errors);
                    }
                    $swatch = isset($choice['swatch']) && \is_string($choice['swatch']) ? CssValue::color($choice['swatch']) : null;
                    if (isset($choice['swatch']) && null === $swatch) {
                        $errors[] = "$path.choices.$choiceId.swatch must be a colour";
                    }
                    $o['choices'][$choiceId] = [
                        'label' => isset($choice['label']) ? self::label($choice['label'], "$path.choices.$choiceId.label", $errors) : ['en' => self::humanize($choiceId)],
                        'description' => isset($choice['description']) ? self::label($choice['description'], "$path.choices.$choiceId.description", $errors) : [],
                        'tokens' => self::tokens($choice['tokens'] ?? [], "$path.choices.$choiceId.tokens", $errors),
                        'schemes' => $schemes,
                        'swatch' => $swatch,
                    ];
                }
            }
        } elseif (isset($raw['choices'])) {
            $errors[] = "$path.choices is only available for choice options";
        }

        if (ThemeOption::TYPE_COLOR === $type && isset($raw['presets'])) {
            if (!self::isMap($raw['presets'])) {
                $errors[] = "$path.presets must be a mapping of preset id to colour or definition";
            } else {
                foreach ($raw['presets'] as $presetId => $preset) {
                    $presetId = (string) $presetId;
                    if (!CssValue::isIdentifier($presetId) || 'custom' === $presetId) {
                        $errors[] = \sprintf('%s.presets: "%s" is not a valid preset id', $path, $presetId);
                        continue;
                    }
                    $preset = self::isMap($preset) ? $preset : ['value' => $preset];
                    $value = \is_string($preset['value'] ?? null) ? CssValue::color($preset['value']) : null;
                    if (null === $value) {
                        $errors[] = "$path.presets.$presetId.value must be a colour";
                        continue;
                    }
                    $perScheme = [];
                    foreach (self::isMap($preset['schemes'] ?? null) ? $preset['schemes'] : [] as $schemeId => $schemeValue) {
                        $schemeValue = \is_string($schemeValue) ? CssValue::color($schemeValue) : null;
                        if (null === $schemeValue) {
                            $errors[] = "$path.presets.$presetId.schemes.$schemeId must be a colour";
                            continue;
                        }
                        $perScheme[(string) $schemeId] = $schemeValue;
                    }
                    $o['presets'][$presetId] = [
                        'label' => isset($preset['label']) ? self::label($preset['label'], "$path.presets.$presetId.label", $errors) : ['en' => self::humanize($presetId)],
                        'value' => $value,
                        'schemes' => $perScheme,
                    ];
                }
            }
        } elseif (isset($raw['presets'])) {
            $errors[] = "$path.presets is only available for color options";
        }

        if (ThemeOption::TYPE_TOGGLE === $type) {
            $o['tokens'] = self::tokens($raw['tokens'] ?? [], "$path.tokens", $errors);
            foreach (self::isMap($raw['schemes'] ?? null) ? $raw['schemes'] : [] as $schemeId => $tokens) {
                $o['schemes'][(string) $schemeId] = self::tokens($tokens, "$path.schemes.$schemeId", $errors);
            }
        } elseif (isset($raw['tokens']) || isset($raw['schemes'])) {
            $errors[] = "$path.tokens and $path.schemes are only available for toggle options (choices carry their own tokens)";
        }

        if (ThemeOption::TYPE_LENGTH === $type) {
            $units = array_values(array_filter((array) ($raw['units'] ?? ['px']), static fn ($u) => \is_string($u) && preg_match('/^(?:[a-z]{1,5}|%)$/', $u)));
            if ([] === $units) {
                $errors[] = "$path.units must be a list of CSS units";
            } else {
                $o['units'] = $units;
            }
        }

        // the default value must be acceptable for the option
        $probe = new ThemeOption(
            name: $name, type: $type, label: $o['label'], choices: $o['choices'], presets: $o['presets'], token: $o['token'],
            min: $o['min'], max: $o['max'], units: $o['units'],
        );
        $default = $raw['default'] ?? match ($type) {
            ThemeOption::TYPE_CHOICE => (string) (array_key_first($o['choices']) ?? ''),
            ThemeOption::TYPE_TOGGLE => '0',
            ThemeOption::TYPE_TEXT, ThemeOption::TYPE_IMAGE => '',
            default => null,
        };
        if (ThemeOption::TYPE_IMAGE === $type && \is_string($default) && '' !== $default) {
            // the default picture of an image option is a file of the theme
            if (!self::isAssetPath($default, $dir)) {
                $errors[] = \sprintf('%s.default: file "%s" not found', $path, $default);
            }
            $o['default'] = $default;
        } else {
            $normalized = null === $default ? null : $probe->normalize($default);
            if (null === $normalized) {
                $errors[] = null === $default ? "$path.default is required" : \sprintf('%s.default: "%s" is not a valid value', $path, \is_scalar($default) ? $default : '?');
            }
            $o['default'] = $normalized ?? '';
        }

        return \count($errors) > $before ? null : $o;
    }

    private static function isMap(mixed $value): bool
    {
        return \is_array($value) && ([] === $value || !array_is_list($value));
    }
}

<?php

declare(strict_types=1);

namespace App\Theme;

/**
 * Generates the stylesheet that turns the manifest of a theme and the values chosen for the site into CSS:
 * custom properties for the tokens, for every colour scheme (and "auto", which follows the system),
 * for every choice of the options, and the custom CSS of the administrators.
 *
 * The stylesheet does not depend on the user: personal values select among the generated rules through the
 * data-* attributes of <html> (see ThemeContext::htmlAttributes), so it can be cached by every browser.
 */
final class ThemeCssCompiler
{
    public function __construct(private readonly ThemeSettings $settings, private readonly ThemeContext $context)
    {
    }

    public function compile(Theme $theme): string
    {
        $root = ':root[data-theme="'.$theme->id.'"]';
        $css = '/* '.str_replace('*/', '', $theme->name).": generated from theme.yaml and the appearance settings */\n";

        $css .= $this->rule($root, $theme->tokens);

        foreach ($theme->colorSchemes as $scheme) {
            $css .= $this->schemeRules($theme, $scheme->id, $root, '', ['color-scheme' => $scheme->dark ? 'dark' : 'light'], $scheme->tokens);
        }

        $site = $this->settings->siteOptions($theme);
        $siteValues = [];
        foreach ($theme->options as $name => $option) {
            $attribute = $option->attribute();
            switch ($option->type) {
                case ThemeOption::TYPE_CHOICE:
                    foreach ($option->choices as $choiceId => $choice) {
                        $selector = '['.$attribute.'="'.$choiceId.'"]';
                        $css .= $this->rule($root.$selector, $choice['tokens']);
                        foreach ($choice['schemes'] as $schemeId => $tokens) {
                            $css .= $this->schemeRules($theme, $schemeId, $root, $selector, [], $tokens);
                        }
                    }
                    break;
                case ThemeOption::TYPE_TOGGLE:
                    $selector = '['.$attribute.'="on"]';
                    $css .= $this->rule($root.$selector, $option->tokens);
                    foreach ($option->schemes as $schemeId => $tokens) {
                        $css .= $this->schemeRules($theme, $schemeId, $root, $selector, [], $tokens);
                    }
                    break;
                case ThemeOption::TYPE_COLOR:
                    foreach ($option->presets as $presetId => $preset) {
                        $selector = '['.$attribute.'="'.$presetId.'"]';
                        $css .= $this->rule($root.$selector, [$option->token => $preset['value']]);
                        foreach ($preset['schemes'] as $schemeId => $value) {
                            $css .= $this->schemeRules($theme, $schemeId, $root, $selector, [], [$option->token => $value]);
                        }
                    }
                    // no break: a colour that is not a preset is a plain value
                default:
                    if (null !== $option->token && !$option->isPreset($site[$name])) {
                        $value = $this->context->cssValue($theme, $option, $site[$name]);
                        if (null !== $value) {
                            $siteValues[$option->token] = $value;
                        }
                    }
            }
        }
        // values of the site that are not a declared choice; with presets they apply to the "custom" state only
        foreach ($theme->options as $name => $option) {
            if (null === $option->token || !isset($siteValues[$option->token])) {
                continue;
            }
            $selector = [] !== $option->presets ? '['.$option->attribute().'="custom"]' : '';
            // [data-theme] twice: as specific as the rules of a colour scheme, which these values come after
            $css .= $this->rule($root.'[data-theme]'.$selector, [$option->token => $siteValues[$option->token]], false);
        }

        $custom = $this->settings->customCss($theme);
        if ('' !== $custom) {
            $css .= "\n/* Custom CSS */\n".$custom."\n";
        }

        return $css;
    }

    /**
     * Rules for a colour scheme: when it is selected, and when "auto" resolves to it.
     *
     * @param array<string, string> $properties plain declarations
     * @param array<string, string> $tokens
     */
    private function schemeRules(Theme $theme, string $schemeId, string $root, string $suffix, array $properties, array $tokens): string
    {
        if ([] === $properties && [] === $tokens) {
            return '';
        }
        $body = $this->declarations($tokens, $properties);
        $css = $root.'[data-color-scheme="'.$schemeId.'"]'.$suffix.'{'.$body."}\n";
        foreach (['light', 'dark'] as $kind) {
            if ($theme->autoSchemes[$kind] === $schemeId && null !== $theme->autoSchemes['light'] && null !== $theme->autoSchemes['dark']) {
                $css .= '@media (prefers-color-scheme: '.$kind.'){'.$root.'[data-color-scheme="auto"]'.$suffix.'{'.$body."}}\n";
            }
        }

        return $css;
    }

    /** @param array<string, string> $tokens */
    private function rule(string $selector, array $tokens, bool $checked = true): string
    {
        return [] === $tokens ? '' : $selector.'{'.$this->declarations($tokens, [], $checked)."}\n";
    }

    /**
     * @param array<string, string> $tokens
     * @param array<string, string> $properties
     */
    private function declarations(array $tokens, array $properties = [], bool $checked = true): string
    {
        $out = [];
        foreach ($properties as $name => $value) {
            $out[] = $name.':'.$value;
        }
        foreach ($tokens as $name => $value) {
            // manifests are validated when they are read; values are checked again as a second line of defence
            if (CssValue::isIdentifier((string) $name) && (!$checked || CssValue::isSafeDeclarationValue((string) $value))) {
                $out[] = '--'.$name.':'.$value;
            }
        }

        return implode(';', $out);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Theme;

use App\Theme\InvalidThemeException;
use App\Theme\Theme;
use App\Theme\ThemeManifest;
use App\Theme\ThemeOption;
use App\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;

/** Reading, validating and inheriting theme manifests (tests/fixtures/themes) */
final class ThemeManifestTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../fixtures/themes';

    private function registry(): ThemeRegistry
    {
        // an empty project directory: only the fixtures are scanned
        return new ThemeRegistry(sys_get_temp_dir().'/symine-no-themes', [self::FIXTURES]);
    }

    public function testDefaultThemeAlwaysExistsAndComesFirst(): void
    {
        $themes = $this->registry()->all();
        self::assertSame(Theme::DEFAULT_ID, array_key_first($themes));
        self::assertTrue($themes[Theme::DEFAULT_ID]->isDefault());
        self::assertSame('', $themes[Theme::DEFAULT_ID]->settingValue());
        self::assertSame('', $themes[Theme::DEFAULT_ID]->bodyClass());
        self::assertTrue($this->registry()->get('')->isDefault(), 'Redmine stores the default theme as an empty ui_theme');
    }

    public function testManifestIsRead(): void
    {
        $theme = $this->registry()->get('fx_base');
        self::assertNotNull($theme);
        self::assertFalse($theme->legacy);
        self::assertSame('Fixture base', $theme->name);
        self::assertSame('2.1.0', $theme->version);
        self::assertSame('theme-Fixture_base', $theme->bodyClass());
        self::assertSame(['fx'], $theme->bodyClasses);
        self::assertSame([['theme' => 'fx_base', 'file' => 'stylesheets/application.css']], $theme->stylesheets);
        self::assertSame([['theme' => 'fx_base', 'file' => 'javascripts/theme.js']], $theme->javascripts);
        self::assertSame(['fx-gap' => '12px', 'oc-gray-4' => '#445566'], $theme->tokens);
        self::assertSame(['light', 'dark'], array_keys($theme->colorSchemes));
        self::assertTrue($theme->colorSchemes['dark']->dark);
        self::assertSame(['light' => 'light', 'dark' => 'dark'], $theme->autoSchemes);
        self::assertSame(Theme::SCHEME_AUTO, $theme->defaultColorScheme);
    }

    public function testOptionsAndTheBuiltInColorSchemeOption(): void
    {
        $theme = $this->registry()->get('fx_base');
        self::assertSame(['color_scheme', 'accent', 'density', 'wide', 'radius', 'columns', 'tagline', 'logo'], array_keys($theme->allOptions()));
        self::assertSame(['color_scheme', 'accent', 'density', 'wide'], array_keys($theme->userOptions()));

        $scheme = $theme->option('color_scheme');
        self::assertSame(['auto', 'light', 'dark'], array_keys($scheme->choices));
        self::assertSame('data-color-scheme', $scheme->attribute());
        self::assertTrue($scheme->quick);

        self::assertSame(
            ['color_scheme' => 'auto', 'accent' => 'teal', 'density' => 'comfortable', 'wide' => '0', 'radius' => '4px', 'columns' => '2', 'tagline' => '', 'logo' => ''],
            $theme->defaults(),
        );
        self::assertSame('data-theme-density', $theme->option('density')->attribute());
        self::assertSame('data-theme-accent', $theme->option('accent')->attribute(), 'a colour with presets has an attribute');
        self::assertNull($theme->option('radius')->attribute());
        self::assertSame('Akcent', ThemeOption::text($theme->option('accent')->label, 'pl'));
        self::assertSame('Accent', ThemeOption::text($theme->option('accent')->label, 'de'), 'English is the fallback');
    }

    public function testOptionValuesAreNormalized(): void
    {
        $theme = $this->registry()->get('fx_base');

        $density = $theme->option('density');
        self::assertSame('compact', $density->normalize('compact'));
        self::assertNull($density->normalize('cosy'));
        self::assertNull($density->normalize(['compact']));

        $wide = $theme->option('wide');
        self::assertSame('1', $wide->normalize('on'));
        self::assertSame('0', $wide->normalize(''));
        self::assertNull($wide->normalize('maybe'));
        self::assertSame('on', $wide->attributeValue('1'));
        self::assertSame('off', $wide->attributeValue('0'));

        $accent = $theme->option('accent');
        self::assertSame('blue', $accent->normalize('blue'));
        self::assertSame('#aabbcc', $accent->normalize('#AABBCC'));
        self::assertNull($accent->normalize('red; }'));
        self::assertSame('blue', $accent->attributeValue('blue'));
        self::assertSame('custom', $accent->attributeValue('#aabbcc'));

        $radius = $theme->option('radius');
        self::assertSame('6px', $radius->normalize('6'));
        self::assertSame('0.5rem', $radius->normalize('0.5rem'));
        self::assertNull($radius->normalize('11px'), 'above max');
        self::assertNull($radius->normalize('2em'), 'unit not accepted');

        $columns = $theme->option('columns');
        self::assertSame('3', $columns->normalize('3'));
        self::assertNull($columns->normalize('5'));
        self::assertNull($columns->normalize('two'));

        self::assertSame('Hello', $theme->option('tagline')->normalize(' Hello '));
        self::assertNull($theme->option('tagline')->normalize(str_repeat('x', 256)));

        $logo = $theme->option('logo');
        self::assertSame('logo-0123abcd.png', $logo->normalize('logo-0123abcd.png'));
        self::assertSame('', $logo->normalize(''));
        self::assertNull($logo->normalize('../../etc/passwd'));
        self::assertNull($logo->normalize('logo.svg'));
    }

    public function testChildThemeInheritsFromItsParent(): void
    {
        $child = $this->registry()->get('fx_child');
        self::assertNotNull($child);
        self::assertSame('fx_base', $child->parent);
        self::assertSame(['fx_child', 'fx_base'], $child->lineage);
        self::assertSame(
            [['theme' => 'fx_base', 'file' => 'stylesheets/application.css'], ['theme' => 'fx_child', 'file' => 'stylesheets/child.css']],
            $child->stylesheets,
            'the stylesheets of the parent come first',
        );
        self::assertSame([['theme' => 'fx_base', 'file' => 'javascripts/theme.js']], $child->javascripts);
        self::assertSame('20px', $child->tokens['fx-gap'], 'tokens are overridden by name');
        self::assertSame('#445566', $child->tokens['oc-gray-4']);
        self::assertSame('1', $child->tokens['fx-extra']);
        self::assertSame('#222222', $child->colorSchemes['dark']->tokens['fx-paper']);
        self::assertSame('#ffffff', $child->colorSchemes['light']->tokens['fx-paper']);
        self::assertTrue($child->colorSchemes['dark']->dark, 'the scheme stays dark when the child only overrides its tokens');
        self::assertNull($child->option('wide'), 'an option set to null is removed');
        self::assertSame('From the child', $child->option('tagline')->default);
        self::assertNotNull($child->option('accent'));
        self::assertSame(['fx', ], $child->bodyClasses);

        // files and templates fall back to the parent
        self::assertSame('fx_base', $child->locate('images/icons.svg')['theme']);
        self::assertSame('fx_child', $child->locate('stylesheets/child.css')['theme']);
        self::assertNull($child->locate('images/nope.png'));
        self::assertCount(2, $child->templateDirectories());
        self::assertStringContainsString('fx_child', $child->templateDirectories()[0]);
    }

    public function testRedmineThemeWithoutManifest(): void
    {
        $theme = $this->registry()->get('fx_redmine');
        self::assertNotNull($theme);
        self::assertTrue($theme->legacy);
        self::assertSame('Fx redmine', $theme->name, 'the directory name humanized, as Redmine does');
        self::assertSame('theme-Fx_redmine', $theme->bodyClass());
        self::assertSame([], $theme->allOptions());
        self::assertSame(['stylesheets/application.css', 'stylesheets/responsive.css'], array_column($theme->stylesheets, 'file'));
        self::assertSame(['javascripts/theme.js'], array_column($theme->javascripts, 'file'));
    }

    public function testBrokenThemesAreLeftOutAndEveryProblemIsReported(): void
    {
        $registry = $this->registry();
        self::assertNull($registry->get('fx_broken'));
        $errors = implode("\n", $registry->errors()['fx_broken']);
        self::assertStringContainsString('unknown key "colour"', $errors);
        self::assertStringContainsString('stylesheets/missing.css', $errors);
        self::assertStringContainsString('"bad name" is not a valid custom property name', $errors);
        self::assertStringContainsString('tokens.fx-inject: not a valid CSS value', $errors);
        self::assertStringContainsString('options.size.default: "huge" is not a valid value', $errors);
        self::assertStringContainsString('options.mode.default: "z" is not a valid value', $errors);
        self::assertStringContainsString('options.shade.token is required', $errors);
    }

    public function testInheritanceProblems(): void
    {
        $registry = $this->registry();
        self::assertNull($registry->get('fx_cycle_a'));
        self::assertNull($registry->get('fx_cycle_b'));
        self::assertStringContainsString('circular inheritance', implode(' ', $registry->errors()['fx_cycle_a']));
        self::assertStringContainsString('circular inheritance', implode(' ', $registry->errors()['fx_cycle_b']));
        self::assertStringContainsString('"not_installed" is not installed', implode(' ', $registry->errors()['fx_orphan']));
    }

    public function testRedmineThemesCannotBeExtended(): void
    {
        $dir = sys_get_temp_dir().'/symine-theme-test-'.bin2hex(random_bytes(4));
        mkdir($dir.'/legacy/stylesheets', 0o777, true);
        mkdir($dir.'/kid', 0o777, true);
        file_put_contents($dir.'/legacy/stylesheets/application.css', '');
        file_put_contents($dir.'/kid/theme.yaml', "parent: legacy\n");
        try {
            $registry = new ThemeRegistry($dir.'/none', [$dir]);
            self::assertNotNull($registry->get('legacy'));
            self::assertNull($registry->get('kid'));
            self::assertStringContainsString('only themes with a theme.yaml can be extended', implode(' ', $registry->errors()['kid']));
        } finally {
            unlink($dir.'/legacy/stylesheets/application.css');
            unlink($dir.'/kid/theme.yaml');
            rmdir($dir.'/legacy/stylesheets');
            rmdir($dir.'/legacy');
            rmdir($dir.'/kid');
            rmdir($dir);
        }
    }

    public function testInvalidYamlAndReservedNames(): void
    {
        $dir = sys_get_temp_dir().'/symine-theme-test-'.bin2hex(random_bytes(4));
        mkdir($dir.'/bad', 0o777, true);
        mkdir($dir.'/default/stylesheets', 0o777, true);
        file_put_contents($dir.'/bad/theme.yaml', "name: [unclosed\n");
        file_put_contents($dir.'/default/stylesheets/application.css', '');
        try {
            $registry = new ThemeRegistry($dir.'/none', [$dir]);
            self::assertArrayHasKey('bad', $registry->errors());
            self::assertSame(['default'], array_keys($registry->all()), '"default" is the built-in theme, a directory of that name is ignored');
            self::assertSame('', $registry->get('default')->path);
        } finally {
            unlink($dir.'/bad/theme.yaml');
            unlink($dir.'/default/stylesheets/application.css');
            rmdir($dir.'/bad');
            rmdir($dir.'/default/stylesheets');
            rmdir($dir.'/default');
            rmdir($dir);
        }
    }

    public function testReadThrowsWithAllErrors(): void
    {
        try {
            ThemeManifest::read('fx_broken', self::FIXTURES.'/fx_broken');
            self::fail('expected an InvalidThemeException');
        } catch (InvalidThemeException $e) {
            self::assertSame('fx_broken', $e->themeId);
            self::assertGreaterThanOrEqual(7, \count($e->errors));
        }
    }

    public function testShippedThemesAreValid(): void
    {
        $registry = new ThemeRegistry(\dirname(__DIR__, 2));
        self::assertSame([], $registry->errors());
        self::assertSame(['default', 'alternate', 'classic', 'strata'], array_keys($registry->all()));
        $strata = $registry->get('strata');
        self::assertFalse($strata->legacy);
        self::assertTrue($strata->hasColorSchemes());
        foreach ($strata->stylesheets as $sheet) {
            self::assertFileExists($strata->path.'/'.$sheet['file']);
        }
        self::assertCount(1, $strata->templateDirectories());
        self::assertTrue($registry->get('classic')->legacy);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Theme;

use App\Service\Settings;
use App\Tests\Admin\AdminWebTestCase;
use App\Theme\ThemeRegistry;
use App\Theme\ThemeSettings;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The theme system end to end, with the fixture themes of tests/fixtures/themes:
 * fx_redmine (Redmine theme), fx_base (theme.yaml with every feature), fx_child (extends fx_base).
 */
final class ThemesWebTest extends AdminWebTestCase
{
    private const array CORE_STYLESHEETS = [
        '/assets/jquery/jquery-ui-1.13.2.css', '/assets/tribute-5.1.3.css', '/assets/application.css',
        '/assets/dropdown.css', '/assets/jstoolbar.css', '/assets/responsive.css',
    ];

    private function themes(): ThemeSettings
    {
        return self::getContainer()->get(ThemeSettings::class);
    }

    private function useTheme(string $id): void
    {
        $theme = self::getContainer()->get(ThemeRegistry::class)->get($id);
        self::assertNotNull($theme, "theme $id");
        $this->themes()->setSiteTheme($theme);
    }

    private function html(string $url = '/'): string
    {
        $this->client->request('GET', $url);
        $this->assertStatus(200);

        return (string) $this->client->getResponse()->getContent();
    }

    /** @return list<string> href of the stylesheets, without the version */
    private function stylesheets(string $html): array
    {
        preg_match_all('/<link rel="stylesheet" href="([^"]+)"/', $html, $m);

        // plugins add their own stylesheets through the layout hooks
        $hrefs = array_values(array_filter($m[1], static fn (string $href) => !str_starts_with($href, '/plugin_assets/')));

        return array_map(static fn (string $href) => preg_replace('/\?v=[a-z0-9]+$/', '', html_entity_decode($href)), $hrefs);
    }

    private function htmlTag(string $html): string
    {
        preg_match('/<html[^>]*>/', $html, $m);

        return $m[0];
    }

    private function body(string $url): string
    {
        $this->client->request('GET', $url);
        $response = $this->client->getResponse();
        $this->assertStatus(200, $url);

        return $response instanceof BinaryFileResponse ? (string) file_get_contents($response->getFile()->getPathname()) : (string) $response->getContent();
    }

    // --- the default theme ----------------------------------------------------------------------

    public function testDefaultThemeKeepsRedmineMarkup(): void
    {
        $this->loginAs('admin');
        $html = $this->html('/');
        self::assertSame('<html lang="en" dir="ltr">', $this->htmlTag($html));
        self::assertSame(self::CORE_STYLESHEETS, $this->stylesheets($html));
        self::assertStringNotContainsString('appearance.js', $html);
        self::assertStringNotContainsString('appearance-switcher', $html);
        self::assertStringNotContainsString('/themes/', $html);
        self::assertStringContainsString('href="/assets/favicon.ico"', $html);
        self::assertDoesNotMatchRegularExpression('/<body class="theme-/', $html);
        self::assertStringContainsString('class="my-appearance"', $html, 'the one addition: users can pick a theme, under their account menu');

        // with nothing to choose (administrators turned personal themes off), the menu is Redmine's again
        $this->themes()->setUserPermissions(false, true);
        self::assertStringNotContainsString('my-appearance', $this->html('/'));
    }

    // --- Redmine themes -------------------------------------------------------------------------

    public function testRedmineThemeReplacesTheCoreStylesheetsOfTheSameName(): void
    {
        $this->useTheme('fx_redmine');
        $html = $this->html('/');
        self::assertSame('<html lang="en" dir="ltr">', $this->htmlTag($html), 'no theme attributes for Redmine themes');
        self::assertSame([
            '/assets/jquery/jquery-ui-1.13.2.css', '/assets/tribute-5.1.3.css', '/themes/fx_redmine/stylesheets/application.css',
            '/assets/dropdown.css', '/assets/jstoolbar.css', '/themes/fx_redmine/stylesheets/responsive.css',
        ], $this->stylesheets($html));
        self::assertMatchesRegularExpression('/<body class="theme-Fx_redmine /', $html);
        self::assertMatchesRegularExpression('#<script src="/themes/fx_redmine/javascripts/theme\.js\?v=[a-z0-9]+"></script>#', $html);
        self::assertStringNotContainsString('appearance.js', $html);
    }

    public function testRedmineThemeStylesheetsGetTheirRootUrlsResolved(): void
    {
        $css = $this->body('/themes/fx_redmine/stylesheets/application.css');
        self::assertStringContainsString('@import url(/assets/application.css);', $css);
        self::assertStringContainsString('url(/assets/help.png)', $css);
        self::assertStringContainsString('url(/themes/fx_redmine/pic.png)', $css, 'theme URLs are kept');
        self::assertStringContainsString('url(/no-such-core-file.png)', $css, 'unknown files are left alone');
        self::assertStringContainsString('url("//cdn.example.org/x.png")', $css);

        // Redmine serves the files of a theme flattened
        $this->client->request('GET', '/themes/fx_redmine/pic.png');
        $this->assertStatus(200);
        self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));
    }

    // --- assets ---------------------------------------------------------------------------------

    public function testAssetsAreCacheable(): void
    {
        $this->client->request('GET', '/themes/fx_base/stylesheets/application.css?v=abc');
        $this->assertStatus(200);
        $response = $this->client->getResponse();
        self::assertStringStartsWith('text/css', (string) $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $cache = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('max-age=31536000', $cache);
        self::assertStringContainsString('immutable', $cache);
        self::assertStringContainsString('public', $cache);

        $etag = $response->headers->get('ETag');
        self::assertNotNull($etag);
        $this->client->request('GET', '/themes/fx_base/stylesheets/application.css', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        $this->assertStatus(304);
        self::assertStringNotContainsString('immutable', (string) $this->client->getResponse()->headers->get('Cache-Control'), 'no version in the URL');

        $this->client->request('GET', '/themes/fx_base/images/icons.svg');
        $this->assertStatus(200);
        self::assertStringContainsString('sandbox', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testOnlyAssetFilesAreServed(): void
    {
        foreach ([
            '/themes/fx_base/theme.yaml',
            '/themes/fx_base/templates/base.html.twig',
            '/themes/fx_base/templates/secret.css',
            '/themes/fx_base/stylesheets/../theme.yaml',
            '/themes/fx_base/stylesheets/..%2Ftheme.yaml',
            '/themes/fx_base/stylesheets/%2e%2e/%2e%2e/fx_redmine/stylesheets/application.css',
            '/themes/fx_base/stylesheets/nope.css',
            '/themes/fx_base/.hidden.css',
            '/themes/nope/stylesheets/application.css',
            '/themes/default/stylesheets/application.css',
            '/themes/fx_broken/theme.yaml',
            '/themes/fx_base/uploads/nope.png',
            '/themes/fx_base/uploads/..%2F..%2Fsecret.png',
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $url);
        }
    }

    public function testAssetsNeedNoLogin(): void
    {
        self::getContainer()->get(Settings::class)->setMany(['login_required' => '1']);
        $this->useTheme('fx_base');
        $this->client->request('GET', '/themes/fx_base/stylesheets/application.css');
        $this->assertStatus(200);
        $this->client->request('GET', '/themes/fx_base/generated.css');
        $this->assertStatus(200);
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'), 'no session for assets');
    }

    // --- Symine themes --------------------------------------------------------------------------

    public function testSymineThemeMarkup(): void
    {
        $this->useTheme('fx_base');
        $html = $this->html('/');
        self::assertSame(
            '<html lang="en" dir="ltr" data-theme="fx_base" data-color-scheme="auto" data-theme-accent="teal" data-theme-density="comfortable" data-theme-wide="off">',
            $this->htmlTag($html),
        );
        self::assertSame(
            [...self::CORE_STYLESHEETS, '/assets/appearance.css', '/themes/fx_base/stylesheets/application.css', '/themes/fx_base/generated.css'],
            $this->stylesheets($html),
        );
        self::assertMatchesRegularExpression('#<script src="/themes/fx_base/javascripts/theme\.js\?v=[a-z0-9]+"></script>#', $html);
        self::assertStringContainsString('<script src="/assets/appearance.js" defer></script>', $html);
        self::assertMatchesRegularExpression('#<link rel="shortcut icon" type="image/x-icon" href="/themes/fx_base/favicon/favicon\.ico\?v=[a-z0-9]+" />#', $html);
        self::assertMatchesRegularExpression('/<body class="theme-Fixture_base fx /', $html);
        self::assertStringContainsString('id="appearance-switcher"', $html, 'the theme has quick options');
        self::assertStringContainsString('data-appearance-option="color_scheme"', $html);
        self::assertStringContainsString('data-appearance-option="density"', $html);
        self::assertStringNotContainsString('data-appearance-option="accent"', $html, 'not a quick option');
    }

    public function testGeneratedStylesheet(): void
    {
        $css = $this->body('/themes/fx_base/generated.css');
        $root = ':root[data-theme="fx_base"]';
        foreach ([
            $root.'{--fx-gap:12px;--oc-gray-4:#445566}',
            $root.'[data-color-scheme="light"]{color-scheme:light;--fx-paper:#ffffff}',
            '@media (prefers-color-scheme: light){'.$root.'[data-color-scheme="auto"]{color-scheme:light;--fx-paper:#ffffff}}',
            $root.'[data-color-scheme="dark"]{color-scheme:dark;--fx-paper:#101010}',
            '@media (prefers-color-scheme: dark){'.$root.'[data-color-scheme="auto"]{color-scheme:dark;--fx-paper:#101010}}',
            $root.'[data-theme-accent="teal"]{--fx-accent:#0e7566}',
            $root.'[data-color-scheme="dark"][data-theme-accent="teal"]{--fx-accent:#5fd4bd}',
            '@media (prefers-color-scheme: dark){'.$root.'[data-color-scheme="auto"][data-theme-accent="teal"]{--fx-accent:#5fd4bd}}',
            $root.'[data-theme-accent="blue"]{--fx-accent:#2456c9}',
            $root.'[data-theme-density="compact"]{--fx-gap:4px}',
            $root.'[data-color-scheme="dark"][data-theme-density="compact"]{--fx-paper:#000000}',
            $root.'[data-theme-wide="on"]{--fx-max:none}',
            $root.'[data-theme]{--fx-radius:4px}',
            $root.'[data-theme]{--fx-columns:2}',
        ] as $rule) {
            self::assertStringContainsString($rule, $css);
        }
        self::assertStringNotContainsString('--fx-tagline', $css, 'empty text options write nothing');
        self::assertStringNotContainsString('--fx-logo', $css);
        self::assertStringNotContainsString('data-theme-accent="custom"', $css, 'the site uses a preset');

        // the child theme: its own id, merged tokens, the removed option gone
        $child = $this->body('/themes/fx_child/generated.css');
        self::assertStringContainsString(':root[data-theme="fx_child"]{--fx-gap:20px;--fx-extra:1;--oc-gray-4:#445566}', $child);
        self::assertStringContainsString('[data-color-scheme="dark"]{color-scheme:dark;--fx-paper:#222222}', $child);
        self::assertStringNotContainsString('data-theme-wide', $child);
        self::assertStringContainsString('--fx-tagline:"From the child"', $child);
    }

    public function testTemplatesOfTheThemeReplaceTheApplicationTemplates(): void
    {
        $html = $this->html('/');
        self::assertStringNotContainsString('Fixture footer', $html);

        $this->useTheme('fx_base');
        $html = $this->html('/');
        self::assertStringContainsString('<meta name="fx-theme" content="fx_base" />', $html);
        self::assertStringContainsString('<div id="footer" class="fx-footer">Fixture footer:  / comfortable<span class="fx-partial">base partial</span>', $html);
        self::assertStringNotContainsString('Powered by', $html, 'the footer block is replaced');
        self::assertStringContainsString('<nav class="top-menu" id="top-menu">', $html, 'the rest of "@Symine/base.html.twig" is kept');
        self::assertStringContainsString('<div id="main-menu"', $html);

        // a child theme: its templates first, then the ones of its parent
        $this->useTheme('fx_child');
        $html = $this->html('/');
        self::assertStringContainsString('<meta name="fx-theme" content="fx_child" />', $html);
        self::assertStringContainsString('Fixture footer: From the child / comfortable<span class="fx-partial">child partial</span>', $html);

        $this->useTheme('default');
        self::assertStringContainsString('Powered by', $this->html('/'));
    }

    public function testIconsOfTheThemeSpriteOverrideTheCoreOnes(): void
    {
        $this->useTheme('fx_base');
        $this->loginAs('admin');
        $html = $this->html('/admin');
        self::assertMatchesRegularExpression('#<use href="/themes/fx_base/images/icons\.svg\?v=[a-z0-9]+\#icon--help">#', $html);
        self::assertStringContainsString('<use href="/assets/icons.svg#icon--settings">', $html, 'icons the theme does not define come from the core sprite');
    }

    public function testStrataRendersItsOwnLayout(): void
    {
        $this->useTheme('strata');
        $this->loginAs('jsmith');
        $html = $this->html('/projects/ecommerce/issues');
        self::assertStringContainsString('data-theme="strata"', $html);
        self::assertStringContainsString('data-theme-navigation="rail"', $html);
        self::assertStringContainsString('class="st-brand"', $html);
        self::assertStringContainsString('<span class="current-project">eCommerce</span>', $html);
        self::assertStringContainsString('<div id="quick-search">', $html);
        self::assertStringContainsString('<div id="main-menu" class="tabs">', $html);
        self::assertStringContainsString('class="st-jump-label"', $html, 'the partial of the theme is used');
        self::assertStringContainsString('/themes/strata/stylesheets/tokens.css', $html);
        foreach (['/login', '/projects', '/my/page', '/issues/13', '/projects/ecommerce/wiki', '/my/appearance'] as $url) {
            $this->client->request('GET', $url);
            self::assertContains($this->client->getResponse()->getStatusCode(), [200, 302], $url);
        }
        $css = $this->body('/themes/strata/generated.css');
        self::assertStringContainsString('[data-theme-accent="malachite"]{--st-accent-source:#0e7566}', $css);
    }

    // --- administration -------------------------------------------------------------------------

    public function testAdministrationIsForAdministrators(): void
    {
        $this->client->request('GET', '/admin/themes');
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401]);
        $this->loginAs('jsmith');
        foreach (['/admin/themes', '/admin/themes/fx_base'] as $url) {
            $this->client->request('GET', $url);
            $this->assertStatus(403, $url);
        }
        $this->post('/admin/themes/fx_base/activate');
        $this->assertStatus(403);
        self::assertTrue($this->themes()->siteTheme()->isDefault());
    }

    public function testIndexListsThemesAndTheirErrors(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/admin/themes');
        $this->assertStatus(200);
        foreach (['default', 'classic', 'alternate', 'strata', 'fx_base', 'fx_child', 'fx_redmine'] as $id) {
            self::assertCount(1, $crawler->filter('#theme-'.$id), $id);
        }
        self::assertCount(1, $crawler->filter('#theme-default.theme-card-active'));
        self::assertCount(0, $crawler->filter('#theme-fx_broken'));
        $errors = $crawler->filter('#theme-errors')->text();
        self::assertStringContainsString('fx_broken', $errors);
        self::assertStringContainsString('unknown key "colour"', $errors);
        self::assertStringContainsString('fx_cycle_a', $errors);
        self::assertCount(1, $crawler->filter('#admin-menu a.themes.selected'));
    }

    public function testActivate(): void
    {
        $this->loginAs('admin');
        $this->post('/admin/themes/fx_base/activate');
        $this->assertRedirectTo('/admin/themes');
        $this->em->clear();
        self::assertSame('fx_base', self::getContainer()->get(Settings::class)->string('ui_theme'));
        self::assertCount(1, $this->get('/admin/themes')->filter('#theme-fx_base.theme-card-active'));

        $this->post('/admin/themes/default/activate');
        self::assertSame('', self::getContainer()->get(Settings::class)->string('ui_theme'), 'Redmine stores the default theme as an empty string');

        $this->post('/admin/themes/fx_broken/activate');
        $this->assertStatus(404);
    }

    public function testPreviewIsOnlyForTheAdministratorWhoStartedIt(): void
    {
        $this->loginAs('admin');
        $this->post('/admin/themes/fx_child/preview');
        $this->assertRedirectTo('/');
        $html = $this->html('/');
        self::assertStringContainsString('data-theme="fx_child"', $html);
        self::assertStringContainsString('id="theme-preview-bar"', $html);
        self::assertTrue($this->themes()->siteTheme()->isDefault(), 'the site theme is unchanged');

        $this->post('/admin/themes/preview');
        $this->assertRedirectTo('/admin/themes');
        $html = $this->html('/');
        self::assertStringNotContainsString('data-theme=', $html);
        self::assertStringNotContainsString('theme-preview-bar', $html);
    }

    public function testCustomize(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/admin/themes/fx_base');
        $this->assertStatus(200);
        self::assertStringContainsString('data-theme="fx_base"', (string) $this->client->getResponse()->getContent(), 'shown with the theme being customised');
        self::assertCount(1, $crawler->filter('input[name="options[density]"][value="comfortable"][checked]'));
        self::assertCount(1, $crawler->filter('input[name="options[radius]"][value="4"]'));
        self::assertCount(1, $crawler->filter('input[type="file"][name="images[logo]"]'));
        self::assertCount(1, $crawler->filter('textarea[name="custom_css"]'));
        self::assertCount(1, $crawler->filter('#theme-not-in-use'));

        $this->post('/admin/themes/fx_base', [
            'options' => ['color_scheme' => 'dark', 'density' => 'compact', 'accent' => 'custom', 'wide' => '1', 'radius' => '8', 'columns' => '3', 'tagline' => 'Dig "deep"'],
            'options_custom' => ['accent' => '#123456'],
            'custom_css' => "#footer { color: red; }\r\n",
        ]);
        $this->assertRedirectTo('/admin/themes/fx_base');
        $theme = self::getContainer()->get(ThemeRegistry::class)->get('fx_base');
        self::assertSame(
            ['color_scheme' => 'dark', 'accent' => '#123456', 'density' => 'compact', 'wide' => '1', 'radius' => '8px', 'columns' => '3', 'tagline' => 'Dig "deep"', 'logo' => ''],
            $this->themes()->siteOptions($theme),
        );

        $this->useTheme('fx_base');
        $html = $this->html('/');
        self::assertStringContainsString('data-color-scheme="dark" data-theme-accent="custom" data-theme-density="compact" data-theme-wide="on">', $html);
        self::assertStringContainsString('Fixture footer: Dig &quot;deep&quot; / compact', $html, 'theme_option() in templates');
        $css = $this->body('/themes/fx_base/generated.css');
        self::assertStringContainsString(':root[data-theme="fx_base"][data-theme][data-theme-accent="custom"]{--fx-accent:#123456}', $css);
        self::assertStringContainsString('{--fx-radius:8px}', $css);
        self::assertStringContainsString('{--fx-columns:3}', $css);
        self::assertStringContainsString('{--fx-tagline:"Dig \22 deep\22 "}', $css);
        self::assertStringContainsString("/* Custom CSS */\n#footer { color: red; }", $css);

        // the URL of the generated stylesheet changes with the settings
        preg_match('#/themes/fx_base/generated\.css\?v=([a-z0-9]+)#', $html, $before);
        $this->post('/admin/themes/fx_base', ['options' => ['radius' => '2']]);
        preg_match('#/themes/fx_base/generated\.css\?v=([a-z0-9]+)#', $this->html('/'), $after);
        self::assertNotSame($before[1], $after[1]);
        self::assertSame('compact', $this->themes()->siteOptions($theme)['density'], 'options left out of the form keep their value');

        // reset
        $this->post('/admin/themes/fx_base', ['reset' => '1']);
        $this->assertRedirectTo('/admin/themes/fx_base');
        self::assertSame($theme->defaults(), $this->themes()->siteOptions($theme));
        self::assertSame('', $this->themes()->customCss($theme));
    }

    public function testInvalidValuesAreRejected(): void
    {
        $this->loginAs('admin');
        $theme = self::getContainer()->get(ThemeRegistry::class)->get('fx_base');
        $response = $this->post('/admin/themes/fx_base', [
            'options' => ['density' => 'cosy', 'radius' => '99', 'accent' => 'custom', 'columns' => '2'],
            'options_custom' => ['accent' => 'red; } body { display: none'],
        ]);
        $this->assertStatus(422);
        $html = (string) $response->getContent();
        self::assertStringContainsString('&quot;cosy&quot; cannot be used here.', $html);
        self::assertStringContainsString('&quot;99&quot; cannot be used here.', $html);
        self::assertSame($theme->defaults(), $this->themes()->siteOptions($theme));
        self::assertStringNotContainsString('display: none', $this->body('/themes/fx_base/generated.css'));
    }

    public function testStoredValuesAreValidatedAgainWhenRead(): void
    {
        // what a previous version of the theme, or anything else, may have left in the setting
        self::getContainer()->get(Settings::class)->set(ThemeSettings::SETTING, ['themes' => ['fx_base' => [
            'options' => ['density' => 'gone', 'radius' => '4px; } * { display: none', 'accent' => 'blue', 'unknown' => 'x'],
        ]]]);
        $this->em->flush();
        $theme = self::getContainer()->get(ThemeRegistry::class)->get('fx_base');
        $options = $this->themes()->siteOptions($theme);
        self::assertSame('comfortable', $options['density']);
        self::assertSame('4px', $options['radius']);
        self::assertSame('blue', $options['accent']);
        self::assertArrayNotHasKey('unknown', $options);
        self::assertStringNotContainsString('display: none', $this->body('/themes/fx_base/generated.css'));
    }

    public function testImageOption(): void
    {
        $this->loginAs('admin');
        $theme = self::getContainer()->get(ThemeRegistry::class)->get('fx_base');
        $png = tempnam(sys_get_temp_dir(), 'fx').'.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $token = $this->csrfToken();
        $this->client->request('POST', '/admin/themes/fx_base', ['_token' => $token], ['images' => ['logo' => new UploadedFile($png, 'Our Logo.png', 'image/png', null, true)]]);
        $this->assertRedirectTo('/admin/themes/fx_base');
        $name = $this->themes()->siteOptions($theme)['logo'];
        self::assertMatchesRegularExpression('/^logo-[0-9a-f]{12}\.png$/', $name);
        self::assertStringContainsString('{--fx-logo:url("/themes/fx_base/uploads/'.$name.'")}', $this->body('/themes/fx_base/generated.css'));
        $this->client->request('GET', '/themes/fx_base/uploads/'.$name);
        $this->assertStatus(200);
        self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertCount(1, $this->get('/admin/themes/fx_base')->filter('.appearance-image img[src="/themes/fx_base/uploads/'.$name.'"]'));

        // not a picture
        $fake = tempnam(sys_get_temp_dir(), 'fx').'.png';
        file_put_contents($fake, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->client->request('POST', '/admin/themes/fx_base', ['_token' => $token], ['images' => ['logo' => new UploadedFile($fake, 'fake.png', 'image/png', null, true)]]);
        $this->assertStatus(422);
        self::assertSame($name, $this->themes()->siteOptions($theme)['logo']);

        $this->post('/admin/themes/fx_base', ['remove_image' => ['logo' => '1']]);
        self::assertSame('', $this->themes()->siteOptions($theme)['logo']);
        self::assertStringNotContainsString('--fx-logo', $this->body('/themes/fx_base/generated.css'));

        $this->themes()->resetSite($theme);
        self::assertSame([], glob($this->themes()->uploadDirectory('fx_base').'/*') ?: []);
        @unlink($png);
        @unlink($fake);
    }

    public function testUserPermissions(): void
    {
        self::assertTrue($this->themes()->usersMayCustomise(), 'personal options are allowed by default');
        self::assertTrue($this->themes()->usersMayChooseTheme(), 'and so is a personal theme');
        $this->loginAs('admin');
        $crawler = $this->get('/admin/themes');
        self::assertCount(1, $crawler->filter('#user_theme[checked]'));
        self::assertCount(1, $crawler->filter('#user_options[checked]'));

        $this->post('/admin/themes/permissions', ['user_options' => '1']);
        $this->assertRedirectTo('/admin/themes');
        self::assertTrue($this->themes()->usersMayCustomise());
        self::assertFalse($this->themes()->usersMayChooseTheme());
        $crawler = $this->get('/admin/themes');
        self::assertCount(0, $crawler->filter('#user_theme[checked]'));
        self::assertCount(1, $crawler->filter('#user_options[checked]'));
    }

    // --- personal appearance --------------------------------------------------------------------

    public function testPersonalOptions(): void
    {
        $this->useTheme('fx_base');
        $this->loginAs('jsmith');
        $crawler = $this->get('/my/appearance');
        $this->assertStatus(200);
        self::assertCount(1, $crawler->filter('input[name="options[density]"][value="comfortable"][checked]'));
        self::assertCount(0, $crawler->filter('input[name="options[radius]"]'), 'only the options open to users');
        self::assertCount(1, $crawler->filter('#appearance-form select[name="theme"]'), 'a personal theme can be picked by default');
        self::assertCount(1, $crawler->filter('#account a.my-appearance'), 'entry in the account menu');

        $this->post('/my/appearance', [
            'options_theme' => 'fx_base',
            'options' => ['color_scheme' => 'dark', 'density' => 'compact', 'accent' => 'custom', 'wide' => '1'],
            'options_custom' => ['accent' => '#AA0000'],
        ]);
        $this->assertRedirectTo('/my/appearance');
        $this->em->clear();
        self::assertEquals(
            ['fx_base' => ['color_scheme' => 'dark', 'density' => 'compact', 'accent' => '#aa0000', 'wide' => '1']],
            $this->user('jsmith')->getPref()->get(ThemeSettings::PREF_OPTIONS),
        );
        self::assertSame(
            '<html lang="en" dir="ltr" data-theme="fx_base" data-color-scheme="dark" data-theme-accent="custom" data-theme-density="compact" data-theme-wide="on" style="--fx-accent:#aa0000">',
            $this->htmlTag($this->html('/')),
        );

        // choosing what the site uses goes back to following the site
        $this->post('/my/appearance', ['options_theme' => 'fx_base', 'options' => ['color_scheme' => 'auto', 'density' => 'comfortable', 'accent' => 'blue', 'wide' => '0']]);
        $this->em->clear();
        self::assertSame(['fx_base' => ['accent' => 'blue']], $this->user('jsmith')->getPref()->get(ThemeSettings::PREF_OPTIONS));

        // other people are not affected
        $this->loginAs('admin');
        self::assertStringContainsString('data-theme-accent="teal" data-theme-density="comfortable"', $this->htmlTag($this->html('/')));

        // an option of another theme than the one in use is not saved
        $this->loginAs('jsmith');
        $this->post('/my/appearance', ['options_theme' => 'fx_child', 'options' => ['density' => 'compact']]);
        $this->em->clear();
        self::assertSame(['fx_base' => ['accent' => 'blue']], $this->user('jsmith')->getPref()->get(ThemeSettings::PREF_OPTIONS));

        // invalid values
        $this->post('/my/appearance', ['options_theme' => 'fx_base', 'options' => ['density' => 'cosy', 'radius' => '9']]);
        $this->assertStatus(422);

        // administrators withdraw the permission: personal values no longer apply
        $this->themes()->setUserPermissions(false, false);
        self::assertStringContainsString('data-theme-accent="teal"', $this->htmlTag($this->html('/')));
        self::assertStringNotContainsString('appearance-switcher', $this->html('/'));
        $this->client->request('GET', '/my/appearance');
        $this->assertStatus(403);
    }

    public function testQuickSwitcher(): void
    {
        $this->useTheme('fx_base');
        $this->loginAs('jsmith');
        $response = $this->post('/appearance', ['option' => 'color_scheme', 'value' => 'dark']);
        $this->assertStatus(200);
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('fx_base', $data['theme']);
        self::assertSame('dark', $data['options']['color_scheme']);
        self::assertStringContainsString('data-color-scheme="dark"', $this->htmlTag($this->html('/')));
        self::assertStringContainsString('data-appearance-value="dark" data-appearance-attribute-value="dark" aria-pressed="true"', $this->html('/'));

        // an empty value goes back to the site setting
        $this->post('/appearance', ['option' => 'color_scheme', 'value' => '']);
        self::assertStringContainsString('data-color-scheme="auto"', $this->htmlTag($this->html('/')));

        foreach ([['option' => 'nope', 'value' => 'x'], ['option' => 'density', 'value' => 'cosy'], ['option' => 'radius', 'value' => '2px']] as $params) {
            $this->post('/appearance', $params);
            $this->assertStatus(422, json_encode($params));
        }

        // the request must carry the CSRF token
        $this->client->request('POST', '/appearance', ['option' => 'color_scheme', 'value' => 'dark']);
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('data-color-scheme="auto"', $this->htmlTag($this->html('/')));
    }

    public function testVisitorsKeepTheirChoiceInACookie(): void
    {
        $this->useTheme('fx_base');
        $this->post('/appearance', ['option' => 'density', 'value' => 'compact']);
        $this->assertStatus(200);
        $cookie = $this->client->getCookieJar()->get('symine_appearance');
        self::assertNotNull($cookie);
        self::assertSame(['fx_base' => ['density' => 'compact']], json_decode($cookie->getValue(), true));
        self::assertTrue($cookie->isHttpOnly());
        self::assertStringContainsString('data-theme-density="compact"', $this->htmlTag($this->html('/')));

        // a forged cookie cannot inject anything
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('symine_appearance', json_encode(['fx_base' => ['density' => '"><script>', 'accent' => 'red;}*{x:y', 'radius' => '1px']]), null, '/', $cookie->getDomain()));
        $tag = $this->htmlTag($this->html('/'));
        self::assertStringContainsString('data-theme-accent="teal" data-theme-density="comfortable"', $tag);
        self::assertStringNotContainsString('style=', $tag);

        // visitors must log in first when the application requires it
        $token = $this->csrfToken();
        self::getContainer()->get(Settings::class)->setMany(['login_required' => '1']);
        $this->client->request('POST', '/appearance', ['option' => 'density', 'value' => 'compact', '_token' => $token]);
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403]);
    }

    public function testPersonalTheme(): void
    {
        $this->loginAs('jsmith');
        // administrators can turn it off: a submitted theme is then ignored
        $this->themes()->setUserPermissions(false, true);
        $this->post('/my/appearance', ['theme' => 'fx_base']);
        self::assertStringNotContainsString('data-theme=', $this->htmlTag($this->html('/')));

        $this->themes()->setUserPermissions(true, true);
        $crawler = $this->get('/my/appearance');
        self::assertCount(1, $crawler->filter('select[name="theme"]'));
        self::assertGreaterThanOrEqual(5, $crawler->filter('select[name="theme"] option')->count());
        self::assertCount(1, $crawler->filter('#account a.my-appearance'));

        $this->post('/my/appearance', ['theme' => 'fx_base']);
        $this->assertRedirectTo('/my/appearance');
        $html = $this->html('/');
        self::assertStringContainsString('data-theme="fx_base"', $this->htmlTag($html));
        self::assertTrue($this->themes()->siteTheme()->isDefault());
        // the switcher of the top menu offers the themes too, with the chosen one selected
        self::assertMatchesRegularExpression('#<select name="theme" id="appearance-quick-theme"[^>]*>.*<option value="fx_base" selected>Fixture base</option>#s', $html);

        $this->post('/my/appearance', ['theme' => 'nope']);
        $this->assertStatus(404);

        $this->loginAs('admin');
        self::assertStringNotContainsString('data-theme=', $this->htmlTag($this->html('/')), 'everybody else keeps the site theme');

        $this->loginAs('jsmith');
        $this->post('/my/appearance', ['theme' => '']);
        self::assertStringNotContainsString('data-theme=', $this->htmlTag($this->html('/')));
    }

    public function testDisplaySettingsListTheInstalledThemes(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/settings?tab=display');
        $this->assertStatus(200);
        $options = $crawler->filter('#settings_ui_theme option')->each(static fn ($o) => $o->attr('value'));
        self::assertSame('', $options[0], 'the default theme is the blank option, as in Redmine');
        foreach (['alternate', 'classic', 'strata', 'fx_base', 'fx_redmine'] as $id) {
            self::assertContains($id, $options);
        }
        self::assertNotContains('fx_broken', $options);
        self::assertNotContains('default', $options);

        // Redmine's own setting keeps working
        $this->post('/settings/edit?tab=display', ['settings' => ['ui_theme' => 'classic']]);
        self::assertSame('classic', $this->themes()->siteTheme()->id);
        self::assertMatchesRegularExpression('/<body class="theme-Classic /', $this->html('/'));
    }

    public function testEveryPageOfTheApplicationRendersWithEachTheme(): void
    {
        $this->loginAs('admin');
        foreach (['fx_redmine', 'fx_child', 'classic', 'alternate', 'strata'] as $id) {
            $this->useTheme($id);
            foreach (['/', '/projects', '/projects/ecommerce', '/projects/ecommerce/issues', '/issues/13', '/admin', '/settings', '/admin/themes', '/admin/themes/'.$id, '/my/account', '/my/appearance'] as $url) {
                $this->client->request('GET', $url);
                $this->assertStatus(200, "$id $url");
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Theme\Http;

use App\Theme\Theme;
use App\Theme\ThemeCssCompiler;
use App\Theme\ThemeManifest;
use App\Theme\ThemeOption;
use App\Theme\ThemeSettings;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds the HTTP responses for the files of the themes, served straight from their directories so a theme is a
 * single folder to drop in and nothing has to be published.
 *
 * Only asset files are served: never theme.yaml, the templates/ and src/ directories or dot files.
 * URLs carrying a version (?v=) never change and are cached forever.
 */
final class ThemeAssetResponder
{
    private const array MIME_TYPES = [
        'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
        'map' => 'application/json', 'json' => 'application/json',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'avif' => 'image/avif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
    ];

    public function __construct(
        private readonly ThemeSettings $settings,
        private readonly ThemeCssCompiler $compiler,
        private readonly string $projectDir,
    ) {
    }

    /** The stylesheet generated from the manifest of the theme and the appearance settings */
    public function generatedStylesheet(Request $request, Theme $theme): Response
    {
        $this->mustBeServable($theme);
        $response = $this->stylesheetResponse();
        $response->setEtag($this->settings->siteVersion($theme));
        $this->cache($request, $response);
        if (!$response->isNotModified($request)) {
            $response->setContent($this->compiler->compile($theme));
        }

        return $response;
    }

    /** A picture uploaded for an image option of the theme */
    public function upload(Request $request, Theme $theme, string $file): Response
    {
        $this->mustBeServable($theme);
        $extension = pathinfo($file, \PATHINFO_EXTENSION);
        $path = $this->settings->uploadDirectory($theme->id).'/'.$file;
        if (!\in_array($extension, ThemeOption::IMAGE_EXTENSIONS, true) || !is_file($path)) {
            throw new NotFoundHttpException();
        }

        return $this->fileResponse($request, $path, $extension, true);
    }

    /** A file of the theme directory */
    public function asset(Request $request, Theme $theme, string $path): Response
    {
        $this->mustBeServable($theme);
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        if (!isset(self::MIME_TYPES[$extension])) {
            throw new NotFoundHttpException();
        }
        $file = ThemeManifest::isAssetPath($path, $theme->path) ? $theme->path.'/'.$path : $this->flattened($theme, $path);
        if (null === $file) {
            throw new NotFoundHttpException();
        }
        // defence in depth: the file must really be inside the theme
        $real = realpath($file);
        $root = realpath($theme->path);
        if (false === $real || false === $root || !str_starts_with($real, $root.\DIRECTORY_SEPARATOR)) {
            throw new NotFoundHttpException();
        }

        if ('css' === $extension && $theme->legacy) {
            $response = $this->stylesheetResponse();
            $response->setEtag(hash('xxh128', $real.filemtime($real).filesize($real)));
            $this->cache($request, $response);
            if (!$response->isNotModified($request)) {
                $response->setContent($this->rewriteRedmineUrls((string) file_get_contents($real)));
            }

            return $response;
        }

        return $this->fileResponse($request, $real, $extension, $request->query->has('v'));
    }

    /** The built-in default theme has no directory: there is nothing to serve for it */
    private function mustBeServable(Theme $theme): void
    {
        if ($theme->isDefault()) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * Redmine serves the files of a theme flattened ("/themes/classic/home.png" for images/home.png):
     * a file name without directory is looked up in the asset directories of the theme.
     */
    private function flattened(Theme $theme, string $path): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.@-]*$/', $path)) {
            return null;
        }
        foreach (glob($theme->path.'/*', \GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (!\in_array($name, ThemeManifest::PRIVATE_DIRECTORIES, true) && '.' !== $name[0] && is_file($dir.'/'.$path)) {
                return $dir.'/'.$path;
            }
        }

        return null;
    }

    /**
     * Stylesheets of Redmine themes refer to the core assets from the root ("@import url(/application.css)",
     * "url(/user.png)"), which Redmine's asset pipeline resolves: they are pointed to /assets here.
     */
    private function rewriteRedmineUrls(string $css): string
    {
        return (string) preg_replace_callback('~url\(\s*([\'"]?)(/[^\'")\s]+)\1\s*\)~', function (array $m): string {
            $url = $m[2];
            $file = strtok($url, '?#');
            if (str_starts_with($url, '//') || str_starts_with($url, '/assets/') || str_starts_with($url, '/themes/') || str_starts_with($url, '/plugin_assets/')
                || str_contains($file, '..') || !is_file($this->projectDir.'/public/assets'.$file)) {
                return $m[0];
            }

            return 'url('.$m[1].'/assets'.$url.$m[1].')';
        }, $css);
    }

    private function stylesheetResponse(): Response
    {
        return new Response('', 200, ['Content-Type' => self::MIME_TYPES['css'], 'X-Content-Type-Options' => 'nosniff']);
    }

    private function fileResponse(Request $request, string $path, string $extension, bool $immutable): Response
    {
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => self::MIME_TYPES[$extension], 'X-Content-Type-Options' => 'nosniff'], true, null, true, true);
        if ('svg' === $extension) {
            // an SVG opened on its own must not be able to run scripts in the origin of the application
            $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }
        $this->cache($request, $response, $immutable);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Versioned URLs (?v=) never change; files referred to from stylesheets (fonts, images) have no version
     * and are kept for a while: a month for fonts, an hour for the rest.
     */
    private function cache(Request $request, Response $response, ?bool $immutable = null): void
    {
        $response->setPublic();
        if ($immutable ?? $request->query->has('v')) {
            $response->setMaxAge(31536000);
            $response->headers->addCacheControlDirective('immutable');

            return;
        }
        $extension = strtolower(pathinfo($request->getPathInfo(), \PATHINFO_EXTENSION));
        $response->setMaxAge(\in_array($extension, ['woff', 'woff2', 'ttf', 'otf'], true) ? 2592000 : 3600);
    }
}

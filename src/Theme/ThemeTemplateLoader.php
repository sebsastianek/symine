<?php

declare(strict_types=1);

namespace App\Theme;

use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Lets themes override templates: templates/<name> of the active theme (then of its parents) is used
 * instead of the application template of the same name.
 *
 * The original template stays reachable as "@Symine/<name>", so an override can extend it and only
 * redefine some blocks. E-mail templates (mailer/) follow the site theme, never the personal theme of
 * whoever triggered the notification.
 */
final class ThemeTemplateLoader implements LoaderInterface
{
    /** @var array<string, string|false> "<theme id>\0<name>" => file */
    private array $found = [];

    public function __construct(private readonly ThemeContext $context)
    {
    }

    public function getSourceContext(string $name): Source
    {
        $file = $this->find($name);
        if (null === $file) {
            throw new LoaderError(\sprintf('Template "%s" is not overridden by the theme.', $name));
        }

        return new Source((string) file_get_contents($file), $name, $file);
    }

    public function getCacheKey(string $name): string
    {
        $file = $this->find($name);
        if (null === $file) {
            throw new LoaderError(\sprintf('Template "%s" is not overridden by the theme.', $name));
        }

        return 'theme:'.$file;
    }

    public function isFresh(string $name, int $time): bool
    {
        $file = $this->find($name);

        return null !== $file && filemtime($file) < $time;
    }

    public function exists(string $name): bool
    {
        return null !== $this->find($name);
    }

    private function find(string $name): ?string
    {
        // namespaced ("@Symine/...") and unsafe names are left to the other loaders
        if ('' === $name || '@' === $name[0] || str_contains($name, '..') || str_contains($name, "\0") || '/' === $name[0]) {
            return null;
        }
        try {
            $theme = str_starts_with($name, 'mailer/') ? $this->context->siteTheme() : $this->context->theme();
        } catch (\Throwable) {
            return null;
        }
        if ($theme->isDefault() || $theme->legacy) {
            return null;
        }
        $key = $theme->id."\0".$name;
        if (!isset($this->found[$key])) {
            $this->found[$key] = false;
            foreach ($theme->templateDirectories() as $dir) {
                if (is_file($dir.'/'.$name)) {
                    $this->found[$key] = $dir.'/'.$name;
                    break;
                }
            }
        }

        return $this->found[$key] ?: null;
    }
}

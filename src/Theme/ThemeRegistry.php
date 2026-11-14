<?php

declare(strict_types=1);

namespace App\Theme;

use App\Plugin\PluginRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The installed themes (Redmine::Themes): every directory of themes/, of the configured extra paths and of
 * the themes/ directory of plugins that holds a theme.yaml or a stylesheets/application.css.
 *
 * A broken theme never breaks the application: it is left out and reported by errors().
 */
final class ThemeRegistry implements ResetInterface
{
    private const string CACHE_KEY = 'symine.themes';

    /** @var array<string, Theme>|null */
    private ?array $themes = null;
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @param list<string> $themePaths extra directories containing themes */
    public function __construct(
        private readonly string $projectDir,
        private readonly array $themePaths = [],
        private readonly ?PluginRegistry $plugins = null,
        private readonly ?CacheItemPoolInterface $cache = null,
    ) {
    }

    /** @return array<string, Theme> usable themes by id: the default one first, then by name */
    public function all(): array
    {
        $this->load();

        return $this->themes;
    }

    public function get(string $id): ?Theme
    {
        if ('' === $id) {
            $id = Theme::DEFAULT_ID;
        }

        return $this->all()[$id] ?? null;
    }

    public function default(): Theme
    {
        return $this->all()[Theme::DEFAULT_ID];
    }

    /** @return array<string, list<string>> problems of the themes that could not be loaded, by theme id */
    public function errors(): array
    {
        $this->load();

        return $this->errors;
    }

    /** @return list<string> directories scanned for themes; a theme found in an earlier one wins */
    public function paths(): array
    {
        $paths = [$this->projectDir.'/themes', ...$this->themePaths];
        try {
            foreach ($this->plugins?->all() ?? [] as $plugin) {
                $paths[] = $plugin->directory.'/themes';
            }
        } catch (\Throwable) {
            // plugins that cannot be loaded do not prevent the themes from working
        }

        return array_values(array_unique(array_filter($paths, 'is_dir')));
    }

    public function reset(): void
    {
        $this->themes = null;
        $this->errors = [];
    }

    private function load(): void
    {
        if (null !== $this->themes) {
            return;
        }
        $directories = $this->directories();
        $fingerprint = $this->fingerprint($directories);

        $item = null;
        try {
            $item = $this->cache?->getItem(self::CACHE_KEY);
            $cached = $item?->isHit() ? $item->get() : null;
            if (\is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint) {
                $this->themes = $cached['themes'];
                $this->errors = $cached['errors'];

                return;
            }
        } catch (\Throwable) {
            $item = null;
        }

        [$this->themes, $this->errors] = $this->scan($directories);

        if (null !== $item) {
            try {
                $this->cache->save($item->set(['fingerprint' => $fingerprint, 'themes' => $this->themes, 'errors' => $this->errors]));
            } catch (\Throwable) {
            }
        }
    }

    /** @return array<string, string> theme id => directory */
    private function directories(): array
    {
        $directories = [];
        foreach ($this->paths() as $path) {
            foreach (glob($path.'/*', \GLOB_ONLYDIR) ?: [] as $dir) {
                $id = basename($dir);
                if (isset($directories[$id]) || !ThemeManifest::isThemeId($id)) {
                    continue;
                }
                if (is_file($dir.'/'.Theme::MANIFEST) || is_file($dir.'/stylesheets/application.css')) {
                    $directories[$id] = $dir;
                }
            }
        }

        return $directories;
    }

    /** @param array<string, string> $directories */
    private function fingerprint(array $directories): string
    {
        $parts = [];
        foreach ($directories as $id => $dir) {
            $parts[] = $id.':'.$dir;
            foreach ([Theme::MANIFEST, '', 'stylesheets', 'javascripts', 'templates'] as $entry) {
                $parts[] = (string) @filemtime(rtrim($dir.'/'.$entry, '/'));
            }
        }

        return hash('xxh128', implode('|', $parts));
    }

    /**
     * @param array<string, string> $directories
     *
     * @return array{array<string, Theme>, array<string, list<string>>}
     */
    private function scan(array $directories): array
    {
        $errors = [];
        $definitions = [];
        foreach ($directories as $id => $dir) {
            try {
                $definitions[$id] = ThemeManifest::read($id, $dir);
            } catch (InvalidThemeException $e) {
                $errors[$id] = $e->errors;
            }
        }

        $themes = [];
        $merged = [];
        foreach (array_keys($definitions) as $id) {
            try {
                $themes[$id] = ThemeManifest::build($this->resolve($id, $definitions, $merged, $errors, []));
            } catch (InvalidThemeException $e) {
                $errors[$id] ??= $e->errors;
            }
        }
        uasort($themes, static fn (Theme $a, Theme $b) => strcasecmp($a->name, $b->name));

        return [[Theme::DEFAULT_ID => Theme::default()] + $themes, $errors];
    }

    /**
     * Definition of a theme merged with its parents.
     *
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<string, mixed>> $merged
     * @param array<string, list<string>>         $errors
     * @param list<string>                        $seen
     *
     * @return array<string, mixed>
     */
    private function resolve(string $id, array $definitions, array &$merged, array $errors, array $seen): array
    {
        if (isset($merged[$id])) {
            return $merged[$id];
        }
        $def = $definitions[$id];
        $parent = $def['parent'];
        if (null === $parent) {
            return $merged[$id] = $def;
        }
        if (\in_array($parent, [...$seen, $id], true)) {
            throw new InvalidThemeException($id, [\sprintf('parent: circular inheritance (%s)', implode(' > ', [...$seen, $id, $parent]))]);
        }
        if (!isset($definitions[$parent])) {
            throw new InvalidThemeException($id, [isset($errors[$parent]) ? \sprintf('parent: the theme "%s" is invalid', $parent) : \sprintf('parent: the theme "%s" is not installed', $parent)]);
        }
        if ($definitions[$parent]['legacy']) {
            throw new InvalidThemeException($id, [\sprintf('parent: "%s" is a Redmine theme, only themes with a %s can be extended', $parent, Theme::MANIFEST)]);
        }
        try {
            $parentDef = $this->resolve($parent, $definitions, $merged, $errors, [...$seen, $id]);
        } catch (InvalidThemeException $e) {
            throw new InvalidThemeException($id, [\sprintf('parent: the theme "%s" is invalid (%s)', $parent, implode('; ', $e->errors))]);
        }

        return $merged[$id] = ThemeManifest::merge($parentDef, $def);
    }
}

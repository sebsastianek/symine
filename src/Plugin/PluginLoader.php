<?php

declare(strict_types=1);

namespace App\Plugin;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Plugin discovery, used by config/bundles.php (port of Redmine::PluginLoader).
 *
 * A plugin is a composer package of type "symine-plugin" whose composer.json declares its bundle class:
 *
 *     {
 *         "name": "acme/symine-hello",
 *         "type": "symine-plugin",
 *         "autoload": {"psr-4": {"Acme\\Hello\\": "src/"}},
 *         "extra": {"symine": {"bundle": "Acme\\Hello\\HelloPlugin", "env": ["all"]}}
 *     }
 *
 * Discovered packages:
 * - every plugins/<dir>/composer.json (drop-in plugins, like Redmine's plugins/ directory). Their PSR-4
 *   autoload is registered here when composer does not know them, so no `composer dump-autoload` is needed;
 * - packages of type "symine-plugin" installed by composer (vendor/composer/installed.json), e.g. through a
 *   path repository `{"type": "path", "url": "plugins/*"}` in the root composer.json.
 *
 * Set SYMINE_PLUGINS_DISCOVERY=0 to disable discovery (bundles must then be listed in config/bundles.php),
 * SYMINE_PLUGINS_DISABLED=id1,id2 (composer names or directory names) to skip some plugins.
 */
#[Exclude]
final class PluginLoader
{
    public const string PACKAGE_TYPE = 'symine-plugin';

    /** @var array<string, list<string>> registered PSR-4 prefixes => directories */
    private static array $psr4 = [];
    private static bool $autoloaderRegistered = false;

    /**
     * Bundles of the discovered plugins in the config/bundles.php format.
     *
     * @return array<class-string, array<string, bool>>
     */
    public static function bundles(string $projectDir): array
    {
        if ('0' === (string) ($_SERVER['SYMINE_PLUGINS_DISCOVERY'] ?? $_ENV['SYMINE_PLUGINS_DISCOVERY'] ?? getenv('SYMINE_PLUGINS_DISCOVERY'))) {
            return [];
        }
        $disabled = array_filter(array_map('trim', explode(',', (string) ($_SERVER['SYMINE_PLUGINS_DISABLED'] ?? $_ENV['SYMINE_PLUGINS_DISABLED'] ?? getenv('SYMINE_PLUGINS_DISABLED') ?: ''))));
        $bundles = [];
        foreach (self::discover($projectDir) as $plugin) {
            if (\in_array($plugin['name'], $disabled, true) || \in_array(basename($plugin['dir']), $disabled, true)) {
                continue;
            }
            if (!class_exists($plugin['bundle'])) {
                self::registerAutoload($plugin['dir'], $plugin['psr4']);
            }
            if (!class_exists($plugin['bundle'])) {
                throw new \LogicException(\sprintf('Plugin bundle class "%s" of %s not found.', $plugin['bundle'], $plugin['dir']));
            }
            $envs = [];
            foreach ($plugin['env'] as $env) {
                $envs[$env] = true;
            }
            $bundles[$plugin['bundle']] = $envs;
        }

        return $bundles;
    }

    /**
     * @return list<array{name: string, dir: string, bundle: class-string, env: list<string>, psr4: array<string, string|list<string>>}>
     */
    public static function discover(string $projectDir): array
    {
        $found = [];
        foreach (glob($projectDir.'/plugins/*/composer.json') ?: [] as $file) {
            $json = json_decode((string) file_get_contents($file), true);
            if (\is_array($json) && null !== ($p = self::describe($json, \dirname($file)))) {
                $found[$p['name']] = $p;
            }
        }
        $installed = $projectDir.'/vendor/composer/installed.json';
        if (is_file($installed)) {
            $json = json_decode((string) file_get_contents($installed), true);
            foreach ((array) ($json['packages'] ?? $json ?? []) as $package) {
                if (!\is_array($package) || self::PACKAGE_TYPE !== ($package['type'] ?? null) || isset($found[$package['name'] ?? ''])) {
                    continue;
                }
                $dir = realpath($projectDir.'/vendor/composer/'.($package['install-path'] ?? '')) ?: $projectDir.'/vendor/'.($package['name'] ?? '');
                if (null !== ($p = self::describe($package, $dir))) {
                    $found[$p['name']] = $p;
                }
            }
        }

        return array_values($found);
    }

    /** @return array{name: string, dir: string, bundle: class-string, env: list<string>, psr4: array<string, string|list<string>>}|null */
    private static function describe(array $json, string $dir): ?array
    {
        $bundle = $json['extra']['symine']['bundle'] ?? null;
        if (!\is_string($bundle) || '' === $bundle) {
            return null;
        }
        $env = $json['extra']['symine']['env'] ?? ['all'];

        return [
            'name' => (string) ($json['name'] ?? basename($dir)),
            'dir' => $dir,
            'bundle' => $bundle,
            'env' => array_values(array_map('strval', (array) $env)),
            'psr4' => (array) ($json['autoload']['psr-4'] ?? []),
        ];
    }

    /** @param array<string, string|list<string>> $psr4 */
    private static function registerAutoload(string $dir, array $psr4): void
    {
        foreach ($psr4 as $prefix => $paths) {
            foreach ((array) $paths as $path) {
                self::$psr4[$prefix][] = rtrim($dir.'/'.$path, '/');
            }
        }
        if (!self::$autoloaderRegistered) {
            self::$autoloaderRegistered = true;
            spl_autoload_register(static function (string $class): void {
                foreach (self::$psr4 as $prefix => $dirs) {
                    if (!str_starts_with($class, $prefix)) {
                        continue;
                    }
                    $relative = str_replace('\\', '/', substr($class, \strlen($prefix))).'.php';
                    foreach ($dirs as $d) {
                        if (is_file($file = $d.'/'.$relative)) {
                            require $file;

                            return;
                        }
                    }
                }
            });
        }
    }
}

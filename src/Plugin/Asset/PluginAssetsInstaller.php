<?php

declare(strict_types=1);

namespace App\Plugin\Asset;

use App\Plugin\PluginRegistry;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Publishes the plugins' assets/ directories to public/plugin_assets/<id>/ (Redmine::PluginLoader.mirror_assets).
 *
 * Runs as a cache warmer (each time the container is built, like Redmine mirroring the assets at startup)
 * and through `bin/console symine:plugins:assets`. Assets are symlinked (relative link), or copied when
 * symlinks are not available or --copy is used.
 */
final class PluginAssetsInstaller implements CacheWarmerInterface
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly string $projectDir,
    ) {
    }

    public function publicDirectory(): string
    {
        return $this->projectDir.'/public/plugin_assets';
    }

    /**
     * @return array<string, string> plugin id => "symlink" | "copy" | "none"
     */
    public function install(bool $copy = false): array
    {
        $fs = new Filesystem();
        $out = [];
        foreach ($this->plugins->all() as $plugin) {
            $source = $plugin->assetsDirectory();
            $target = $this->publicDirectory().'/'.$plugin->id;
            if (!is_dir($source)) {
                $out[$plugin->id] = 'none';
                continue;
            }
            if (is_link($target) && !$copy && realpath($target) === realpath($source)) {
                $out[$plugin->id] = 'symlink';
                continue;
            }
            $fs->remove($target);
            $fs->mkdir(\dirname($target));
            if (!$copy) {
                try {
                    $fs->symlink($fs->makePathRelative($source, \dirname($target)), $target);
                    if (is_dir($target)) {
                        $out[$plugin->id] = 'symlink';
                        continue;
                    }
                    $fs->remove($target);
                } catch (\Throwable) {
                }
            }
            $fs->mirror($source, $target, null, ['override' => true, 'delete' => true]);
            $out[$plugin->id] = 'copy';
        }

        return $out;
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        try {
            $this->install();
        } catch (\Throwable) {
            // read-only public directory: run symine:plugins:assets at deploy time
        }

        return [];
    }
}

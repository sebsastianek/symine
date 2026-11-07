<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Importmap equivalent of Redmine's config/importmap.rb, built from public/assets. */
final class ImportmapExtension extends AbstractExtension
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('importmap_json', $this->importmap(...), ['is_safe' => ['html']])];
    }

    public function importmap(): string
    {
        $assets = $this->projectDir.'/public/assets';
        $imports = [
            '@rails/request.js' => '/assets/requestjs.js',
            'application' => '/assets/application.js',
            '@hotwired/stimulus' => '/assets/stimulus.min.js',
            '@hotwired/stimulus-loading' => '/assets/stimulus-loading.js',
            'turndown' => '/assets/turndown.js',
            'tablesort' => '/assets/tablesort.min.js',
            'tablesort.number' => '/assets/tablesort.number.min.js',
            'chart.js' => '/assets/chart.min.js',
        ];
        foreach (['controllers', 'wiki_formatting'] as $dir) {
            $base = $assets.'/'.$dir;
            if (!is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ('js' !== $file->getExtension()) {
                    continue;
                }
                $rel = substr($file->getPathname(), \strlen($assets) + 1, -3);
                $name = str_ends_with($rel, '/index') ? substr($rel, 0, -6) : $rel;
                $imports[$name] = '/assets/'.$rel.'.js';
            }
        }
        ksort($imports);

        return json_encode(['imports' => $imports], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }
}

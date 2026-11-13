<?php

declare(strict_types=1);

namespace App\Twig;

use App\Plugin\Hook\Hooks;
use App\Plugin\PluginRegistry;
use App\Service\Admin\FieldFormats;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Plugin API helpers for templates:
 * - call_hook('view_...', {issue: issue}): output of the view hook listeners (Redmine's call_hook helper);
 *   the "project" of the template is added to the context like Redmine's default context
 * - plugin_asset_path('plugin_id', 'stylesheets/x.css'), plugin_stylesheet(), plugin_javascript():
 *   assets published in /plugin_assets/<id>/ (stylesheet_link_tag 'x', :plugin => 'id')
 * - field_format_label(format): label key of a core or plugin custom field format
 */
final class PluginExtension extends AbstractExtension
{
    public function __construct(
        private readonly Hooks $hooks,
        private readonly PluginRegistry $plugins,
        private readonly RequestStack $requests,
        private readonly FieldFormats $fieldFormats,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('call_hook', $this->callHook(...), ['is_safe' => ['html'], 'needs_context' => true]),
            new TwigFunction('plugin_asset_path', $this->assetPath(...)),
            new TwigFunction('plugin_stylesheet', fn (string $plugin, string $file, string $media = 'all') => '<link rel="stylesheet" href="'.htmlspecialchars($this->assetPath($plugin, 'stylesheets/'.self::ext($file, 'css'))).'" media="'.htmlspecialchars($media).'" />', $safe),
            new TwigFunction('plugin_javascript', fn (string $plugin, string $file) => '<script src="'.htmlspecialchars($this->assetPath($plugin, 'javascripts/'.self::ext($file, 'js'))).'"></script>', $safe),
            new TwigFunction('plugin_installed', $this->plugins->installed(...)),
            new TwigFunction('field_format_label', fn (?string $format) => $this->fieldFormats->info((string) $format)['label']),
        ];
    }

    /**
     * @param array<string, mixed> $twigContext
     * @param array<string, mixed> $context
     */
    public function callHook(array $twigContext, string $hook, array $context = []): string
    {
        $context += ['project' => $twigContext['project'] ?? null, 'hook_caller' => 'view'];

        return $this->hooks->render($hook, $context);
    }

    public function assetPath(string $plugin, string $path): string
    {
        $base = $this->requests->getMainRequest()?->getBasePath() ?? '';

        return $base.'/plugin_assets/'.rawurlencode($plugin).'/'.ltrim($path, '/');
    }

    private static function ext(string $file, string $ext): string
    {
        return str_contains(basename($file), '.') ? $file : $file.'.'.$ext;
    }
}

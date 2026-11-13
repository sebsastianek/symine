<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Controller\Admin\AdminController;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A registered plugin - port of a Redmine::Plugin instance and of its registration DSL.
 *
 *     public function register(PluginDefinition $plugin): void
 *     {
 *         $plugin->name('Hello')->author('Jane')->version('1.0.0')->requiresSymine('0.1');
 *         $plugin->settings(['greeting' => 'Hello'], '@HelloPlugin/settings/_hello.html.twig');
 *         $plugin->projectModule('hello', function (PluginDefinition $p): void {
 *             $p->permission('view_hello', ['hello_index'], ['read' => true]);
 *         });
 *         $plugin->menu('project_menu', 'hello', 'hello_index', ['caption' => 'label_hello', 'param' => 'project_id']);
 *     }
 */
#[Exclude]
final class PluginDefinition
{
    /** Version of Symine checked by requiresSymine() */
    public const string SYMINE_VERSION = AdminController::VERSION;
    /** Redmine version whose plugin API Symine implements, checked by requiresRedmine() */
    public const string REDMINE_VERSION = '7.0.0';

    public private(set) string $name;
    public private(set) ?string $description = null;
    public private(set) ?string $url = null;
    public private(set) ?string $author = null;
    public private(set) ?string $authorUrl = null;
    public private(set) ?string $version = null;
    public private(set) ?string $requiresSymine = null;
    /** @var array{default: array<string, mixed>, partial: ?string}|null */
    public private(set) ?array $settings = null;

    /** @var list<array{name: string, actions: list<string>, options: array<string, mixed>, module: ?string}> */
    private array $permissions = [];
    /** @var list<string> */
    private array $modules = [];
    /** @var list<array{menu: string, op: string, name: string, url: mixed, options: array<string, mixed>}> */
    private array $menuOperations = [];
    /** @var array<string, array<string, mixed>> plugin id => requirement */
    private array $pluginRequirements = [];
    private ?string $currentModule = null;

    public function __construct(
        public readonly string $id,
        public readonly string $directory,
        public readonly ?SyminePluginInterface $bundle = null,
    ) {
        if (!preg_match('/\A[a-z0-9_]+\z/', $id)) {
            throw new \InvalidArgumentException(\sprintf('Invalid plugin id "%s": use lowercase letters, digits and underscores.', $id));
        }
        $this->name = ucfirst(str_replace('_', ' ', $id));
    }

    // --- metadata (Redmine def_field) ---------------------------------------------------------

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function url(?string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function author(?string $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function authorUrl(?string $url): self
    {
        $this->authorUrl = $url;

        return $this;
    }

    public function version(?string $version): self
    {
        $this->version = $version;

        return $this;
    }

    /**
     * Plugin settings (Redmine `settings :default => {...}, :partial => '...'`): stored in the "plugin_<id>"
     * setting, editable on /settings/plugin/<id> when a partial (Twig template) is given.
     *
     * @param array<string, mixed> $default
     */
    public function settings(array $default = [], ?string $partial = null): self
    {
        $this->settings = ['default' => $default, 'partial' => $partial];

        return $this;
    }

    // --- requirements -------------------------------------------------------------------------

    /**
     * requires_redmine equivalent: '0.1' (= version or higher), ['version_or_higher' => '0.1'],
     * ['version' => '0.1.0'], ['version' => ['0.1', '0.2']] or ['version' => ['0.1', '0.3']] as a range
     * with ['range' => true].
     *
     * @param string|array<string, mixed> $requirement
     *
     * @throws PluginRequirementException
     */
    public function requiresSymine(string|array $requirement): self
    {
        $this->requiresSymine = \is_string($requirement) ? $requirement : json_encode($requirement, \JSON_THROW_ON_ERROR);
        $this->checkVersion($requirement, self::SYMINE_VERSION, 'Symine');

        return $this;
    }

    /**
     * requires_redmine: Symine implements the plugin API of Redmine 7.0.
     *
     * @param string|array<string, mixed> $requirement
     */
    public function requiresRedmine(string|array $requirement): self
    {
        $this->checkVersion($requirement, self::REDMINE_VERSION, 'Redmine');

        return $this;
    }

    /**
     * requires_redmine_plugin: checked once all plugins are registered.
     *
     * @param string|array<string, mixed> $requirement '1.0' (or higher) or ['version' => [...]]
     */
    public function requiresSyminePlugin(string $pluginId, string|array $requirement = '0'): self
    {
        $this->pluginRequirements[$pluginId] = \is_string($requirement) ? ['version_or_higher' => $requirement] : $requirement;

        return $this;
    }

    /** @return array<string, array<string, mixed>> */
    public function pluginRequirements(): array
    {
        return $this->pluginRequirements;
    }

    /** @param string|array<string, mixed> $requirement */
    private function checkVersion(string|array $requirement, string $current, string $app): void
    {
        $req = \is_string($requirement) ? ['version_or_higher' => $requirement] : $requirement;
        foreach ($req as $k => $v) {
            if ('version_or_higher' === $k) {
                if (self::compareVersions((string) $v, $current) > 0) {
                    throw new PluginRequirementException(\sprintf('%s plugin requires %s %s or higher but current is %s', $this->id, $app, $v, $current));
                }
            } elseif ('version' === $k) {
                $versions = (array) $v;
                if (!empty($req['range']) && 2 === \count($versions)) {
                    [$first, $last] = array_values($versions);
                    if (self::compareVersions((string) $first, $current) > 0 || self::compareVersions((string) $last, $current) < 0) {
                        throw new PluginRequirementException(\sprintf('%s plugin requires a %s version between %s and %s but current is %s', $this->id, $app, $first, $last, $current));
                    }
                } elseif (!array_filter($versions, static fn ($ver) => 0 === self::compareVersions((string) $ver, $current))) {
                    throw new PluginRequirementException(\sprintf('%s plugin requires one the following %s versions: %s but current is %s', $this->id, $app, implode(', ', $versions), $current));
                }
            } elseif ('range' !== $k) {
                throw new \InvalidArgumentException(\sprintf('Unknown requirement key "%s" (version, version_or_higher).', $k));
            }
        }
    }

    /** Redmine::Plugin#compare_versions: compares the requirement with the same number of parts of the current version */
    public static function compareVersions(string $requirement, string $current): int
    {
        $req = array_map('intval', explode('.', $requirement));
        $cur = array_slice(array_map('intval', explode('.', $current)), 0, \count($req));
        $cur = array_pad($cur, \count($req), 0);

        return $req <=> $cur;
    }

    // --- permissions & project modules --------------------------------------------------------

    /**
     * Declares a permission (Redmine `permission :name, {controller => actions}, options`).
     * Inside projectModule() the permission belongs to that module.
     *
     * @param list<string>                                         $actions route names covered by the permission
     * @param array{public?: bool, read?: bool, require?: string} $options
     */
    public function permission(string $name, array $actions = [], array $options = []): self
    {
        $this->permissions[] = ['name' => $name, 'actions' => $actions, 'options' => $options, 'module' => $this->currentModule];

        return $this;
    }

    /**
     * Declares a project module; permissions declared in the callback belong to it.
     *
     * @param callable(self): void $block
     */
    public function projectModule(string $name, callable $block): self
    {
        if (!\in_array($name, $this->modules, true)) {
            $this->modules[] = $name;
        }
        $previous = $this->currentModule;
        $this->currentModule = $name;
        try {
            $block($this);
        } finally {
            $this->currentModule = $previous;
        }

        return $this;
    }

    /** @return list<array{name: string, actions: list<string>, options: array<string, mixed>, module: ?string}> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    /** @return list<string> */
    public function projectModules(): array
    {
        return $this->modules;
    }

    // --- menus --------------------------------------------------------------------------------

    /**
     * Adds an item to a menu (Redmine `menu :project_menu, :name, url, options`).
     *
     * @param string                $menu    top_menu, account_menu, application_menu, project_menu or admin_menu
     * @param string|array|\Closure $url     route name, ['route' => name, 'params' => [...]], path/URL, or Closure(?Project): string
     * @param array<string, mixed>  $options caption, if, permission, param, html, before, after, first, last, parent, icon, children
     */
    public function menu(string $menu, string $name, string|array|\Closure $url, array $options = []): self
    {
        $this->menuOperations[] = ['menu' => $menu, 'op' => 'push', 'name' => $name, 'url' => $url, 'options' => $options];

        return $this;
    }

    /** Alias of menu() (Redmine add_menu_item) */
    public function addMenuItem(string $menu, string $name, string|array|\Closure $url, array $options = []): self
    {
        return $this->menu($menu, $name, $url, $options);
    }

    /** Removes an item (core or plugin) from a menu (Redmine delete_menu_item) */
    public function deleteMenuItem(string $menu, string $name): self
    {
        $this->menuOperations[] = ['menu' => $menu, 'op' => 'delete', 'name' => $name, 'url' => null, 'options' => []];

        return $this;
    }

    /**
     * Replaces an existing item, keeping its position unless a position option is given.
     *
     * @param array<string, mixed> $options
     */
    public function replaceMenuItem(string $menu, string $name, string|array|\Closure $url, array $options = []): self
    {
        $this->menuOperations[] = ['menu' => $menu, 'op' => 'replace', 'name' => $name, 'url' => $url, 'options' => $options];

        return $this;
    }

    /** @return list<array{menu: string, op: string, name: string, url: mixed, options: array<string, mixed>}> */
    public function menuOperations(): array
    {
        return $this->menuOperations;
    }

    // --- accessors ----------------------------------------------------------------------------

    /** Redmine::Plugin#configurable?: settings with a partial */
    public function isConfigurable(): bool
    {
        return null !== $this->settings && null !== $this->settings['partial'] && '' !== $this->settings['partial'];
    }

    public function settingName(): string
    {
        return 'plugin_'.$this->id;
    }

    /** @return array<string, mixed> */
    public function defaultSettings(): array
    {
        return $this->settings['default'] ?? [];
    }

    public function settingsPartial(): ?string
    {
        return $this->settings['partial'] ?? null;
    }

    /** Plugin assets source directory (<plugin>/assets) */
    public function assetsDirectory(): string
    {
        return $this->directory.'/assets';
    }

    /** Public URL prefix of the plugin assets (Redmine asset_prefix: plugin_assets/<id>) */
    public function assetPrefix(): string
    {
        return '/plugin_assets/'.$this->id;
    }

    public function migrationDirectory(): string
    {
        return $this->directory.'/migrations';
    }

    public function migrationNamespace(): string
    {
        if ($this->bundle instanceof AbstractSyminePlugin) {
            return $this->bundle->getMigrationsNamespace();
        }

        return null !== $this->bundle ? (new \ReflectionClass($this->bundle))->getNamespaceName().'\\Migrations' : 'SyminePlugin\\'.$this->id.'\\Migrations';
    }

    public function hasMigrations(): bool
    {
        return [] !== (glob($this->migrationDirectory().'/*.php') ?: []);
    }
}

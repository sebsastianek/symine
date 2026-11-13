<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Security\AccessControlRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Registered plugins (port of the class side of Redmine::Plugin: registered_plugins, all, find, installed?).
 *
 * Plugins are the kernel bundles implementing SyminePluginInterface (config/bundles.php, including the
 * bundles auto-discovered by PluginLoader in plugins/*). Their register() method is called once, in bundle
 * order; boot() (called when the kernel boots) pushes their permissions and project modules to AccessControl.
 */
#[Autoconfigure(public: true)]
final class PluginRegistry
{
    /** @var array<string, PluginDefinition>|null */
    private ?array $plugins = null;
    private bool $booted = false;
    /** @var list<SyminePluginInterface> plugins added with add() (tests, non-bundle plugins) */
    private array $extra = [];

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly AccessControlRegistry $accessControl,
    ) {
    }

    /** Registers the permissions and project modules of all plugins (idempotent) */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        foreach ($this->all() as $plugin) {
            foreach ($plugin->projectModules() as $module) {
                $this->accessControl->projectModule($module);
            }
            foreach ($plugin->permissions() as $p) {
                $this->accessControl->permission($p['name'], $p['actions'], $p['options'], $p['module']);
            }
        }
    }

    /**
     * Registers a plugin that is not a kernel bundle.
     */
    public function add(SyminePluginInterface $plugin): PluginDefinition
    {
        $this->extra[] = $plugin;
        $this->plugins = null;
        $this->booted = false;
        $this->boot();

        return $this->find($plugin->getPluginId());
    }

    /**
     * Redmine::Plugin.all - sorted by id.
     *
     * @return array<string, PluginDefinition>
     */
    public function all(): array
    {
        if (null === $this->plugins) {
            $plugins = [];
            $sources = array_merge(
                array_values(array_filter($this->kernel->getBundles(), static fn ($b) => $b instanceof SyminePluginInterface)),
                $this->extra,
            );
            foreach ($sources as $source) {
                $def = new PluginDefinition($source->getPluginId(), $source->getPath(), $source);
                $source->register($def);
                if (isset($plugins[$def->id])) {
                    throw new \LogicException(\sprintf('Plugin "%s" is registered twice.', $def->id));
                }
                $plugins[$def->id] = $def;
            }
            foreach ($plugins as $def) {
                foreach ($def->pluginRequirements() as $id => $req) {
                    self::checkPluginRequirement($def, $plugins[$id] ?? null, $id, $req);
                }
            }
            ksort($plugins, \SORT_STRING);
            $this->plugins = $plugins;
        }

        return $this->plugins;
    }

    /** @throws PluginNotFoundException */
    public function find(string $id): PluginDefinition
    {
        return $this->all()[$id] ?? throw new PluginNotFoundException(\sprintf('Plugin "%s" not found.', $id));
    }

    public function installed(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    /**
     * Plugins in registration (bundle) order: the order in which menu operations are applied.
     *
     * @return list<PluginDefinition>
     */
    public function inRegistrationOrder(): array
    {
        $all = $this->all();
        $out = [];
        foreach ($this->kernel->getBundles() as $bundle) {
            if ($bundle instanceof SyminePluginInterface && isset($all[$bundle->getPluginId()])) {
                $out[] = $all[$bundle->getPluginId()];
            }
        }
        foreach ($this->extra as $plugin) {
            $out[] = $all[$plugin->getPluginId()];
        }

        return $out;
    }

    /** @param array<string, mixed> $req */
    private static function checkPluginRequirement(PluginDefinition $def, ?PluginDefinition $required, string $id, array $req): void
    {
        if (null === $required) {
            throw new PluginRequirementException(\sprintf('%s plugin requires the %s plugin', $def->id, $id));
        }
        $current = (string) ($required->version ?? '0');
        foreach ($req as $k => $v) {
            $versions = array_map('strval', (array) $v);
            if ('version_or_higher' === $k) {
                if (version_compare($current, $versions[0], '<')) {
                    throw new PluginRequirementException(\sprintf('%s plugin requires the %s plugin %s or higher but current is %s', $def->id, $id, $versions[0], $current));
                }
            } elseif ('version' === $k) {
                if (!array_filter($versions, static fn ($ver) => 0 === PluginDefinition::compareVersions($ver, $current))) {
                    throw new PluginRequirementException(\sprintf('%s plugin requires one the following versions of %s: %s but current is %s', $def->id, $id, implode(', ', $versions), $current));
                }
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Injectable registry of permissions and project modules (Redmine::AccessControl.map).
 *
 * Backed by the static AccessControl storage so the static API used by the core (Authorization,
 * roles editor, project modules) sees every registered permission. Plugins declare their permissions
 * in SyminePluginInterface::register() and the PluginRegistry pushes them here at kernel boot.
 */
final class AccessControlRegistry
{
    /**
     * map.permission(name, actions, options).
     *
     * @param list<string>                                         $actions route names covered by the permission
     * @param array{public?: bool, read?: bool, require?: string} $options
     */
    public function permission(string $name, array $actions = [], array $options = [], ?string $module = null): self
    {
        AccessControl::register($name, $module, $options, $actions);

        return $this;
    }

    /**
     * map.project_module(name) { ... }: permissions declared in the callback belong to the module.
     *
     * @param callable(ModulePermissionMapper): void $permissions
     */
    public function projectModule(string $module, ?callable $permissions = null): self
    {
        AccessControl::registerModule($module);
        if (null !== $permissions) {
            $permissions(new ModulePermissionMapper($this, $module));
        }

        return $this;
    }

    public function exists(string $permission): bool
    {
        return AccessControl::exists($permission);
    }

    /** @return array<string, array{module: ?string, public?: bool, read?: bool, require?: string, actions?: list<string>}> */
    public function permissions(): array
    {
        return AccessControl::permissions();
    }

    /** @return list<string> */
    public function modules(): array
    {
        return AccessControl::modules();
    }

    public function permissionForRoute(string $route): ?string
    {
        return AccessControl::permissionForRoute($route);
    }
}

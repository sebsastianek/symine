<?php

declare(strict_types=1);

namespace App\Menu;

use App\Plugin\PluginRegistry;

/**
 * Menu items added/removed at runtime - port of Redmine::MenuManager.map(menu).push / delete.
 *
 * MenuBuilder builds the core items of each menu, then applies these operations in order:
 * plugins' operations (bundle order) followed by the ones registered directly on this service.
 *
 * Item options (same as Redmine's MenuItem):
 * - caption: translation key, literal string or Closure(?Project): string (default: label_<name>)
 * - if: Closure(?Project, User): bool condition
 * - permission: permission checked in project menus (false = no check; default: the permission whose
 *   actions include the item route, if any)
 * - param: name of the route parameter receiving the project identifier in project menus (default "id")
 * - html: attributes of the link; icon (sprite icon name, admin menu); class
 * - first / last: true, before / after: name of an existing item
 * - parent: name of the item the new one is a child of (e.g. "new_object" in the project menu)
 * - children: Closure(?Project): list of [name, url, options] children
 */
final class MenuRegistry
{
    public const array MENUS = ['top_menu', 'account_menu', 'application_menu', 'project_menu', 'admin_menu'];

    /** @var list<array{menu: string, op: string, name: string, url: mixed, options: array<string, mixed>}> */
    private array $operations = [];

    public function __construct(
        private readonly ?PluginRegistry $plugins = null,
    ) {
    }

    /**
     * @param string|array|\Closure $url route name, ['route' => ..., 'params' => [...]], path or URL, Closure(?Project): string
     * @param array<string, mixed>  $options
     */
    public function push(string $menu, string $name, string|array|\Closure $url, array $options = []): self
    {
        $this->operations[] = self::operation($menu, 'push', $name, $url, $options);

        return $this;
    }

    public function delete(string $menu, string $name): self
    {
        $this->operations[] = self::operation($menu, 'delete', $name, null, []);

        return $this;
    }

    /** @param array<string, mixed> $options */
    public function replace(string $menu, string $name, string|array|\Closure $url, array $options = []): self
    {
        $this->operations[] = self::operation($menu, 'replace', $name, $url, $options);

        return $this;
    }

    /**
     * Operations for a menu, in application order.
     *
     * @return list<array{menu: string, op: string, name: string, url: mixed, options: array<string, mixed>}>
     */
    public function operations(string $menu): array
    {
        $ops = [];
        foreach ($this->plugins?->inRegistrationOrder() ?? [] as $plugin) {
            foreach ($plugin->menuOperations() as $op) {
                $op = self::operation($op['menu'], $op['op'], $op['name'], $op['url'], $op['options']);
                $op['options']['plugin'] ??= $plugin->id;
                $ops[] = $op;
            }
        }
        $ops = [...$ops, ...$this->operations];

        return array_values(array_filter($ops, static fn (array $op) => $op['menu'] === $menu));
    }

    /** @return array{menu: string, op: string, name: string, url: mixed, options: array<string, mixed>} */
    private static function operation(string $menu, string $op, string $name, mixed $url, array $options): array
    {
        if (!\in_array($menu, self::MENUS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown menu "%s" (%s).', $menu, implode(', ', self::MENUS)));
        }
        if (isset($options['if']) && !\is_callable($options['if'])) {
            throw new \InvalidArgumentException(\sprintf("Invalid option :if for menu item '%s'", $name));
        }
        if (isset($options['html']) && !\is_array($options['html'])) {
            throw new \InvalidArgumentException(\sprintf("Invalid option :html for menu item '%s'", $name));
        }
        if (isset($options['parent']) && $options['parent'] === $name) {
            throw new \InvalidArgumentException('Cannot set the :parent to be the same as this item');
        }
        if (isset($options['children']) && !\is_callable($options['children'])) {
            throw new \InvalidArgumentException(\sprintf("Invalid option :children for menu item '%s'", $name));
        }

        return ['menu' => $menu, 'op' => $op, 'name' => $name, 'url' => $url, 'options' => $options];
    }
}

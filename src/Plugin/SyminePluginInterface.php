<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * A Symine plugin: a Symfony bundle implementing this interface (usually by extending AbstractSyminePlugin).
 *
 * register() is the equivalent of the block given to Redmine::Plugin.register: it receives the plugin
 * definition and declares metadata, requirements, settings, permissions, project modules and menu items.
 * Hook listeners, activity/search providers and custom field formats are plain services implementing
 * the corresponding interfaces (see docs/PLUGINS.md).
 */
interface SyminePluginInterface
{
    /** Plugin identifier (Redmine plugin id, e.g. "symine_hello"): used in URLs and in the "plugin_<id>" setting */
    public function getPluginId(): string;

    public function register(PluginDefinition $plugin): void;

    /** Root directory of the plugin (templates/, translations/, migrations/, assets/) */
    public function getPath(): string;
}

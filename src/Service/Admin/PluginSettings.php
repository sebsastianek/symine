<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Plugin\PluginDefinition;
use App\Plugin\PluginNotFoundException;
use App\Plugin\PluginRegistry;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The settings of a configurable plugin (SettingsController#plugin), stored in the Setting "plugin_<id>". */
final class PluginSettings
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** The installed plugin with this id: 404 when there is none or when it has nothing to configure */
    public function find(string $id): PluginDefinition
    {
        try {
            $plugin = $this->plugins->find($id);
        } catch (PluginNotFoundException) {
            throw new NotFoundHttpException();
        }
        if (!$plugin->isConfigurable()) {
            throw new NotFoundHttpException();
        }

        return $plugin;
    }

    /** Stores the settings[...] of the submitted form */
    public function save(PluginDefinition $plugin, Request $request): void
    {
        $settings = $request->request->all('settings');
        $this->settings->set($plugin->settingName(), $settings);
        $this->em->flush();
    }

    /** @return array<string, mixed> the stored settings of the plugin */
    public function values(PluginDefinition $plugin): array
    {
        return $this->settings->hash($plugin->settingName());
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Service\Admin\PluginSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of SettingsController#plugin: settings of a configurable plugin, stored in Setting "plugin_<id>" */
final class PluginSettingsController extends AbstractController
{
    #[Route('/settings/plugin/{id}', name: 'plugin_settings', methods: ['GET', 'POST'])]
    public function plugin(Request $request, string $id, PluginSettings $pluginSettings): Response
    {
        $this->requireAdmin();
        $plugin = $pluginSettings->find($id);
        if ($request->isMethod('POST')) {
            $pluginSettings->save($plugin, $request);
            $this->flashNotice('notice_successful_update');

            return $this->redirectToRoute('plugin_settings', ['id' => $plugin->id]);
        }

        return $this->render('admin/settings/plugin.html.twig', [
            'plugin' => $plugin,
            'partial' => $plugin->settingsPartial(),
            'plugin_settings' => $pluginSettings->values($plugin),
            'admin_menu_selected' => 'plugins',
            'page_title' => $plugin->name,
            'body_css' => 'controller-settings action-plugin',
        ]);
    }
}

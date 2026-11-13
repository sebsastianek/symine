<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Plugin\PluginNotFoundException;
use App\Plugin\PluginRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of SettingsController#plugin: settings of a configurable plugin, stored in Setting "plugin_<id>" */
final class PluginSettingsController extends AbstractController
{
    #[Route('/settings/plugin/{id}', name: 'plugin_settings', methods: ['GET', 'POST'])]
    public function plugin(Request $request, string $id, PluginRegistry $plugins): Response
    {
        $this->requireAdmin();
        try {
            $plugin = $plugins->find($id);
        } catch (PluginNotFoundException) {
            throw $this->createNotFoundException();
        }
        if (!$plugin->isConfigurable()) {
            throw $this->createNotFoundException();
        }
        if ($request->isMethod('POST')) {
            $settings = $request->request->all('settings');
            $this->settings->set($plugin->settingName(), $settings);
            $this->em->flush();
            $this->flashNotice('notice_successful_update');

            return $this->redirectToRoute('plugin_settings', ['id' => $plugin->id]);
        }

        return $this->render('admin/settings/plugin.html.twig', [
            'plugin' => $plugin,
            'partial' => $plugin->settingsPartial(),
            'plugin_settings' => $this->settings->hash($plugin->settingName()),
            'admin_menu_selected' => 'plugins',
            'page_title' => $plugin->name,
            'body_css' => 'controller-settings action-plugin',
        ]);
    }
}

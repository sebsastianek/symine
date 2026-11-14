<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Theme\Form\SiteAppearanceForm;
use App\Theme\Theme;
use App\Theme\ThemeContext;
use App\Theme\ThemeRegistry;
use App\Theme\ThemeSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration > Themes: choose the theme of the site, try one out, customise its options and decide what
 * users may change for themselves. The Theme of an action comes from the {id} of its route.
 */
final class ThemesController extends AbstractController
{
    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly ThemeSettings $themeSettings,
        private readonly ThemeContext $context,
        private readonly SiteAppearanceForm $form,
    ) {
    }

    #[Route('/admin/themes', name: 'admin_themes', methods: ['GET'])]
    public function index(): Response
    {
        $this->requireAdmin();

        return $this->render('admin/themes/index.html.twig', [
            'themes' => $this->registry->all(),
            'theme_errors' => $this->registry->errors(),
            'site_theme' => $this->themeSettings->siteTheme(),
            'previewed' => $this->context->previewed(),
            'user_theme' => $this->themeSettings->usersMayChooseTheme(),
            'user_options' => $this->themeSettings->usersMayCustomise(),
            'admin_menu_selected' => 'themes',
            'page_title' => $this->l('label_theme_plural'),
            'body_css' => 'controller-themes action-index',
        ]);
    }

    #[Route('/admin/themes/permissions', name: 'admin_themes_permissions', methods: ['POST'], priority: 1)]
    public function permissions(Request $request): Response
    {
        $this->requireAdmin();
        $this->themeSettings->setUserPermissions($request->request->getBoolean('user_theme'), $request->request->getBoolean('user_options'));
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('admin_themes');
    }

    #[Route('/admin/themes/preview', name: 'admin_themes_preview_stop', methods: ['POST', 'DELETE'], priority: 1)]
    public function stopPreview(Request $request): Response
    {
        $this->requireAdmin();
        $request->getSession()->remove(ThemeContext::SESSION_PREVIEW);

        return $this->redirectToRoute('admin_themes');
    }

    #[Route('/admin/themes/{id}/activate', name: 'admin_themes_activate', methods: ['POST'])]
    public function activate(Request $request, Theme $theme): Response
    {
        $this->requireAdmin();
        $this->themeSettings->setSiteTheme($theme);
        $request->getSession()->remove(ThemeContext::SESSION_PREVIEW);
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('admin_themes');
    }

    /** Shows the theme to this administrator only, until the preview is stopped or the theme activated */
    #[Route('/admin/themes/{id}/preview', name: 'admin_themes_preview', methods: ['POST'])]
    public function preview(Request $request, Theme $theme): Response
    {
        $this->requireAdmin();
        $request->getSession()->set(ThemeContext::SESSION_PREVIEW, $theme->id);

        return $this->redirectToRoute('home');
    }

    #[Route('/admin/themes/{id}', name: 'admin_themes_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Theme $theme): Response
    {
        $this->requireAdmin();

        $rejected = [];
        if ($request->isMethod('POST')) {
            if ($this->form->isReset($request)) {
                $this->form->reset($theme);
            } else {
                $rejected = $this->form->submit($theme, $request);
            }
            if ([] === $rejected) {
                $this->flashNotice('notice_successful_update');

                return $this->redirectToRoute('admin_themes_edit', ['id' => $theme->id]);
            }
        }

        // the page is shown with the theme being customised, so every change can be seen as it is made
        $this->context->force($theme);

        return $this->render('admin/themes/edit.html.twig', $this->form->view($theme, $request, $rejected) + [
            'admin_menu_selected' => 'themes',
            'page_title' => $theme->name,
            'body_css' => 'controller-themes action-edit',
        ], new Response('', [] === $rejected ? 200 : 422));
    }
}

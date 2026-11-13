<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Service\Admin\SettingsForm;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's SettingsController (administration settings, all tabs) */
final class SettingsController extends AbstractController
{
    public function __construct(private readonly SettingsForm $form)
    {
    }

    #[Route('/settings', name: 'settings_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin();

        return $this->renderEdit($request);
    }

    #[Route('/settings/edit', name: 'settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $this->requireAdmin();
        if ($request->isMethod('POST')) {
            $posted = $request->request->all('settings');
            if (($posted['default_issue_due_date_offset_enabled'] ?? null) === '0') {
                $posted['default_issue_due_date_offset'] = '';
            }
            $errors = $this->form->validate($posted);
            if ([] === $errors) {
                $this->form->save($posted);
                $this->flashNotice('notice_successful_update');

                return $this->redirectToRoute('settings_index', array_filter(['tab' => $request->query->get('tab', $request->request->get('tab'))]));
            }

            return $this->renderEdit($request, $posted, $errors, 422);
        }

        return $this->renderEdit($request);
    }

    /** @param array<string, string> $errors */
    private function renderEdit(Request $request, array $posted = [], array $errors = [], int $status = 200): Response
    {
        $tabs = [];
        foreach (SettingsForm::TABS as $name => [$template, $label]) {
            $tabs[] = ['name' => $name, 'label' => $label, 'template' => 'admin/settings/_'.$template.'.html.twig'];
        }

        return $this->render('admin/settings/edit.html.twig', [
            'tabs' => $tabs,
            'values' => $this->form->values($posted),
            'posted' => $posted,
            'setting_errors' => $errors,
            'opts' => $this->form->options($this->user()),
            'guessed_host_and_path' => $request->getHttpHost().$request->getBasePath(),
            'admin_menu_selected' => 'settings',
            'page_title' => $this->l('label_settings'),
            'body_css' => 'controller-settings action-edit',
        ], new Response('', $status));
    }
}

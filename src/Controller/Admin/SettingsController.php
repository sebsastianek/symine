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

        return $this->render('admin/settings/edit.html.twig', $this->form->view($request, $this->user()));
    }

    #[Route('/settings/edit', name: 'settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $this->requireAdmin();
        if (!$request->isMethod('POST')) {
            return $this->render('admin/settings/edit.html.twig', $this->form->view($request, $this->user()));
        }
        $posted = $this->form->posted($request);
        $errors = $this->form->submit($posted);
        if ([] !== $errors) {
            return $this->render('admin/settings/edit.html.twig', $this->form->view($request, $this->user(), $posted, $errors), new Response('', 422));
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('settings_index', array_filter(['tab' => $request->query->get('tab', $request->request->get('tab'))]));
    }
}

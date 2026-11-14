<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Service\Admin\CustomFields\CustomFieldEnumerationManager;
use App\Service\Admin\CustomFields\CustomFieldEnumerationsView;
use App\Service\Admin\CustomFields\CustomFieldFinder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's CustomFieldEnumerationsController (values of "Key/value list" custom fields) */
final class CustomFieldEnumerationsController extends AbstractController
{
    public function __construct(
        private readonly CustomFieldFinder $finder,
        private readonly CustomFieldEnumerationManager $enumerations,
        private readonly CustomFieldEnumerationsView $view,
    ) {
    }

    #[Route('/custom_fields/{custom_field_id}/enumerations', name: 'custom_field_enumerations', requirements: ['custom_field_id' => '\d+'], methods: ['GET'])]
    public function index(int $custom_field_id): Response
    {
        $this->requireAdmin();

        return $this->render('admin/custom_fields/enumerations.html.twig', $this->view->index($this->finder->find($custom_field_id)) + [
            'admin_menu_selected' => 'custom_fields',
            'body_css' => 'controller-custom_field_enumerations action-index',
        ]);
    }

    #[Route('/custom_fields/{custom_field_id}/enumerations', name: 'custom_field_enumerations_create', requirements: ['custom_field_id' => '\d+'], methods: ['POST'])]
    public function create(Request $request, int $custom_field_id): Response
    {
        $this->requireAdmin();
        $field = $this->finder->find($custom_field_id);
        $errors = $this->enumerations->add($field, $request->request->all('custom_field_enumeration'));
        if ([] !== $errors) {
            return $this->render('admin/custom_fields/enumerations.html.twig', $this->view->index($field, $errors) + [
                'admin_menu_selected' => 'custom_fields',
                'body_css' => 'controller-custom_field_enumerations action-index',
            ], new Response('', 422));
        }

        return $this->redirectToRoute('custom_field_enumerations', ['custom_field_id' => $field->id]);
    }

    #[Route('/custom_fields/{custom_field_id}/enumerations', name: 'custom_field_enumerations_update_each', requirements: ['custom_field_id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function updateEach(Request $request, int $custom_field_id): Response
    {
        $this->requireAdmin();
        if ($this->enumerations->updateEach($this->finder->find($custom_field_id), $request->request->all('custom_field_enumerations'))) {
            $this->flashNotice('notice_successful_update');
        }

        return $this->redirectToRoute('custom_field_enumerations', ['custom_field_id' => $custom_field_id]);
    }

    #[Route('/custom_fields/{custom_field_id}/enumerations/{id}', name: 'custom_field_enumeration_destroy', requirements: ['custom_field_id' => '\d+', 'id' => '\d+'], methods: ['DELETE'])]
    public function destroy(Request $request, int $custom_field_id, int $id): Response
    {
        $this->requireAdmin();
        $field = $this->finder->find($custom_field_id);
        $value = $this->enumerations->find($field, $id);
        if (!$this->enumerations->destroyOrReassign($field, $value, (string) $request->request->get('reassign_to_id', ''))) {
            return $this->render('admin/custom_fields/enumeration_destroy.html.twig', $this->view->destroy($field, $value) + [
                'admin_menu_selected' => 'custom_fields',
                'body_css' => 'controller-custom_field_enumerations action-destroy',
            ]);
        }

        return $this->redirectToRoute('custom_field_enumerations', ['custom_field_id' => $field->id]);
    }
}

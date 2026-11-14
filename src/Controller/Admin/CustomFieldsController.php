<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\ApiRenderer;
use App\Api\CustomFieldApiPresenter;
use App\Controller\AbstractController;
use App\Service\Admin\CustomFields\CustomFieldFinder;
use App\Service\Admin\CustomFields\CustomFieldForm;
use App\Service\Admin\CustomFields\CustomFieldIndex;
use App\Service\Admin\CustomFields\CustomFieldManager;
use App\Service\Admin\FieldFormats;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's CustomFieldsController */
final class CustomFieldsController extends AbstractController
{
    public function __construct(
        private readonly ApiRenderer $api,
        private readonly CustomFieldApiPresenter $presenter,
        private readonly CustomFieldFinder $finder,
        private readonly CustomFieldIndex $list,
        private readonly CustomFieldForm $form,
        private readonly CustomFieldManager $fields,
    ) {
    }

    #[Route('/custom_fields', name: 'custom_fields_index', methods: ['GET'])]
    #[Route('/custom_fields.{_format}', name: 'custom_fields_index_api', requirements: ['_format' => 'json|xml'], methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin();
        if ($this->isApi($request)) {
            return $this->api->render($request, ['custom_fields' => $this->presenter->customFields($this->finder->all())]);
        }

        return $this->render('admin/custom_fields/index.html.twig', $this->list->view() + [
            'admin_menu_selected' => 'custom_fields',
            'page_title' => $this->l('label_custom_field_plural'),
            'body_css' => 'controller-custom_fields action-index',
        ]);
    }

    #[Route('/custom_fields/new', name: 'custom_field_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin();
        $type = (string) $request->query->get('type', '');
        if (!FieldFormats::typeExists($type)) {
            return $this->render('admin/custom_fields/select_type.html.twig', $this->form->typeSelectionView((string) $request->query->get('tab', '')) + [
                'admin_menu_selected' => 'custom_fields',
                'body_css' => 'controller-custom_fields action-new',
            ]);
        }
        $copyFrom = $this->finder->copySource((int) $request->query->get('copy'));

        return $this->render('admin/custom_fields/new.html.twig', $this->form->newView($type, $request->query->all('custom_field'), $copyFrom) + [
            'admin_menu_selected' => 'custom_fields',
            'body_css' => 'controller-custom_fields action-new',
        ]);
    }

    #[Route('/custom_fields', name: 'custom_field_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $type = (string) $request->request->get('type', '');
        if (!FieldFormats::typeExists($type)) {
            return $this->redirectToRoute('custom_field_new');
        }
        $copyFrom = $this->finder->copySource((int) $request->request->get('copy'));
        $field = $this->form->newField($type, $copyFrom);
        $errors = $this->form->submit($field, $request->request->all('custom_field'), fn () => $this->callHook('controller_custom_fields_new_after_save', ['params' => $request->request->all(), 'custom_field' => $field]));
        if ([] !== $errors) {
            return $this->render('admin/custom_fields/new.html.twig', $this->form->view($field, $errors, $copyFrom) + [
                'admin_menu_selected' => 'custom_fields',
                'body_css' => 'controller-custom_fields action-new',
            ], new Response('', 422));
        }
        $this->flashNotice('notice_successful_create');
        if ($request->request->has('continue')) {
            return $this->redirectToRoute('custom_field_new', ['type' => $type]);
        }

        return $this->redirectToRoute('custom_fields_index', ['tab' => $type]);
    }

    #[Route('/custom_fields/{id}/edit', name: 'custom_field_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->requireAdmin();

        return $this->render('admin/custom_fields/edit.html.twig', $this->form->view($this->finder->find($id)) + [
            'admin_menu_selected' => 'custom_fields',
            'body_css' => 'controller-custom_fields action-edit',
        ]);
    }

    #[Route('/custom_fields/{id}', name: 'custom_field_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH', 'POST'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $field = $this->finder->find($id);
        $errors = $this->form->submit($field, $request->request->all('custom_field'), fn () => $this->callHook('controller_custom_fields_edit_after_save', ['params' => $request->request->all(), 'custom_field' => $field]));
        if ($request->isXmlHttpRequest()) {
            return new Response('', [] === $errors ? 200 : 422);
        }
        if ([] !== $errors) {
            return $this->render('admin/custom_fields/edit.html.twig', $this->form->view($field, $errors) + [
                'admin_menu_selected' => 'custom_fields',
                'body_css' => 'controller-custom_fields action-edit',
            ], new Response('', 422));
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectBackOr($request, $this->generateUrl('custom_field_edit', ['id' => $field->id]));
    }

    #[Route('/custom_fields/{id}', name: 'custom_field_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->requireAdmin();
        $field = $this->finder->find($id);
        $type = $field::typeName();
        if ($this->fields->destroy($field)) {
            $this->flashNotice('notice_successful_delete');
        } else {
            $this->flashError($this->l('error_can_not_delete_custom_field'));
        }

        return $this->redirectToRoute('custom_fields_index', ['tab' => $type]);
    }
}

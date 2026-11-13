<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Entity\CustomField;
use App\Entity\CustomFieldEnumeration;
use App\Service\Admin\FieldFormats;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's CustomFieldEnumerationsController (values of "Key/value list" custom fields) */
final class CustomFieldEnumerationsController extends AbstractController
{
    #[Route('/custom_fields/{custom_field_id}/enumerations', name: 'custom_field_enumerations', requirements: ['custom_field_id' => '\d+'], methods: ['GET'])]
    public function index(int $custom_field_id, array $errors = []): Response
    {
        $this->requireAdmin();
        $field = $this->findField($custom_field_id);

        return $this->render('admin/custom_fields/enumerations.html.twig', [
            'custom_field' => $field,
            'type' => $field::typeName(),
            'type_label' => FieldFormats::TABS[$field::typeName()] ?? 'label_custom_field_plural',
            'errors' => $errors,
            'admin_menu_selected' => 'custom_fields',
            'body_css' => 'controller-custom_field_enumerations action-index',
        ], new Response('', [] === $errors ? 200 : 422));
    }

    #[Route('/custom_fields/{custom_field_id}/enumerations', name: 'custom_field_enumerations_create', requirements: ['custom_field_id' => '\d+'], methods: ['POST'])]
    public function create(Request $request, int $custom_field_id): Response
    {
        $this->requireAdmin();
        $field = $this->findField($custom_field_id);
        $params = $request->request->all('custom_field_enumeration');
        $name = trim((string) ($params['name'] ?? ''));
        $errors = [];
        if ('' === $name) {
            $errors[] = $this->l('field_name').' '.$this->l('activerecord.errors.messages.blank');
        } elseif (mb_strlen($name) > 255) {
            $errors[] = $this->l('field_name').' '.$this->l('activerecord.errors.messages.too_long', ['count' => 255]);
        }
        if ([] === $errors) {
            $e = new CustomFieldEnumeration();
            $e->customField = $field;
            $e->name = $name;
            $e->active = !isset($params['active']) || '0' !== (string) $params['active'];
            $max = (int) $this->em->getConnection()->fetchOne('SELECT MAX(position) FROM custom_field_enumerations WHERE custom_field_id = ?', [$field->id]);
            $e->position = $max + 1;
            $field->enumerations->add($e);
            $this->em->persist($e);
            $this->em->flush();

            return $this->redirectToRoute('custom_field_enumerations', ['custom_field_id' => $field->id]);
        }

        return $this->index($custom_field_id, $errors);
    }

    #[Route('/custom_fields/{custom_field_id}/enumerations', name: 'custom_field_enumerations_update_each', requirements: ['custom_field_id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function updateEach(Request $request, int $custom_field_id): Response
    {
        $this->requireAdmin();
        $field = $this->findField($custom_field_id);
        $attrs = $request->request->all('custom_field_enumerations');
        $ok = true;
        foreach ($attrs as $id => $values) {
            foreach ($field->enumerations as $e) {
                if ($e->id !== (int) $id || !\is_array($values)) {
                    continue;
                }
                if (\array_key_exists('name', $values)) {
                    $name = trim((string) $values['name']);
                    if ('' === $name || mb_strlen($name) > 255) {
                        $ok = false;
                        break 2;
                    }
                    $e->name = $name;
                }
                if (\array_key_exists('active', $values)) {
                    $v = \is_array($values['active']) ? end($values['active']) : $values['active'];
                    $e->active = '1' === (string) $v;
                }
                if (\array_key_exists('position', $values)) {
                    if (!preg_match('/^-?\d+$/', (string) $values['position'])) {
                        $ok = false;
                        break 2;
                    }
                    $e->position = (int) $values['position'];
                }
            }
        }
        if ($ok) {
            $this->em->flush();
            $this->flashNotice('notice_successful_update');
        } else {
            $this->em->clear();
        }

        return $this->redirectToRoute('custom_field_enumerations', ['custom_field_id' => $custom_field_id]);
    }

    #[Route('/custom_fields/{custom_field_id}/enumerations/{id}', name: 'custom_field_enumeration_destroy', requirements: ['custom_field_id' => '\d+', 'id' => '\d+'], methods: ['DELETE'])]
    public function destroy(Request $request, int $custom_field_id, int $id): Response
    {
        $this->requireAdmin();
        $field = $this->findField($custom_field_id);
        $value = null;
        $reassignTo = null;
        foreach ($field->enumerations as $e) {
            if ($e->id === $id) {
                $value = $e;
            }
            if ('' !== (string) $request->request->get('reassign_to_id', '') && $e->id === (int) $request->request->get('reassign_to_id')) {
                $reassignTo = $e;
            }
        }
        if (null === $value) {
            throw $this->createNotFoundException();
        }
        $db = $this->em->getConnection();
        $count = (int) $db->fetchOne('SELECT COUNT(*) FROM custom_values WHERE custom_field_id = ? AND value = ?', [$field->id, (string) $value->id]);
        if (null === $reassignTo && $count > 0) {
            return $this->render('admin/custom_fields/enumeration_destroy.html.twig', [
                'custom_field' => $field,
                'type' => $field::typeName(),
                'type_label' => FieldFormats::TABS[$field::typeName()] ?? 'label_custom_field_plural',
                'value' => $value,
                'objects_count' => $count,
                'enumerations' => array_values(array_filter($field->enumerations->toArray(), static fn ($e) => $e !== $value)),
                'admin_menu_selected' => 'custom_fields',
                'body_css' => 'controller-custom_field_enumerations action-destroy',
            ]);
        }
        if (null !== $reassignTo) {
            $db->executeStatement('UPDATE custom_values SET value = ? WHERE custom_field_id = ? AND value = ?', [(string) $reassignTo->id, $field->id, (string) $value->id]);
        }
        $field->enumerations->removeElement($value);
        $this->em->remove($value);
        $this->em->flush();

        return $this->redirectToRoute('custom_field_enumerations', ['custom_field_id' => $field->id]);
    }

    private function findField(int $id): CustomField
    {
        $field = $this->em->find(CustomField::class, $id);
        if (null === $field) {
            throw $this->createNotFoundException();
        }

        return $field;
    }
}

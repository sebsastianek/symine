<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CustomField;
use App\Repository\CustomFieldRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/custom-fields')]
#[IsGranted('ROLE_ADMIN')]
class CustomFieldController extends AbstractController
{
    public function __construct(
        private CustomFieldRepository $customFieldRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/issues', name: 'admin_custom_field_issues')]
    public function issues(): Response
    {
        $customFields = $this->customFieldRepository->findBy(
            ['type' => 'IssueCustomField'],
            ['position' => 'ASC']
        );

        return $this->render('admin/custom_field/list.html.twig', [
            'custom_fields' => $customFields,
            'type' => 'IssueCustomField',
            'type_label' => 'Issue Custom Fields',
            'type_singular' => 'Issue Field',
        ]);
    }

    #[Route('/projects', name: 'admin_custom_field_projects')]
    public function projects(): Response
    {
        $customFields = $this->customFieldRepository->findBy(
            ['type' => 'ProjectCustomField'],
            ['position' => 'ASC']
        );

        return $this->render('admin/custom_field/list.html.twig', [
            'custom_fields' => $customFields,
            'type' => 'ProjectCustomField',
            'type_label' => 'Project Custom Fields',
            'type_singular' => 'Project Field',
        ]);
    }

    #[Route('/new', name: 'admin_custom_field_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $type = $request->query->get('type') ?: $request->request->get('type');

        if (!in_array($type, ['IssueCustomField', 'ProjectCustomField'])) {
            $this->addFlash('error', 'Invalid custom field type.');
            return $this->redirectToRoute('admin_custom_field_issues');
        }

        $customField = new CustomField();

        if ($request->isMethod('POST')) {
            $customField->setType($type);
            $customField->setName($request->request->get('name'));
            $customField->setFieldFormat($request->request->get('field_format', 'string'));
            $customField->setDescription($request->request->get('description'));
            $customField->setPosition((int) $request->request->get('position', 1));
            $customField->setIsRequired($request->request->has('is_required'));
            $customField->setIsForAll($request->request->has('is_for_all'));
            $customField->setSearchable($request->request->has('searchable'));
            $customField->setVisible($request->request->has('visible'));
            $customField->setEditable($request->request->has('editable'));
            $customField->setMultiple($request->request->has('multiple'));
            $customField->setDefaultValue($request->request->get('default_value'));
            $customField->setMinLength($request->request->get('min_length') ? (int) $request->request->get('min_length') : null);
            $customField->setMaxLength($request->request->get('max_length') ? (int) $request->request->get('max_length') : null);
            $customField->setRegexp($request->request->get('regexp'));
            $customField->setPossibleValues($request->request->get('possible_values'));

            $this->entityManager->persist($customField);
            $this->entityManager->flush();

            $this->addFlash('success', 'Custom field created successfully.');

            return $this->redirectToRoute($this->getRouteForType($type));
        }

        return $this->render('admin/custom_field/new.html.twig', [
            'custom_field' => $customField,
            'type' => $type,
            'type_label' => $this->getLabelForType($type),
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_custom_field_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, CustomField $customField): Response
    {
        if ($request->isMethod('POST')) {
            $customField->setName($request->request->get('name'));
            $customField->setFieldFormat($request->request->get('field_format', 'string'));
            $customField->setDescription($request->request->get('description'));
            $customField->setPosition((int) $request->request->get('position', 1));
            $customField->setIsRequired($request->request->has('is_required'));
            $customField->setIsForAll($request->request->has('is_for_all'));
            $customField->setSearchable($request->request->has('searchable'));
            $customField->setVisible($request->request->has('visible'));
            $customField->setEditable($request->request->has('editable'));
            $customField->setMultiple($request->request->has('multiple'));
            $customField->setDefaultValue($request->request->get('default_value'));
            $customField->setMinLength($request->request->get('min_length') ? (int) $request->request->get('min_length') : null);
            $customField->setMaxLength($request->request->get('max_length') ? (int) $request->request->get('max_length') : null);
            $customField->setRegexp($request->request->get('regexp'));
            $customField->setPossibleValues($request->request->get('possible_values'));

            $this->entityManager->flush();

            $this->addFlash('success', 'Custom field updated successfully.');

            return $this->redirectToRoute($this->getRouteForType($customField->getType()));
        }

        return $this->render('admin/custom_field/edit.html.twig', [
            'custom_field' => $customField,
            'type_label' => $this->getLabelForType($customField->getType()),
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_custom_field_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, CustomField $customField): Response
    {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_custom_field_' . $customField->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute($this->getRouteForType($customField->getType()));
        }

        $type = $customField->getType();
        $fieldName = $customField->getName();

        $this->entityManager->remove($customField);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Custom field "%s" deleted successfully.', $fieldName));

        return $this->redirectToRoute($this->getRouteForType($type));
    }

    private function getRouteForType(string $type): string
    {
        return match ($type) {
            'IssueCustomField' => 'admin_custom_field_issues',
            'ProjectCustomField' => 'admin_custom_field_projects',
            default => 'admin_index',
        };
    }

    private function getLabelForType(string $type): string
    {
        return match ($type) {
            'IssueCustomField' => 'Issue Custom Field',
            'ProjectCustomField' => 'Project Custom Field',
            default => 'Custom Field',
        };
    }
}

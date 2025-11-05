<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Enumeration;
use App\Repository\EnumerationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/enumerations')]
#[IsGranted('ROLE_ADMIN')]
class EnumerationController extends AbstractController
{
    public function __construct(
        private EnumerationRepository $enumerationRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/priorities', name: 'admin_enumeration_priorities')]
    public function priorities(): Response
    {
        $enumerations = $this->enumerationRepository->findBy(
            ['type' => 'IssuePriority'],
            ['position' => 'ASC']
        );

        return $this->render('admin/enumeration/list.html.twig', [
            'enumerations' => $enumerations,
            'type' => 'IssuePriority',
            'type_label' => 'Issue Priorities',
            'type_singular' => 'Priority',
        ]);
    }

    #[Route('/activities', name: 'admin_enumeration_activities')]
    public function activities(): Response
    {
        $enumerations = $this->enumerationRepository->findBy(
            ['type' => 'TimeEntryActivity'],
            ['position' => 'ASC']
        );

        return $this->render('admin/enumeration/list.html.twig', [
            'enumerations' => $enumerations,
            'type' => 'TimeEntryActivity',
            'type_label' => 'Time Entry Activities',
            'type_singular' => 'Activity',
        ]);
    }

    #[Route('/document-categories', name: 'admin_enumeration_document_categories')]
    public function documentCategories(): Response
    {
        $enumerations = $this->enumerationRepository->findBy(
            ['type' => 'DocumentCategory'],
            ['position' => 'ASC']
        );

        return $this->render('admin/enumeration/list.html.twig', [
            'enumerations' => $enumerations,
            'type' => 'DocumentCategory',
            'type_label' => 'Document Categories',
            'type_singular' => 'Category',
        ]);
    }

    #[Route('/{type}/new', name: 'admin_enumeration_new', methods: ['GET', 'POST'])]
    public function new(Request $request, string $type): Response
    {
        $enumeration = new Enumeration();
        $enumeration->setType($type);

        if ($request->isMethod('POST')) {
            $enumeration->setName($request->request->get('name'));
            $enumeration->setIsDefault($request->request->has('is_default'));
            $enumeration->setActive($request->request->has('active'));
            $enumeration->setPosition((int) $request->request->get('position', 1));

            $this->entityManager->persist($enumeration);
            $this->entityManager->flush();

            $this->addFlash('success', 'Enumeration created successfully.');

            return $this->redirectToRoute($this->getRouteForType($type));
        }

        return $this->render('admin/enumeration/new.html.twig', [
            'enumeration' => $enumeration,
            'type' => $type,
            'type_label' => $this->getLabelForType($type),
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_enumeration_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Enumeration $enumeration): Response
    {
        if ($request->isMethod('POST')) {
            $enumeration->setName($request->request->get('name'));
            $enumeration->setIsDefault($request->request->has('is_default'));
            $enumeration->setActive($request->request->has('active'));
            $enumeration->setPosition((int) $request->request->get('position', 1));

            $this->entityManager->flush();

            $this->addFlash('success', 'Enumeration updated successfully.');

            return $this->redirectToRoute($this->getRouteForType($enumeration->getType()));
        }

        return $this->render('admin/enumeration/edit.html.twig', [
            'enumeration' => $enumeration,
            'type_label' => $this->getLabelForType($enumeration->getType()),
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_enumeration_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Enumeration $enumeration): Response
    {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_enumeration_' . $enumeration->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute($this->getRouteForType($enumeration->getType()));
        }

        $enumerationName = $enumeration->getName();
        $type = $enumeration->getType();

        $this->entityManager->remove($enumeration);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('"%s" deleted successfully.', $enumerationName));

        return $this->redirectToRoute($this->getRouteForType($type));
    }

    private function getRouteForType(string $type): string
    {
        return match ($type) {
            'IssuePriority' => 'admin_enumeration_priorities',
            'TimeEntryActivity' => 'admin_enumeration_activities',
            'DocumentCategory' => 'admin_enumeration_document_categories',
            default => 'admin_index',
        };
    }

    private function getLabelForType(string $type): string
    {
        return match ($type) {
            'IssuePriority' => 'Issue Priority',
            'TimeEntryActivity' => 'Time Entry Activity',
            'DocumentCategory' => 'Document Category',
            default => 'Enumeration',
        };
    }
}

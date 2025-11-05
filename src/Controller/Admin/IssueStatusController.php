<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\IssueStatus;
use App\Repository\IssueStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/issue-statuses')]
#[IsGranted('ROLE_ADMIN')]
class IssueStatusController extends AbstractController
{
    public function __construct(
        private IssueStatusRepository $issueStatusRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/', name: 'admin_issue_status_index')]
    public function index(): Response
    {
        $statuses = $this->issueStatusRepository->findBy([], ['position' => 'ASC']);

        return $this->render('admin/issue_status/index.html.twig', [
            'statuses' => $statuses,
        ]);
    }

    #[Route('/new', name: 'admin_issue_status_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $status = new IssueStatus();

        if ($request->isMethod('POST')) {
            $status->setName($request->request->get('name'));
            $status->setIsClosed($request->request->has('is_closed'));
            $status->setPosition((int) $request->request->get('position', 1));

            $this->entityManager->persist($status);
            $this->entityManager->flush();

            $this->addFlash('success', 'Issue status created successfully.');

            return $this->redirectToRoute('admin_issue_status_index');
        }

        return $this->render('admin/issue_status/new.html.twig', [
            'status' => $status,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_issue_status_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, IssueStatus $status): Response
    {
        if ($request->isMethod('POST')) {
            $status->setName($request->request->get('name'));
            $status->setIsClosed($request->request->has('is_closed'));
            $status->setPosition((int) $request->request->get('position', 1));

            $this->entityManager->flush();

            $this->addFlash('success', 'Issue status updated successfully.');

            return $this->redirectToRoute('admin_issue_status_index');
        }

        return $this->render('admin/issue_status/edit.html.twig', [
            'status' => $status,
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_issue_status_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, IssueStatus $status): Response
    {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_status_' . $status->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_issue_status_index');
        }

        $statusName = $status->getName();

        // TODO: Check if status is in use before deleting
        $this->entityManager->remove($status);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Issue status "%s" deleted successfully.', $statusName));

        return $this->redirectToRoute('admin_issue_status_index');
    }
}

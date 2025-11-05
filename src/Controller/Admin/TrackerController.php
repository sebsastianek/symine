<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Tracker;
use App\Repository\TrackerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/trackers')]
#[IsGranted('ROLE_ADMIN')]
class TrackerController extends AbstractController
{
    public function __construct(
        private TrackerRepository $trackerRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/', name: 'admin_tracker_index')]
    public function index(): Response
    {
        $trackers = $this->trackerRepository->findBy([], ['position' => 'ASC']);

        return $this->render('admin/tracker/index.html.twig', [
            'trackers' => $trackers,
        ]);
    }

    #[Route('/new', name: 'admin_tracker_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $tracker = new Tracker();

        if ($request->isMethod('POST')) {
            $tracker->setName($request->request->get('name'));
            $tracker->setDescription($request->request->get('description'));
            $tracker->setIsInRoadmap($request->request->has('is_in_roadmap'));
            $tracker->setPosition((int) $request->request->get('position', 1));

            $this->entityManager->persist($tracker);
            $this->entityManager->flush();

            $this->addFlash('success', 'Tracker created successfully.');

            return $this->redirectToRoute('admin_tracker_index');
        }

        return $this->render('admin/tracker/new.html.twig', [
            'tracker' => $tracker,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_tracker_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Tracker $tracker): Response
    {
        if ($request->isMethod('POST')) {
            $tracker->setName($request->request->get('name'));
            $tracker->setDescription($request->request->get('description'));
            $tracker->setIsInRoadmap($request->request->has('is_in_roadmap'));
            $tracker->setPosition((int) $request->request->get('position', 1));

            $this->entityManager->flush();

            $this->addFlash('success', 'Tracker updated successfully.');

            return $this->redirectToRoute('admin_tracker_index');
        }

        return $this->render('admin/tracker/edit.html.twig', [
            'tracker' => $tracker,
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_tracker_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Tracker $tracker): Response
    {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_tracker_' . $tracker->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_tracker_index');
        }

        $trackerName = $tracker->getName();

        // TODO: Check if tracker is in use before deleting
        $this->entityManager->remove($tracker);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Tracker "%s" deleted successfully.', $trackerName));

        return $this->redirectToRoute('admin_tracker_index');
    }
}

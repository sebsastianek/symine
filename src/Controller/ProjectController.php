<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Form\ProjectType;
use App\Repository\ProjectRepository;
use App\Security\Voter\ProjectVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects')]
class ProjectController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private EntityManagerInterface $entityManager
    ) {
    }
    
    #[Route('/', name: 'project_index')]
    public function index(): Response
    {
        // Get all projects that user can view
        $projects = $this->projectRepository->findAll();
        $viewableProjects = [];
        
        foreach ($projects as $project) {
            if ($this->isGranted(ProjectVoter::VIEW, $project)) {
                $viewableProjects[] = $project;
            }
        }
        
        return $this->render('project/index.html.twig', [
            'projects' => $viewableProjects,
        ]);
    }
    
    #[Route('/{id}', name: 'project_show', requirements: ['id' => '\d+'])]
    public function show(Project $project): Response
    {
        // Check if user can view this project
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        
        return $this->render('project/show.html.twig', [
            'project' => $project,
            'can_edit' => $this->isGranted(ProjectVoter::EDIT, $project),
            'can_manage_members' => $this->isGranted(ProjectVoter::MANAGE_MEMBERS, $project),
            'can_manage_versions' => $this->isGranted(ProjectVoter::MANAGE_VERSIONS, $project),
        ]);
    }
    
    #[Route('/{id}/edit', name: 'project_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, Project $project): Response
    {
        // Check if user can edit this project
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Project updated successfully.');

            return $this->redirectToRoute('project_show', ['id' => $project->getId()]);
        }

        return $this->render('project/edit.html.twig', [
            'project' => $project,
            'form' => $form,
        ]);
    }
    
    #[Route('/{id}/members', name: 'project_members', requirements: ['id' => '\d+'])]
    public function members(Project $project): Response
    {
        // Check if user can manage members
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE_MEMBERS, $project);
        
        return $this->render('project/members.html.twig', [
            'project' => $project,
        ]);
    }
    
    #[Route('/new', name: 'project_new')]
    #[IsGranted('global_project_create')]
    public function new(Request $request): Response
    {
        // User needs global project creation permission
        $project = new Project();

        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($project);
            $this->entityManager->flush();

            $this->addFlash('success', 'Project created successfully.');

            return $this->redirectToRoute('project_show', ['id' => $project->getId()]);
        }

        return $this->render('project/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'project_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Project $project): Response
    {
        // Check if user can delete this project
        $this->denyAccessUnlessGranted(ProjectVoter::DELETE, $project);

        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_project_' . $project->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('project_show', ['id' => $project->getId()]);
        }

        $projectName = $project->getName();

        $this->entityManager->remove($project);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Project "%s" deleted successfully.', $projectName));

        return $this->redirectToRoute('project_index');
    }
}
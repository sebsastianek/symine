<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Security\Voter\ProjectVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/projects')]
class ProjectController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository
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
    public function edit(Project $project): Response
    {
        // Check if user can edit this project
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        
        return $this->render('project/edit.html.twig', [
            'project' => $project,
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
    public function new(): Response
    {
        // User needs global project creation permission
        return $this->render('project/new.html.twig');
    }
}
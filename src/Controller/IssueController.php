<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Issue;
use App\Entity\Project;
use App\Repository\IssueRepository;
use App\Security\Voter\IssueVoter;
use App\Security\Voter\ProjectVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/projects/{projectId}/issues')]
class IssueController extends AbstractController
{
    public function __construct(
        private IssueRepository $issueRepository
    ) {
    }
    
    #[Route('/', name: 'issue_index', requirements: ['projectId' => '\d+'])]
    public function index(Project $project): Response
    {
        // Check if user can view the project
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        
        // Get all issues for this project
        $issues = $this->issueRepository->findBy(['project' => $project]);
        $viewableIssues = [];
        
        foreach ($issues as $issue) {
            if ($this->isGranted(IssueVoter::VIEW, $issue)) {
                $viewableIssues[] = $issue;
            }
        }
        
        return $this->render('issue/index.html.twig', [
            'project' => $project,
            'issues' => $viewableIssues,
            'can_create' => $this->isGranted(IssueVoter::CREATE, null),
        ]);
    }
    
    #[Route('/{id}', name: 'issue_show', requirements: ['projectId' => '\d+', 'id' => '\d+'])]
    public function show(Project $project, Issue $issue): Response
    {
        // Ensure issue belongs to the project
        if ($issue->getProject() !== $project) {
            throw $this->createNotFoundException('Issue not found in this project');
        }
        
        // Check if user can view this issue
        $this->denyAccessUnlessGranted(IssueVoter::VIEW, $issue);
        
        return $this->render('issue/show.html.twig', [
            'project' => $project,
            'issue' => $issue,
            'can_edit' => $this->isGranted(IssueVoter::EDIT, $issue),
            'can_delete' => $this->isGranted(IssueVoter::DELETE, $issue),
            'can_comment' => $this->isGranted(IssueVoter::COMMENT, $issue),
            'can_manage_watchers' => $this->isGranted(IssueVoter::MANAGE_WATCHERS, $issue),
        ]);
    }
    
    #[Route('/new', name: 'issue_new', requirements: ['projectId' => '\d+'])]
    public function new(Project $project): Response
    {
        // Check if user can view the project and create issues
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $this->denyAccessUnlessGranted(IssueVoter::CREATE, null);
        
        return $this->render('issue/new.html.twig', [
            'project' => $project,
        ]);
    }
    
    #[Route('/{id}/edit', name: 'issue_edit', requirements: ['projectId' => '\d+', 'id' => '\d+'])]
    public function edit(Project $project, Issue $issue): Response
    {
        // Ensure issue belongs to the project
        if ($issue->getProject() !== $project) {
            throw $this->createNotFoundException('Issue not found in this project');
        }
        
        // Check if user can edit this issue
        $this->denyAccessUnlessGranted(IssueVoter::EDIT, $issue);
        
        return $this->render('issue/edit.html.twig', [
            'project' => $project,
            'issue' => $issue,
        ]);
    }
}
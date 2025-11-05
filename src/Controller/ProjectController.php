<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Member;
use App\Entity\MemberRole;
use App\Form\ProjectType;
use App\Form\MemberType;
use App\Repository\ProjectRepository;
use App\Repository\MemberRepository;
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
        private MemberRepository $memberRepository,
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
    public function members(Request $request, Project $project): Response
    {
        // Check if user can manage members
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE_MEMBERS, $project);

        // Get all existing members
        $members = $this->memberRepository->findBy(['project' => $project]);

        // Create form for adding new member
        $member = new Member();
        $member->setProject($project);
        $member->setCreatedOn(new \DateTime());

        $form = $this->createForm(MemberType::class, $member, [
            'project' => $project,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Get selected roles from form
            $roles = $form->get('roles')->getData();

            // Create MemberRole for each selected role
            foreach ($roles as $role) {
                $memberRole = new MemberRole();
                $memberRole->setMember($member);
                $memberRole->setRole($role);
                $member->addMemberRole($memberRole);
            }

            $this->entityManager->persist($member);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('User "%s %s" added to project successfully.',
                $member->getUser()->getFirstname(),
                $member->getUser()->getLastname()
            ));

            return $this->redirectToRoute('project_members', ['id' => $project->getId()]);
        }

        return $this->render('project/members.html.twig', [
            'project' => $project,
            'members' => $members,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/members/{memberId}/remove', name: 'project_member_remove', requirements: ['id' => '\d+', 'memberId' => '\d+'], methods: ['POST'])]
    public function removeMember(Request $request, Project $project, int $memberId): Response
    {
        // Check if user can manage members
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE_MEMBERS, $project);

        $member = $this->memberRepository->find($memberId);

        if (!$member || $member->getProject()->getId() !== $project->getId()) {
            $this->addFlash('error', 'Member not found or does not belong to this project.');
            return $this->redirectToRoute('project_members', ['id' => $project->getId()]);
        }

        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('remove_member_' . $memberId, $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('project_members', ['id' => $project->getId()]);
        }

        $userName = sprintf('%s %s', $member->getUser()->getFirstname(), $member->getUser()->getLastname());

        $this->entityManager->remove($member);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('User "%s" removed from project successfully.', $userName));

        return $this->redirectToRoute('project_members', ['id' => $project->getId()]);
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
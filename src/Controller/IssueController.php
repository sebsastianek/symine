<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Issue;
use App\Entity\Project;
use App\Form\CommentType;
use App\Form\IssueType;
use App\Repository\IssueRepository;
use App\Security\Voter\IssueVoter;
use App\Security\Voter\ProjectVoter;
use App\Service\JournalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/projects/{projectId}/issues')]
class IssueController extends AbstractController
{
    public function __construct(
        private IssueRepository $issueRepository,
        private EntityManagerInterface $entityManager,
        private JournalService $journalService
    ) {
    }

    #[Route('/', name: 'issue_index', requirements: ['projectId' => '\d+'])]
    public function index(Project $project): Response
    {
        // Check if user can view the project
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        // Get all issues for this project
        $issues = $this->issueRepository->findBy(['project' => $project], ['id' => 'DESC']);
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

    #[Route('/{id}', name: 'issue_show', requirements: ['projectId' => '\d+', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(Request $request, Project $project, Issue $issue): Response
    {
        // Ensure issue belongs to the project
        if ($issue->getProject()->getId() !== $project->getId()) {
            throw $this->createNotFoundException('Issue not found in this project');
        }

        // Check if user can view this issue
        $this->denyAccessUnlessGranted(IssueVoter::VIEW, $issue);

        // Get journals for this issue
        $canViewPrivate = $this->isGranted(IssueVoter::VIEW_PRIVATE_NOTES, $issue);
        $journals = $this->journalService->getIssueJournals($issue, $canViewPrivate);

        // Get details for each journal
        $journalDetails = [];
        foreach ($journals as $journal) {
            $journalDetails[$journal->getId()] = $this->journalService->getJournalDetails($journal);
        }

        // Create comment form
        $commentForm = null;
        if ($this->isGranted(IssueVoter::COMMENT, $issue)) {
            $commentForm = $this->createForm(CommentType::class);
            $commentForm->handleRequest($request);

            if ($commentForm->isSubmitted() && $commentForm->isValid()) {
                $data = $commentForm->getData();

                $this->journalService->createComment(
                    $issue,
                    $this->getUser(),
                    $data['notes'],
                    $data['privateNotes'] ?? false
                );

                $this->entityManager->flush();

                $this->addFlash('success', 'Comment added successfully.');

                return $this->redirectToRoute('issue_show', [
                    'projectId' => $project->getId(),
                    'id' => $issue->getId(),
                ]);
            }
        }

        return $this->render('issue/show.html.twig', [
            'project' => $project,
            'issue' => $issue,
            'journals' => $journals,
            'journal_details' => $journalDetails,
            'comment_form' => $commentForm?->createView(),
            'can_edit' => $this->isGranted(IssueVoter::EDIT, $issue),
            'can_delete' => $this->isGranted(IssueVoter::DELETE, $issue),
            'can_comment' => $this->isGranted(IssueVoter::COMMENT, $issue),
            'can_manage_watchers' => $this->isGranted(IssueVoter::MANAGE_WATCHERS, $issue),
        ]);
    }

    #[Route('/new', name: 'issue_new', requirements: ['projectId' => '\d+'])]
    public function new(Request $request, Project $project): Response
    {
        // Check if user can view the project and create issues
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $this->denyAccessUnlessGranted(IssueVoter::CREATE, null);

        $issue = new Issue();
        $issue->setProject($project);

        $form = $this->createForm(IssueType::class, $issue, [
            'project' => $project,
            'user' => $this->getUser(),
            'is_new' => true,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Set author to current user
            $issue->setAuthor($this->getUser());

            // Set timestamps
            $now = new \DateTime();
            $issue->setCreatedOn($now);
            $issue->setUpdatedOn($now);

            // Persist the issue
            $this->entityManager->persist($issue);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Issue #%d created successfully.', $issue->getId()));

            return $this->redirectToRoute('issue_show', [
                'projectId' => $project->getId(),
                'id' => $issue->getId(),
            ]);
        }

        return $this->render('issue/new.html.twig', [
            'project' => $project,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'issue_edit', requirements: ['projectId' => '\d+', 'id' => '\d+'])]
    public function edit(Request $request, Project $project, Issue $issue): Response
    {
        // Ensure issue belongs to the project
        if ($issue->getProject()->getId() !== $project->getId()) {
            throw $this->createNotFoundException('Issue not found in this project');
        }

        // Check if user can edit this issue
        $this->denyAccessUnlessGranted(IssueVoter::EDIT, $issue);

        // Capture original data before changes
        $originalData = [
            'status' => $issue->getStatus()->getId(),
            'priority' => $issue->getPriority()->getId(),
            'assigned_to' => $issue->getAssignedTo()?->getId(),
            'subject' => $issue->getSubject(),
            'description' => $issue->getDescription(),
            'done_ratio' => $issue->getDoneRatio(),
            'start_date' => $issue->getStartDate()?->format('Y-m-d'),
            'due_date' => $issue->getDueDate()?->format('Y-m-d'),
        ];

        $form = $this->createForm(IssueType::class, $issue, [
            'project' => $project,
            'user' => $this->getUser(),
            'is_new' => false,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Update timestamp
            $issue->setUpdatedOn(new \DateTime());

            // Detect changes and create journal entry
            $changes = $this->journalService->detectIssueChanges($originalData, $issue);

            if (!empty($changes)) {
                $this->journalService->trackChanges(
                    $issue,
                    $this->getUser(),
                    $changes
                );
            }

            // Persist changes
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Issue #%d updated successfully.', $issue->getId()));

            return $this->redirectToRoute('issue_show', [
                'projectId' => $project->getId(),
                'id' => $issue->getId(),
            ]);
        }

        return $this->render('issue/edit.html.twig', [
            'project' => $project,
            'issue' => $issue,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/delete', name: 'issue_delete', requirements: ['projectId' => '\d+', 'id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Project $project, Issue $issue): Response
    {
        // Ensure issue belongs to the project
        if ($issue->getProject()->getId() !== $project->getId()) {
            throw $this->createNotFoundException('Issue not found in this project');
        }

        // Check if user can delete this issue
        $this->denyAccessUnlessGranted(IssueVoter::DELETE, $issue);

        // Verify CSRF token
        $token = $request->request->get('_token');
        if ($this->isCsrfTokenValid('delete_issue_' . $issue->getId(), $token)) {
            $issueId = $issue->getId();

            $this->entityManager->remove($issue);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Issue #%d deleted successfully.', $issueId));
        } else {
            $this->addFlash('error', 'Invalid CSRF token. Issue was not deleted.');
        }

        return $this->redirectToRoute('issue_index', [
            'projectId' => $project->getId(),
        ]);
    }
}

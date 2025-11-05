<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Workflow;
use App\Repository\WorkflowRepository;
use App\Repository\TrackerRepository;
use App\Repository\RoleRepository;
use App\Repository\IssueStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/workflows')]
#[IsGranted('ROLE_ADMIN')]
class WorkflowController extends AbstractController
{
    public function __construct(
        private WorkflowRepository $workflowRepository,
        private TrackerRepository $trackerRepository,
        private RoleRepository $roleRepository,
        private IssueStatusRepository $issueStatusRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('', name: 'admin_workflow_index', methods: ['GET'])]
    public function index(): Response
    {
        $trackers = $this->trackerRepository->findBy([], ['position' => 'ASC']);
        $roles = $this->roleRepository->findBy([], ['position' => 'ASC']);

        return $this->render('admin/workflow/index.html.twig', [
            'trackers' => $trackers,
            'roles' => $roles,
        ]);
    }

    #[Route('/edit', name: 'admin_workflow_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $trackerId = $request->query->getInt('tracker_id') ?: $request->request->getInt('tracker_id');
        $roleId = $request->query->getInt('role_id') ?: $request->request->getInt('role_id');

        if (!$trackerId || !$roleId) {
            $this->addFlash('error', 'Please select both a tracker and a role.');
            return $this->redirectToRoute('admin_workflow_index');
        }

        $tracker = $this->trackerRepository->find($trackerId);
        $role = $this->roleRepository->find($roleId);

        if (!$tracker || !$role) {
            $this->addFlash('error', 'Invalid tracker or role selected.');
            return $this->redirectToRoute('admin_workflow_index');
        }

        $statuses = $this->issueStatusRepository->findBy([], ['position' => 'ASC']);

        if ($request->isMethod('POST')) {
            // Clear existing workflows for this tracker/role combination
            $existingWorkflows = $this->workflowRepository->findBy([
                'tracker' => $tracker,
                'role' => $role,
            ]);

            foreach ($existingWorkflows as $workflow) {
                $this->entityManager->remove($workflow);
            }

            // Process the submitted transitions
            $transitions = $request->request->all('transitions');

            foreach ($transitions as $oldStatusId => $newStatusIds) {
                if ($oldStatusId === 'new') {
                    // New issues (no old status)
                    foreach ($newStatusIds as $newStatusId => $enabled) {
                        if ($enabled) {
                            $workflow = new Workflow();
                            $workflow->setTracker($tracker);
                            $workflow->setRole($role);
                            // For new issues, old_status_id should reference a special status or 0
                            // We'll need to find the "New" status or handle this specially
                            $oldStatus = $this->issueStatusRepository->find($newStatusId);
                            $newStatus = $this->issueStatusRepository->find($newStatusId);

                            if ($oldStatus && $newStatus) {
                                $workflow->setOldStatus($oldStatus);
                                $workflow->setNewStatus($newStatus);
                                $this->entityManager->persist($workflow);
                            }
                        }
                    }
                } else {
                    $oldStatus = $this->issueStatusRepository->find($oldStatusId);

                    if ($oldStatus) {
                        foreach ($newStatusIds as $newStatusId => $enabled) {
                            if ($enabled) {
                                $newStatus = $this->issueStatusRepository->find($newStatusId);

                                if ($newStatus) {
                                    $workflow = new Workflow();
                                    $workflow->setTracker($tracker);
                                    $workflow->setRole($role);
                                    $workflow->setOldStatus($oldStatus);
                                    $workflow->setNewStatus($newStatus);
                                    $this->entityManager->persist($workflow);
                                }
                            }
                        }
                    }
                }
            }

            $this->entityManager->flush();

            $this->addFlash('success', 'Workflow transitions updated successfully.');

            return $this->redirectToRoute('admin_workflow_edit', [
                'tracker_id' => $trackerId,
                'role_id' => $roleId,
            ]);
        }

        // Load existing workflows into a matrix
        $workflows = $this->workflowRepository->findBy([
            'tracker' => $tracker,
            'role' => $role,
        ]);

        $matrix = [];
        foreach ($workflows as $workflow) {
            $oldId = $workflow->getOldStatus()->getId();
            $newId = $workflow->getNewStatus()->getId();
            $matrix[$oldId][$newId] = true;
        }

        $trackers = $this->trackerRepository->findBy([], ['position' => 'ASC']);
        $roles = $this->roleRepository->findBy([], ['position' => 'ASC']);

        return $this->render('admin/workflow/edit.html.twig', [
            'tracker' => $tracker,
            'role' => $role,
            'trackers' => $trackers,
            'roles' => $roles,
            'statuses' => $statuses,
            'matrix' => $matrix,
        ]);
    }

    #[Route('/copy', name: 'admin_workflow_copy', methods: ['POST'])]
    public function copy(Request $request): Response
    {
        $sourceTrackerId = $request->request->getInt('source_tracker_id');
        $sourceRoleId = $request->request->getInt('source_role_id');
        $targetTrackerIds = $request->request->all('target_tracker_ids') ?: [];
        $targetRoleIds = $request->request->all('target_role_ids') ?: [];

        if (!$sourceTrackerId || !$sourceRoleId) {
            $this->addFlash('error', 'Please select source tracker and role.');
            return $this->redirectToRoute('admin_workflow_index');
        }

        if (empty($targetTrackerIds) || empty($targetRoleIds)) {
            $this->addFlash('error', 'Please select at least one target tracker and role.');
            return $this->redirectToRoute('admin_workflow_index');
        }

        $sourceTracker = $this->trackerRepository->find($sourceTrackerId);
        $sourceRole = $this->roleRepository->find($sourceRoleId);

        if (!$sourceTracker || !$sourceRole) {
            $this->addFlash('error', 'Invalid source tracker or role.');
            return $this->redirectToRoute('admin_workflow_index');
        }

        // Get source workflows
        $sourceWorkflows = $this->workflowRepository->findBy([
            'tracker' => $sourceTracker,
            'role' => $sourceRole,
        ]);

        if (empty($sourceWorkflows)) {
            $this->addFlash('error', 'No workflows found for the source tracker and role.');
            return $this->redirectToRoute('admin_workflow_index');
        }

        $copiedCount = 0;

        // Copy to each combination of target tracker and role
        foreach ($targetTrackerIds as $targetTrackerId) {
            $targetTracker = $this->trackerRepository->find($targetTrackerId);

            if (!$targetTracker) {
                continue;
            }

            foreach ($targetRoleIds as $targetRoleId) {
                $targetRole = $this->roleRepository->find($targetRoleId);

                if (!$targetRole) {
                    continue;
                }

                // Clear existing workflows for this target combination
                $existingWorkflows = $this->workflowRepository->findBy([
                    'tracker' => $targetTracker,
                    'role' => $targetRole,
                ]);

                foreach ($existingWorkflows as $workflow) {
                    $this->entityManager->remove($workflow);
                }

                // Copy source workflows
                foreach ($sourceWorkflows as $sourceWorkflow) {
                    $newWorkflow = new Workflow();
                    $newWorkflow->setTracker($targetTracker);
                    $newWorkflow->setRole($targetRole);
                    $newWorkflow->setOldStatus($sourceWorkflow->getOldStatus());
                    $newWorkflow->setNewStatus($sourceWorkflow->getNewStatus());
                    $newWorkflow->setAssignee($sourceWorkflow->getAssignee());
                    $newWorkflow->setAuthor($sourceWorkflow->getAuthor());

                    $this->entityManager->persist($newWorkflow);
                }

                $copiedCount++;
            }
        }

        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Workflows copied to %d tracker/role combinations.', $copiedCount));

        return $this->redirectToRoute('admin_workflow_index');
    }
}

<?php

declare(strict_types=1);

namespace App\Security\Service;

use App\Entity\User;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Workflow;
use App\Entity\IssueStatuse;
use App\Entity\Tracker;
use App\Repository\WorkflowRepository;
use App\Security\Service\PermissionService;
use Psr\Log\LoggerInterface;

/**
 * Service for handling workflow-based status transitions and permissions
 * 
 * Implements Redmine's workflow system where role-based permissions
 * determine which status transitions are allowed for users
 */
class WorkflowService
{
    public function __construct(
        private WorkflowRepository $workflowRepository,
        private PermissionService $permissionService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Check if user can transition issue from current status to new status
     */
    public function canTransitionStatus(User $user, Issue $issue, IssueStatuse $newStatus): bool
    {
        $project = $issue->getProject();
        $tracker = $issue->getTracker();
        $currentStatus = $issue->getStatus();

        // Admin users can always transition statuses
        if ($user->getAdmin()) {
            return true;
        }

        // Get user's roles for this project
        $userRoles = $this->permissionService->getUserRolesForProject($user, $project);

        // Check workflows for each role
        foreach ($userRoles as $role) {
            if ($this->isTransitionAllowed($tracker, $currentStatus, $newStatus, $role, $user, $issue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all allowed status transitions for user and issue
     */
    public function getAllowedTransitions(User $user, Issue $issue): array
    {
        $project = $issue->getProject();
        $tracker = $issue->getTracker();
        $currentStatus = $issue->getStatus();

        // Admin users can transition to any status
        if ($user->getAdmin()) {
            return $this->getAllStatusesForTracker($tracker);
        }

        $allowedStatuses = [];
        $userRoles = $this->permissionService->getUserRolesForProject($user, $project);

        // Get all possible status transitions
        $workflows = $this->workflowRepository->findBy([
            'tracker' => $tracker,
            'oldStatus' => $currentStatus
        ]);

        foreach ($workflows as $workflow) {
            foreach ($userRoles as $role) {
                if ($this->isWorkflowApplicable($workflow, $role, $user, $issue)) {
                    $allowedStatuses[] = $workflow->getNewStatus();
                }
            }
        }

        // Remove duplicates and return
        return array_unique($allowedStatuses, SORT_REGULAR);
    }

    /**
     * Check if user can edit issue fields based on current status and workflow
     */
    public function canEditField(User $user, Issue $issue, string $fieldName): bool
    {
        $project = $issue->getProject();
        $tracker = $issue->getTracker();
        $currentStatus = $issue->getStatus();

        // Admin users can always edit
        if ($user->getAdmin()) {
            return true;
        }

        // Basic edit permission check first
        if (!$this->permissionService->hasPermission($user, 'edit_issues', $project)) {
            return false;
        }

        // Get workflow field permissions
        $userRoles = $this->permissionService->getUserRolesForProject($user, $project);

        foreach ($userRoles as $role) {
            $workflows = $this->workflowRepository->findBy([
                'tracker' => $tracker,
                'oldStatus' => $currentStatus,
                'newStatus' => $currentStatus, // Same status = field editing
                'role' => $role,
                'type' => 'Field',
                'fieldName' => $fieldName
            ]);

            foreach ($workflows as $workflow) {
                if ($this->isWorkflowApplicable($workflow, $role, $user, $issue)) {
                    return $workflow->getRule() === 'required' || $workflow->getRule() === 'optional';
                }
            }
        }

        // Default: allow editing if no specific workflow restriction
        return true;
    }

    /**
     * Get required fields for status transition
     */
    public function getRequiredFieldsForTransition(User $user, Issue $issue, IssueStatuse $newStatus): array
    {
        $project = $issue->getProject();
        $tracker = $issue->getTracker();
        $currentStatus = $issue->getStatus();

        $requiredFields = [];
        $userRoles = $this->permissionService->getUserRolesForProject($user, $project);

        foreach ($userRoles as $role) {
            $workflows = $this->workflowRepository->findBy([
                'tracker' => $tracker,
                'oldStatus' => $currentStatus,
                'newStatus' => $newStatus,
                'role' => $role,
                'type' => 'Field',
                'rule' => 'required'
            ]);

            foreach ($workflows as $workflow) {
                if ($this->isWorkflowApplicable($workflow, $role, $user, $issue) && $workflow->getFieldName()) {
                    $requiredFields[] = $workflow->getFieldName();
                }
            }
        }

        return array_unique($requiredFields);
    }

    /**
     * Check if workflow rule is applicable for user and issue
     */
    private function isWorkflowApplicable($workflow, $role, User $user, Issue $issue): bool
    {
        // Check if workflow matches the user's role
        if ($workflow->getRole()->getId() !== $role->getId()) {
            return false;
        }

        // Check author constraint
        if ($workflow->getAuthor() && $issue->getAuthor()->getId() !== $user->getId()) {
            return false;
        }

        // Check assignee constraint
        if ($workflow->getAssignee() && $issue->getAssignedTo() && $issue->getAssignedTo()->getId() !== $user->getId()) {
            return false;
        }

        return true;
    }

    /**
     * Check if specific transition is allowed
     */
    private function isTransitionAllowed($tracker, $currentStatus, $newStatus, $role, User $user, Issue $issue): bool
    {
        $workflows = $this->workflowRepository->findBy([
            'tracker' => $tracker,
            'oldStatus' => $currentStatus,
            'newStatus' => $newStatus,
            'role' => $role
        ]);

        foreach ($workflows as $workflow) {
            if ($this->isWorkflowApplicable($workflow, $role, $user, $issue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all statuses available for a tracker
     */
    private function getAllStatusesForTracker($tracker): array
    {
        // This would typically query IssueStatuse repository
        // For now, return empty array as placeholder
        return [];
    }

    /**
     * Log workflow transition for audit purposes
     */
    public function logTransition(User $user, Issue $issue, $oldStatus, $newStatus): void
    {
        $this->logger->info('Issue status transition', [
            'user_id' => $user->getId(),
            'issue_id' => $issue->getId(),
            'project_id' => $issue->getProject()->getId(),
            'old_status_id' => $oldStatus ? $oldStatus->getId() : null,
            'new_status_id' => $newStatus->getId(),
            'tracker_id' => $issue->getTracker()->getId()
        ]);
    }
}
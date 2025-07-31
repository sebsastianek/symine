<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Issue;
use App\Entity\IssueStatuse;
use App\Security\Service\WorkflowService;
use App\Security\Service\PermissionService;
use App\Security\Service\AnonymousUserService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Voter for controlling issue status transitions based on workflow rules
 */
class StatusTransitionVoter extends BaseRedmineVoter
{
    public const TRANSITION = 'transition_status';

    public function __construct(
        protected PermissionService $permissionService,
        protected AnonymousUserService $anonymousUserService,
        private WorkflowService $workflowService
    ) {
        parent::__construct($permissionService, $anonymousUserService);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::TRANSITION && 
               is_array($subject) && 
               isset($subject['issue']) && 
               isset($subject['new_status']) &&
               $subject['issue'] instanceof Issue &&
               $subject['new_status'] instanceof IssueStatuse;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        // Anonymous users cannot transition statuses
        if ($this->isAnonymous($token)) {
            return false;
        }

        // Admin users can always transition statuses
        if ($this->isAdmin($token)) {
            return true;
        }

        $user = $this->getUser($token);
        if (!$user) {
            return false;
        }

        $issue = $subject['issue'];
        $newStatus = $subject['new_status'];

        // Check if user can access the project
        if (!$this->canUserAccessProject($token, $issue->getProject())) {
            return false;
        }

        // Use workflow service to check transition permissions
        return $this->workflowService->canTransitionStatus($user, $issue, $newStatus);
    }
}
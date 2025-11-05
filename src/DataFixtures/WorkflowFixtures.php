<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Workflow;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class WorkflowFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Bug Tracker Workflows
        
        // Bug: New -> In Progress (Manager, Developer)
        $workflow1 = new Workflow();
        $workflow1->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        $workflow1->setOldStatus($this->getReference('status-new', \App\Entity\IssueStatus::class));
        $workflow1->setNewStatus($this->getReference('status-in-progress', \App\Entity\IssueStatus::class));
        $workflow1->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        $workflow1->setAssignee(false);
        $workflow1->setAuthor(false);
        
        $manager->persist($workflow1);
        $this->addReference('workflow-bug-new-to-progress-manager', $workflow1);

        $workflow2 = new Workflow();
        $workflow2->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        $workflow2->setOldStatus($this->getReference('status-new', \App\Entity\IssueStatus::class));
        $workflow2->setNewStatus($this->getReference('status-in-progress', \App\Entity\IssueStatus::class));
        $workflow2->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $workflow2->setAssignee(true); // Only assignee can transition
        $workflow2->setAuthor(false);
        
        $manager->persist($workflow2);
        $this->addReference('workflow-bug-new-to-progress-dev', $workflow2);

        // Bug: In Progress -> Resolved (Developer, Tester)
        $workflow3 = new Workflow();
        $workflow3->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        $workflow3->setOldStatus($this->getReference('status-in-progress', \App\Entity\IssueStatus::class));
        $workflow3->setNewStatus($this->getReference('status-resolved', \App\Entity\IssueStatus::class));
        $workflow3->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $workflow3->setAssignee(true);
        $workflow3->setAuthor(false);
        
        $manager->persist($workflow3);
        $this->addReference('workflow-bug-progress-to-resolved-dev', $workflow3);

        $workflow4 = new Workflow();
        $workflow4->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        $workflow4->setOldStatus($this->getReference('status-in-progress', \App\Entity\IssueStatus::class));
        $workflow4->setNewStatus($this->getReference('status-resolved', \App\Entity\IssueStatus::class));
        $workflow4->setRole($this->getReference('role-tester', \App\Entity\Role::class));
        $workflow4->setAssignee(false);
        $workflow4->setAuthor(false);
        
        $manager->persist($workflow4);
        $this->addReference('workflow-bug-progress-to-resolved-tester', $workflow4);

        // Bug: Resolved -> Closed (Tester, Manager)
        $workflow5 = new Workflow();
        $workflow5->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        $workflow5->setOldStatus($this->getReference('status-resolved', \App\Entity\IssueStatus::class));
        $workflow5->setNewStatus($this->getReference('status-closed', \App\Entity\IssueStatus::class));
        $workflow5->setRole($this->getReference('role-tester', \App\Entity\Role::class));
        $workflow5->setAssignee(false);
        $workflow5->setAuthor(false);
        
        $manager->persist($workflow5);
        $this->addReference('workflow-bug-resolved-to-closed-tester', $workflow5);

        $workflow6 = new Workflow();
        $workflow6->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        $workflow6->setOldStatus($this->getReference('status-resolved', \App\Entity\IssueStatus::class));
        $workflow6->setNewStatus($this->getReference('status-closed', \App\Entity\IssueStatus::class));
        $workflow6->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        $workflow6->setAssignee(false);
        $workflow6->setAuthor(false);
        
        $manager->persist($workflow6);
        $this->addReference('workflow-bug-resolved-to-closed-manager', $workflow6);

        // Bug: Resolved -> In Progress (Tester - reopen by setting back to progress)
        $workflow7 = new Workflow();
        $workflow7->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        $workflow7->setOldStatus($this->getReference('status-resolved', \App\Entity\IssueStatus::class));
        $workflow7->setNewStatus($this->getReference('status-in-progress', \App\Entity\IssueStatus::class));
        $workflow7->setRole($this->getReference('role-tester', \App\Entity\Role::class));
        $workflow7->setAssignee(false);
        $workflow7->setAuthor(false);
        
        $manager->persist($workflow7);
        $this->addReference('workflow-bug-resolved-to-progress-reopen', $workflow7);

        // Bug: Closed -> In Progress (Manager - reopen closed issues)
        $workflow8 = new Workflow();
        $workflow8->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        $workflow8->setOldStatus($this->getReference('status-closed', \App\Entity\IssueStatus::class));
        $workflow8->setNewStatus($this->getReference('status-in-progress', \App\Entity\IssueStatus::class));
        $workflow8->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        $workflow8->setAssignee(false);
        $workflow8->setAuthor(false);
        
        $manager->persist($workflow8);
        $this->addReference('workflow-bug-closed-to-progress-reopen', $workflow8);

        // Feature Tracker Workflows
        
        // Feature: New -> In Progress (Manager, Developer)
        $workflow9 = new Workflow();
        $workflow9->setTracker($this->getReference('tracker-feature', \App\Entity\Tracker::class));
        $workflow9->setOldStatus($this->getReference('status-new', \App\Entity\IssueStatus::class));
        $workflow9->setNewStatus($this->getReference('status-in-progress', \App\Entity\IssueStatus::class));
        $workflow9->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        $workflow9->setAssignee(false);
        $workflow9->setAuthor(false);
        
        $manager->persist($workflow9);
        $this->addReference('workflow-feature-new-to-progress-manager', $workflow9);

        $workflow10 = new Workflow();
        $workflow10->setTracker($this->getReference('tracker-feature', \App\Entity\Tracker::class));
        $workflow10->setOldStatus($this->getReference('status-new', \App\Entity\IssueStatus::class));
        $workflow10->setNewStatus($this->getReference('status-in-progress', \App\Entity\IssueStatus::class));
        $workflow10->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $workflow10->setAssignee(true);
        $workflow10->setAuthor(false);
        
        $manager->persist($workflow10);
        $this->addReference('workflow-feature-new-to-progress-dev', $workflow10);

        // Feature: In Progress -> Resolved (Developer)
        $workflow11 = new Workflow();
        $workflow11->setTracker($this->getReference('tracker-feature', \App\Entity\Tracker::class));
        $workflow11->setOldStatus($this->getReference('status-in-progress', \App\Entity\IssueStatus::class));
        $workflow11->setNewStatus($this->getReference('status-resolved', \App\Entity\IssueStatus::class));
        $workflow11->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $workflow11->setAssignee(true);
        $workflow11->setAuthor(false);
        
        $manager->persist($workflow11);
        $this->addReference('workflow-feature-progress-to-resolved', $workflow11);

        // Feature: Resolved -> Closed (Manager, Tester)
        $workflow12 = new Workflow();
        $workflow12->setTracker($this->getReference('tracker-feature', \App\Entity\Tracker::class));
        $workflow12->setOldStatus($this->getReference('status-resolved', \App\Entity\IssueStatus::class));
        $workflow12->setNewStatus($this->getReference('status-closed', \App\Entity\IssueStatus::class));
        $workflow12->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        $workflow12->setAssignee(false);
        $workflow12->setAuthor(false);
        
        $manager->persist($workflow12);
        $this->addReference('workflow-feature-resolved-to-closed-manager', $workflow12);

        $workflow13 = new Workflow();
        $workflow13->setTracker($this->getReference('tracker-feature', \App\Entity\Tracker::class));
        $workflow13->setOldStatus($this->getReference('status-resolved', \App\Entity\IssueStatus::class));
        $workflow13->setNewStatus($this->getReference('status-closed', \App\Entity\IssueStatus::class));
        $workflow13->setRole($this->getReference('role-tester', \App\Entity\Role::class));
        $workflow13->setAssignee(false);
        $workflow13->setAuthor(false);
        
        $manager->persist($workflow13);
        $this->addReference('workflow-feature-resolved-to-closed-tester', $workflow13);

        // Support Tracker Workflows
        
        // Support: New -> In Progress (Manager, Developer, Tester)
        $workflow14 = new Workflow();
        $workflow14->setTracker($this->getReference('tracker-support', \App\Entity\Tracker::class));
        $workflow14->setOldStatus($this->getReference('status-new', \App\Entity\IssueStatus::class));
        $workflow14->setNewStatus($this->getReference('status-in-progress', \App\Entity\IssueStatus::class));
        $workflow14->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        $workflow14->setAssignee(false);
        $workflow14->setAuthor(false);
        
        $manager->persist($workflow14);
        $this->addReference('workflow-support-new-to-progress-manager', $workflow14);

        $workflow15 = new Workflow();
        $workflow15->setTracker($this->getReference('tracker-support', \App\Entity\Tracker::class));
        $workflow15->setOldStatus($this->getReference('status-new', \App\Entity\IssueStatus::class));
        $workflow15->setNewStatus($this->getReference('status-in-progress', \App\Entity\IssueStatus::class));
        $workflow15->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $workflow15->setAssignee(false);
        $workflow15->setAuthor(false);
        
        $manager->persist($workflow15);
        $this->addReference('workflow-support-new-to-progress-dev', $workflow15);

        // Support: In Progress -> Resolved (Anyone assigned)
        $workflow16 = new Workflow();
        $workflow16->setTracker($this->getReference('tracker-support', \App\Entity\Tracker::class));
        $workflow16->setOldStatus($this->getReference('status-in-progress', \App\Entity\IssueStatus::class));
        $workflow16->setNewStatus($this->getReference('status-resolved', \App\Entity\IssueStatus::class));
        $workflow16->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $workflow16->setAssignee(true);
        $workflow16->setAuthor(false);
        
        $manager->persist($workflow16);
        $this->addReference('workflow-support-progress-to-resolved', $workflow16);

        // Support: Resolved -> Closed (Manager or Author)
        $workflow17 = new Workflow();
        $workflow17->setTracker($this->getReference('tracker-support', \App\Entity\Tracker::class));
        $workflow17->setOldStatus($this->getReference('status-resolved', \App\Entity\IssueStatus::class));
        $workflow17->setNewStatus($this->getReference('status-closed', \App\Entity\IssueStatus::class));
        $workflow17->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        $workflow17->setAssignee(false);
        $workflow17->setAuthor(false);
        
        $manager->persist($workflow17);
        $this->addReference('workflow-support-resolved-to-closed-manager', $workflow17);

        $workflow18 = new Workflow();
        $workflow18->setTracker($this->getReference('tracker-support', \App\Entity\Tracker::class));
        $workflow18->setOldStatus($this->getReference('status-resolved', \App\Entity\IssueStatus::class));
        $workflow18->setNewStatus($this->getReference('status-closed', \App\Entity\IssueStatus::class));
        $workflow18->setRole($this->getReference('role-client', \App\Entity\Role::class));
        $workflow18->setAssignee(false);
        $workflow18->setAuthor(true); // Author can close their own support tickets
        
        $manager->persist($workflow18);
        $this->addReference('workflow-support-resolved-to-closed-author', $workflow18);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            TrackerFixtures::class,
            IssueStatusFixtures::class,
            RoleFixtures::class,
        ];
    }
}
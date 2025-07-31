<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ProjectsTracker;
use App\Entity\CustomFieldsProject;
use App\Entity\CustomFieldsTracker;
use App\Entity\QueriesRole;
use App\Entity\ChangesetsIssue;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class JunctionTableFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // ProjectsTracker: Enable different trackers for different projects
        $projectTracker1 = new ProjectsTracker();
        $projectTracker1->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $projectTracker1->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        
        $manager->persist($projectTracker1);
        $this->addReference('project-tracker-ecommerce-bug', $projectTracker1);

        $projectTracker2 = new ProjectsTracker();
        $projectTracker2->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $projectTracker2->setTracker($this->getReference('tracker-feature', \App\Entity\Tracker::class));
        
        $manager->persist($projectTracker2);
        $this->addReference('project-tracker-ecommerce-feature', $projectTracker2);

        $projectTracker3 = new ProjectsTracker();
        $projectTracker3->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $projectTracker3->setTracker($this->getReference('tracker-support', \App\Entity\Tracker::class));
        
        $manager->persist($projectTracker3);
        $this->addReference('project-tracker-ecommerce-support', $projectTracker3);

        $projectTracker4 = new ProjectsTracker();
        $projectTracker4->setProject($this->getReference('project-crm', \App\Entity\Project::class));
        $projectTracker4->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        
        $manager->persist($projectTracker4);
        $this->addReference('project-tracker-crm-bug', $projectTracker4);

        $projectTracker5 = new ProjectsTracker();
        $projectTracker5->setProject($this->getReference('project-crm', \App\Entity\Project::class));
        $projectTracker5->setTracker($this->getReference('tracker-feature', \App\Entity\Tracker::class));
        
        $manager->persist($projectTracker5);
        $this->addReference('project-tracker-crm-feature', $projectTracker5);

        $projectTracker6 = new ProjectsTracker();
        $projectTracker6->setProject($this->getReference('project-mobile', \App\Entity\Project::class));
        $projectTracker6->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        
        $manager->persist($projectTracker6);
        $this->addReference('project-tracker-mobile-bug', $projectTracker6);

        $projectTracker7 = new ProjectsTracker();
        $projectTracker7->setProject($this->getReference('project-mobile', \App\Entity\Project::class));
        $projectTracker7->setTracker($this->getReference('tracker-feature', \App\Entity\Tracker::class));
        
        $manager->persist($projectTracker7);
        $this->addReference('project-tracker-mobile-feature', $projectTracker7);

        $projectTracker8 = new ProjectsTracker();
        $projectTracker8->setProject($this->getReference('project-docs', \App\Entity\Project::class));
        $projectTracker8->setTracker($this->getReference('tracker-support', \App\Entity\Tracker::class));
        
        $manager->persist($projectTracker8);
        $this->addReference('project-tracker-docs-support', $projectTracker8);

        // Check if the junction table entities exist before creating fixtures
        try {
            // CustomFieldsProject: Enable custom fields for specific projects
            if (class_exists(\App\Entity\CustomFieldsProject::class)) {
                $cfProject1 = new CustomFieldsProject();
                // Note: This would need proper setters implemented
                // $cfProject1->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
                // $cfProject1->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
                // $manager->persist($cfProject1);
            }
        } catch (\Exception $e) {
            // Entity class doesn't exist or isn't properly configured
        }

        try {
            // CustomFieldsTracker: Enable custom fields for specific trackers
            if (class_exists(\App\Entity\CustomFieldsTracker::class)) {
                $cfTracker1 = new CustomFieldsTracker();
                // Note: This would need proper setters implemented
                // $cfTracker1->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
                // $cfTracker1->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
                // $manager->persist($cfTracker1);
            }
        } catch (\Exception $e) {
            // Entity class doesn't exist or isn't properly configured
        }

        try {
            // QueriesRole: Grant query access to specific roles
            if (class_exists(\App\Entity\QueriesRole::class)) {
                $queryRole1 = new QueriesRole();
                // Note: This would need proper setters implemented
                // $queryRole1->setQuery($this->getReference('query-my-open-issues', \App\Entity\Query::class));
                // $queryRole1->setRole($this->getReference('role-developer', \App\Entity\Role::class));
                // $manager->persist($queryRole1);
            }
        } catch (\Exception $e) {
            // Entity class doesn't exist or isn't properly configured
        }

        try {
            // ChangesetsIssue: Link changesets to issues
            if (class_exists(\App\Entity\ChangesetsIssue::class)) {
                $changesetIssue1 = new ChangesetsIssue();
                // Note: This would need proper setters implemented
                // $changesetIssue1->setChangeset($this->getReference('changeset-product-catalog', \App\Entity\Changeset::class));
                // $changesetIssue1->setIssue($this->getReference('issue-login-responsive', \App\Entity\Issue::class));
                // $manager->persist($changesetIssue1);
            }
        } catch (\Exception $e) {
            // Entity class doesn't exist or isn't properly configured
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ProjectFixtures::class,
            TrackerFixtures::class,
            CustomFieldFixtures::class,
            QueryFixtures::class,
            ChangesetFixtures::class,
            IssueFixtures::class,
            RoleFixtures::class,
        ];
    }
}
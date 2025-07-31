<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\TimeEntry;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class TimeEntryFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Time entries for Issue 1 (Authentication system)
        $timeEntry1 = new TimeEntry();
        $timeEntry1->setProject($this->getReference('project-backend', \App\Entity\Project::class));
        $timeEntry1->setIssue($this->getReference('issue-auth', \App\Entity\Issue::class));
        $timeEntry1->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $timeEntry1->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $timeEntry1->setActivity($this->getReference('activity-development', \App\Entity\Enumeration::class));
        $timeEntry1->setHours(8.5);
        $timeEntry1->setComments('Initial setup of authentication controllers and middleware');
        $spentDate1 = new \DateTime('2024-01-10');
        $timeEntry1->setSpentOn($spentDate1);
        $timeEntry1->setTyear((int)$spentDate1->format('Y'));
        $timeEntry1->setTmonth((int)$spentDate1->format('n'));
        $timeEntry1->setTweek((int)$spentDate1->format('W'));
        $timeEntry1->setCreatedOn(new \DateTime('2024-01-10 18:30:00'));
        $timeEntry1->setUpdatedOn(new \DateTime('2024-01-10 18:30:00'));
        
        $manager->persist($timeEntry1);

        $timeEntry2 = new TimeEntry();
        $timeEntry2->setProject($this->getReference('project-backend', \App\Entity\Project::class));
        $timeEntry2->setIssue($this->getReference('issue-auth', \App\Entity\Issue::class));
        $timeEntry2->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $timeEntry2->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $timeEntry2->setActivity($this->getReference('activity-development', \App\Entity\Enumeration::class));
        $timeEntry2->setHours(6.0);
        $timeEntry2->setComments('Implementing password hashing and session management');
        $timeEntry2->setSpentOn(new \DateTime('2024-01-15'));
        $timeEntry2->setTyear(2024);
        $timeEntry2->setTmonth(1);
        $timeEntry2->setTweek(3);
        $timeEntry2->setCreatedOn(new \DateTime('2024-01-15 17:00:00'));
        $timeEntry2->setUpdatedOn(new \DateTime('2024-01-15 17:00:00'));
        
        $manager->persist($timeEntry2);

        $timeEntry3 = new TimeEntry();
        $timeEntry3->setProject($this->getReference('project-backend', \App\Entity\Project::class));
        $timeEntry3->setIssue($this->getReference('issue-auth', \App\Entity\Issue::class));
        $timeEntry3->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        $timeEntry3->setAuthor($this->getReference('user-dbrown', \App\Entity\User::class));
        $timeEntry3->setActivity($this->getReference('activity-testing', \App\Entity\Enumeration::class));
        $timeEntry3->setHours(4.0);
        $timeEntry3->setComments('Testing authentication flows and security measures');
        $timeEntry3->setSpentOn(new \DateTime('2024-01-22'));
        $timeEntry3->setTyear(2024);
        $timeEntry3->setTmonth(1);
        $timeEntry3->setTweek(4);
        $timeEntry3->setCreatedOn(new \DateTime('2024-01-22 16:45:00'));
        $timeEntry3->setUpdatedOn(new \DateTime('2024-01-22 16:45:00'));
        
        $manager->persist($timeEntry3);

        // Time entries for Issue 2 (Frontend layout)
        $timeEntry4 = new TimeEntry();
        $timeEntry4->setProject($this->getReference('project-frontend', \App\Entity\Project::class));
        $timeEntry4->setIssue($this->getReference('issue-frontend-layout', \App\Entity\Issue::class));
        $timeEntry4->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $timeEntry4->setAuthor($this->getReference('user-sgarcia', \App\Entity\User::class));
        $timeEntry4->setActivity($this->getReference('activity-design', \App\Entity\Enumeration::class));
        $timeEntry4->setHours(3.5);
        $timeEntry4->setComments('Created wireframes and mockups for responsive layout');
        $timeEntry4->setSpentOn(new \DateTime('2024-02-02'));
        $timeEntry4->setTyear(2024);
        $timeEntry4->setTmonth(02);
        $timeEntry4->setTweek(5);
        $timeEntry4->setCreatedOn(new \DateTime('2024-02-02 18:00:00'));
        $timeEntry4->setUpdatedOn(new \DateTime('2024-02-02 18:00:00'));
        
        $manager->persist($timeEntry4);

        $timeEntry5 = new TimeEntry();
        $timeEntry5->setProject($this->getReference('project-frontend', \App\Entity\Project::class));
        $timeEntry5->setIssue($this->getReference('issue-frontend-layout', \App\Entity\Issue::class));
        $timeEntry5->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $timeEntry5->setAuthor($this->getReference('user-sgarcia', \App\Entity\User::class));
        $timeEntry5->setActivity($this->getReference('activity-development', \App\Entity\Enumeration::class));
        $timeEntry5->setHours(7.0);
        $timeEntry5->setComments('Implementing responsive CSS grid layout with Tailwind');
        $timeEntry5->setSpentOn(new \DateTime('2024-02-05'));
        $timeEntry5->setTyear(2024);
        $timeEntry5->setTmonth(02);
        $timeEntry5->setTweek(6);
        $timeEntry5->setCreatedOn(new \DateTime('2024-02-05 17:30:00'));
        $timeEntry5->setUpdatedOn(new \DateTime('2024-02-05 17:30:00'));
        
        $manager->persist($timeEntry5);

        // Time entries for Bug fixing
        $timeEntry6 = new TimeEntry();
        $timeEntry6->setProject($this->getReference('project-frontend', \App\Entity\Project::class));
        $timeEntry6->setIssue($this->getReference('issue-login-bug', \App\Entity\Issue::class));
        $timeEntry6->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $timeEntry6->setAuthor($this->getReference('user-sgarcia', \App\Entity\User::class));
        $timeEntry6->setActivity($this->getReference('activity-bugfixing', \App\Entity\Enumeration::class));
        $timeEntry6->setHours(2.5);
        $timeEntry6->setComments('Investigating form validation issue and fixing client-side checks');
        $timeEntry6->setSpentOn(new \DateTime('2024-02-16'));
        $timeEntry6->setTyear(2024);
        $timeEntry6->setTmonth(02);
        $timeEntry6->setTweek(7);
        $timeEntry6->setCreatedOn(new \DateTime('2024-02-16 14:15:00'));
        $timeEntry6->setUpdatedOn(new \DateTime('2024-02-16 14:15:00'));
        
        $manager->persist($timeEntry6);

        // Time entries for Documentation
        $timeEntry7 = new TimeEntry();
        $timeEntry7->setProject($this->getReference('project-docs', \App\Entity\Project::class));
        $timeEntry7->setIssue($this->getReference('issue-api-docs', \App\Entity\Issue::class));
        $timeEntry7->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $timeEntry7->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $timeEntry7->setActivity($this->getReference('activity-documentation', \App\Entity\Enumeration::class));
        $timeEntry7->setHours(5.0);
        $timeEntry7->setComments('Writing API endpoint documentation with examples');
        $timeEntry7->setSpentOn(new \DateTime('2024-02-25'));
        $timeEntry7->setTyear(2024);
        $timeEntry7->setTmonth(02);
        $timeEntry7->setTweek(8);
        $timeEntry7->setCreatedOn(new \DateTime('2024-02-25 16:00:00'));
        $timeEntry7->setUpdatedOn(new \DateTime('2024-02-25 16:00:00'));
        
        $manager->persist($timeEntry7);

        // Time entries for Mobile setup
        $timeEntry8 = new TimeEntry();
        $timeEntry8->setProject($this->getReference('project-mobile', \App\Entity\Project::class));
        $timeEntry8->setIssue($this->getReference('issue-mobile-setup', \App\Entity\Issue::class));
        $timeEntry8->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $timeEntry8->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $timeEntry8->setActivity($this->getReference('activity-development', \App\Entity\Enumeration::class));
        $timeEntry8->setHours(4.0);
        $timeEntry8->setComments('Setting up React Native project structure and navigation');
        $timeEntry8->setSpentOn(new \DateTime('2024-02-05'));
        $timeEntry8->setTyear(2024);
        $timeEntry8->setTmonth(02);
        $timeEntry8->setTweek(6);
        $timeEntry8->setCreatedOn(new \DateTime('2024-02-05 17:00:00'));
        $timeEntry8->setUpdatedOn(new \DateTime('2024-02-05 17:00:00'));
        
        $manager->persist($timeEntry8);

        // Meeting time entries
        $timeEntry9 = new TimeEntry();
        $timeEntry9->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $timeEntry9->setIssue(null); // General project time
        $timeEntry9->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $timeEntry9->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $timeEntry9->setActivity($this->getReference('activity-meeting', \App\Entity\Enumeration::class));
        $timeEntry9->setHours(2.0);
        $timeEntry9->setComments('Sprint planning meeting with development team');
        $timeEntry9->setSpentOn(new \DateTime('2024-02-12'));
        $timeEntry9->setTyear(2024);
        $timeEntry9->setTmonth(02);
        $timeEntry9->setTweek(7);
        $timeEntry9->setCreatedOn(new \DateTime('2024-02-12 11:00:00'));
        $timeEntry9->setUpdatedOn(new \DateTime('2024-02-12 11:00:00'));
        
        $manager->persist($timeEntry9);

        $timeEntry10 = new TimeEntry();
        $timeEntry10->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $timeEntry10->setIssue(null);
        $timeEntry10->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $timeEntry10->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $timeEntry10->setActivity($this->getReference('activity-meeting', \App\Entity\Enumeration::class));
        $timeEntry10->setHours(2.0);
        $timeEntry10->setComments('Sprint planning meeting - backend tasks discussion');
        $timeEntry10->setSpentOn(new \DateTime('2024-02-12'));
        $timeEntry10->setTyear(2024);
        $timeEntry10->setTmonth(02);
        $timeEntry10->setTweek(7);
        $timeEntry10->setCreatedOn(new \DateTime('2024-02-12 11:00:00'));
        $timeEntry10->setUpdatedOn(new \DateTime('2024-02-12 11:00:00'));
        
        $manager->persist($timeEntry10);

        // Research time entries
        $timeEntry11 = new TimeEntry();
        $timeEntry11->setProject($this->getReference('project-backend', \App\Entity\Project::class));
        $timeEntry11->setIssue($this->getReference('issue-security-private', \App\Entity\Issue::class));
        $timeEntry11->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $timeEntry11->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $timeEntry11->setActivity($this->getReference('activity-research', \App\Entity\Enumeration::class));
        $timeEntry11->setHours(3.0);
        $timeEntry11->setComments('Security assessment research and vulnerability analysis');
        $timeEntry11->setSpentOn(new \DateTime('2024-02-23'));
        $timeEntry11->setTyear(2024);
        $timeEntry11->setTmonth(02);
        $timeEntry11->setTweek(8);
        $timeEntry11->setCreatedOn(new \DateTime('2024-02-23 15:30:00'));
        $timeEntry11->setUpdatedOn(new \DateTime('2024-02-23 15:30:00'));
        
        $manager->persist($timeEntry11);

        // Support time entries
        $timeEntry12 = new TimeEntry();
        $timeEntry12->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $timeEntry12->setIssue($this->getReference('issue-support-login', \App\Entity\Issue::class));
        $timeEntry12->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        $timeEntry12->setAuthor($this->getReference('user-dbrown', \App\Entity\User::class));
        $timeEntry12->setActivity($this->getReference('activity-support', \App\Entity\Enumeration::class));
        $timeEntry12->setHours(1.5);
        $timeEntry12->setComments('Investigating customer login issues and checking account status');
        $timeEntry12->setSpentOn(new \DateTime('2024-02-28'));
        $timeEntry12->setTyear(2024);
        $timeEntry12->setTmonth(02);
        $timeEntry12->setTweek(9);
        $timeEntry12->setCreatedOn(new \DateTime('2024-02-28 16:00:00'));
        $timeEntry12->setUpdatedOn(new \DateTime('2024-02-28 16:00:00'));
        
        $manager->persist($timeEntry12);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            ProjectFixtures::class,
            IssueFixtures::class,
            EnumerationFixtures::class,
        ];
    }
}
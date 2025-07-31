<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Issue;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class IssueFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Issue 1: Setup user authentication system
        $issue1 = new Issue();
        $issue1->setSubject('Setup user authentication system');
        $issue1->setDescription('Implement user authentication with login, logout, and session management. Include password hashing and security features.');
        $issue1->setProject($this->getReference('project-backend', \App\Entity\Project::class));
        $issue1->setTracker($this->getReference('tracker-feature', \App\Entity\Tracker::class));
        $issue1->setStatus($this->getReference('status-resolved', \App\Entity\IssueStatuse::class));
        $issue1->setPriority($this->getReference('priority-high', \App\Entity\Enumeration::class));
        $issue1->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $issue1->setAssignedTo($this->getReference('user-mjohnson', \App\Entity\User::class));
        $issue1->setStartDate(new \DateTime('2024-01-10'));
        $issue1->setDueDate(new \DateTime('2024-01-25'));
        $issue1->setEstimatedHours(40.0);
        $issue1->setDoneRatio(100);
        $issue1->setIsPrivate(0);
        $issue1->setCreatedOn(new \DateTime('2024-01-08 09:30:00'));
        $issue1->setUpdatedOn(new \DateTime('2024-01-25 16:45:00'));
        $issue1->setClosedOn(new \DateTime('2024-01-25 16:45:00'));
        
        $manager->persist($issue1);
        $this->addReference('issue-auth', $issue1);

        // Issue 2: Design responsive frontend layout
        $issue2 = new Issue();
        $issue2->setSubject('Design responsive frontend layout');
        $issue2->setDescription('Create a mobile-responsive layout for the main application interface. Include navigation, sidebar, and content areas.');
        $issue2->setProject($this->getReference('project-frontend', \App\Entity\Project::class));
        $issue2->setTracker($this->getReference('tracker-task', \App\Entity\Tracker::class));
        $issue2->setStatus($this->getReference('status-in-progress', \App\Entity\IssueStatuse::class));
        $issue2->setPriority($this->getReference('priority-normal', \App\Entity\Enumeration::class));
        $issue2->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $issue2->setAssignedTo($this->getReference('user-sgarcia', \App\Entity\User::class));
        $issue2->setStartDate(new \DateTime('2024-02-01'));
        $issue2->setDueDate(new \DateTime('2024-02-20'));
        $issue2->setEstimatedHours(32.0);
        $issue2->setDoneRatio(45);
        $issue2->setIsPrivate(0);
        $issue2->setCreatedOn(new \DateTime('2024-01-28 14:20:00'));
        $issue2->setUpdatedOn(new \DateTime());
        
        $manager->persist($issue2);
        $this->addReference('issue-frontend-layout', $issue2);

        // Issue 3: Bug - Login form validation not working
        $issue3 = new Issue();
        $issue3->setSubject('Login form validation not working properly');
        $issue3->setDescription('The client-side validation for the login form is not functioning correctly. Empty fields are allowed to be submitted.');
        $issue3->setProject($this->getReference('project-frontend', \App\Entity\Project::class));
        $issue3->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        $issue3->setStatus($this->getReference('status-testing', \App\Entity\IssueStatuse::class));
        $issue3->setPriority($this->getReference('priority-urgent', \App\Entity\Enumeration::class));
        $issue3->setAuthor($this->getReference('user-dbrown', \App\Entity\User::class));
        $issue3->setAssignedTo($this->getReference('user-sgarcia', \App\Entity\User::class));
        $issue3->setStartDate(new \DateTime('2024-02-15'));
        $issue3->setDueDate(new \DateTime('2024-02-18'));
        $issue3->setEstimatedHours(8.0);
        $issue3->setDoneRatio(80);
        $issue3->setIsPrivate(0);
        $issue3->setCreatedOn(new \DateTime('2024-02-14 11:15:00'));
        $issue3->setUpdatedOn(new \DateTime());
        
        $manager->persist($issue3);
        $this->addReference('issue-login-bug', $issue3);

        // Issue 4: Parent issue - Mobile app development
        $issue4 = new Issue();
        $issue4->setSubject('Develop mobile application');
        $issue4->setDescription('Create a cross-platform mobile application for iOS and Android using React Native.');
        $issue4->setProject($this->getReference('project-mobile', \App\Entity\Project::class));
        $issue4->setTracker($this->getReference('tracker-feature', \App\Entity\Tracker::class));
        $issue4->setStatus($this->getReference('status-in-progress', \App\Entity\IssueStatuse::class));
        $issue4->setPriority($this->getReference('priority-high', \App\Entity\Enumeration::class));
        $issue4->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $issue4->setAssignedTo($this->getReference('user-mjohnson', \App\Entity\User::class));
        $issue4->setStartDate(new \DateTime('2024-02-01'));
        $issue4->setDueDate(new \DateTime('2024-04-30'));
        $issue4->setEstimatedHours(200.0);
        $issue4->setDoneRatio(25);
        $issue4->setIsPrivate(0);
        $issue4->setCreatedOn(new \DateTime('2024-01-30 10:00:00'));
        $issue4->setUpdatedOn(new \DateTime());
        
        $manager->persist($issue4);
        $this->addReference('issue-mobile-parent', $issue4);

        // Issue 5: Child issue - Setup React Native project
        $issue5 = new Issue();
        $issue5->setSubject('Setup React Native project structure');
        $issue5->setDescription('Initialize React Native project with proper folder structure, navigation, and basic components.');
        $issue5->setProject($this->getReference('project-mobile', \App\Entity\Project::class));
        $issue5->setTracker($this->getReference('tracker-task', \App\Entity\Tracker::class));
        $issue5->setStatus($this->getReference('status-resolved', \App\Entity\IssueStatuse::class));
        $issue5->setPriority($this->getReference('priority-normal', \App\Entity\Enumeration::class));
        $issue5->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $issue5->setAssignedTo($this->getReference('user-mjohnson', \App\Entity\User::class));
        $issue5->setParent($issue4); // Child of mobile app development
        $issue5->setStartDate(new \DateTime('2024-02-01'));
        $issue5->setDueDate(new \DateTime('2024-02-10'));
        $issue5->setEstimatedHours(16.0);
        $issue5->setDoneRatio(100);
        $issue5->setIsPrivate(0);
        $issue5->setCreatedOn(new \DateTime('2024-02-01 09:00:00'));
        $issue5->setUpdatedOn(new \DateTime('2024-02-10 17:30:00'));
        $issue5->setClosedOn(new \DateTime('2024-02-10 17:30:00'));
        
        $manager->persist($issue5);
        $this->addReference('issue-mobile-setup', $issue5);

        // Issue 6: CRM System feature
        $issue6 = new Issue();
        $issue6->setSubject('Implement customer contact management');
        $issue6->setDescription('Create interface for managing customer contacts with CRUD operations, search, and filtering capabilities.');
        $issue6->setProject($this->getReference('project-crm', \App\Entity\Project::class));
        $issue6->setTracker($this->getReference('tracker-feature', \App\Entity\Tracker::class));
        $issue6->setStatus($this->getReference('status-new', \App\Entity\IssueStatuse::class));
        $issue6->setPriority($this->getReference('priority-normal', \App\Entity\Enumeration::class));
        $issue6->setAuthor($this->getReference('user-alee', \App\Entity\User::class));
        $issue6->setAssignedTo($this->getReference('user-sgarcia', \App\Entity\User::class));
        $issue6->setStartDate(new \DateTime('2024-03-01'));
        $issue6->setDueDate(new \DateTime('2024-03-20'));
        $issue6->setEstimatedHours(50.0);
        $issue6->setDoneRatio(0);
        $issue6->setIsPrivate(0);
        $issue6->setCreatedOn(new \DateTime('2024-02-25 13:45:00'));
        $issue6->setUpdatedOn(new \DateTime());
        
        $manager->persist($issue6);
        $this->addReference('issue-crm-contacts', $issue6);

        // Issue 7: Documentation task
        $issue7 = new Issue();
        $issue7->setSubject('Create API documentation');
        $issue7->setDescription('Document all REST API endpoints with examples, request/response formats, and authentication requirements.');
        $issue7->setProject($this->getReference('project-docs', \App\Entity\Project::class));
        $issue7->setTracker($this->getReference('tracker-documentation', \App\Entity\Tracker::class));
        $issue7->setStatus($this->getReference('status-under-review', \App\Entity\IssueStatuse::class));
        $issue7->setPriority($this->getReference('priority-normal', \App\Entity\Enumeration::class));
        $issue7->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $issue7->setAssignedTo($this->getReference('user-mjohnson', \App\Entity\User::class));
        $issue7->setStartDate(new \DateTime('2024-02-20'));
        $issue7->setDueDate(new \DateTime('2024-03-10'));
        $issue7->setEstimatedHours(24.0);
        $issue7->setDoneRatio(70);
        $issue7->setIsPrivate(0);
        $issue7->setCreatedOn(new \DateTime('2024-02-18 16:00:00'));
        $issue7->setUpdatedOn(new \DateTime());
        
        $manager->persist($issue7);
        $this->addReference('issue-api-docs', $issue7);

        // Issue 8: Private issue
        $issue8 = new Issue();
        $issue8->setSubject('Security vulnerability assessment');
        $issue8->setDescription('Conduct security assessment of the authentication system and identify potential vulnerabilities.');
        $issue8->setProject($this->getReference('project-backend', \App\Entity\Project::class));
        $issue8->setTracker($this->getReference('tracker-research', \App\Entity\Tracker::class));
        $issue8->setStatus($this->getReference('status-in-progress', \App\Entity\IssueStatuse::class));
        $issue8->setPriority($this->getReference('priority-urgent', \App\Entity\Enumeration::class));
        $issue8->setAuthor($this->getReference('user-admin', \App\Entity\User::class));
        $issue8->setAssignedTo($this->getReference('user-jsmith', \App\Entity\User::class));
        $issue8->setStartDate(new \DateTime('2024-02-22'));
        $issue8->setDueDate(new \DateTime('2024-03-05'));
        $issue8->setEstimatedHours(16.0);
        $issue8->setDoneRatio(30);
        $issue8->setIsPrivate(1); // Private issue
        $issue8->setCreatedOn(new \DateTime('2024-02-22 08:30:00'));
        $issue8->setUpdatedOn(new \DateTime());
        
        $manager->persist($issue8);
        $this->addReference('issue-security-private', $issue8);

        // Issue 9: Support request
        $issue9 = new Issue();
        $issue9->setSubject('Customer reporting login issues');
        $issue9->setDescription('Customer reports they cannot log in with their credentials. Need to investigate account status and password reset functionality.');
        $issue9->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $issue9->setTracker($this->getReference('tracker-support', \App\Entity\Tracker::class));
        $issue9->setStatus($this->getReference('status-needs-info', \App\Entity\IssueStatuse::class));
        $issue9->setPriority($this->getReference('priority-high', \App\Entity\Enumeration::class));
        $issue9->setAuthor($this->getReference('user-alee', \App\Entity\User::class));
        $issue9->setAssignedTo($this->getReference('user-dbrown', \App\Entity\User::class));
        $issue9->setStartDate(new \DateTime('2024-02-28'));
        $issue9->setDueDate(new \DateTime('2024-03-01'));
        $issue9->setEstimatedHours(4.0);
        $issue9->setDoneRatio(20);
        $issue9->setIsPrivate(0);
        $issue9->setCreatedOn(new \DateTime('2024-02-28 14:30:00'));
        $issue9->setUpdatedOn(new \DateTime());
        
        $manager->persist($issue9);
        $this->addReference('issue-support-login', $issue9);

        // Issue 10: On hold issue
        $issue10 = new Issue();
        $issue10->setSubject('Integrate payment gateway');
        $issue10->setDescription('Integrate Stripe payment gateway for processing customer payments. Waiting for API access approval.');
        $issue10->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $issue10->setTracker($this->getReference('tracker-feature', \App\Entity\Tracker::class));
        $issue10->setStatus($this->getReference('status-on-hold', \App\Entity\IssueStatuse::class));
        $issue10->setPriority($this->getReference('priority-high', \App\Entity\Enumeration::class));
        $issue10->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $issue10->setAssignedTo($this->getReference('user-mjohnson', \App\Entity\User::class));
        $issue10->setStartDate(new \DateTime('2024-03-05'));
        $issue10->setDueDate(new \DateTime('2024-03-25'));
        $issue10->setEstimatedHours(60.0);
        $issue10->setDoneRatio(10);
        $issue10->setIsPrivate(0);
        $issue10->setCreatedOn(new \DateTime('2024-02-20 11:00:00'));
        $issue10->setUpdatedOn(new \DateTime());
        
        $manager->persist($issue10);
        $this->addReference('issue-payment-gateway', $issue10);

        $manager->flush();

        // Update parent issue relationships after all issues are persisted
        $issue4->getChildren()->add($issue5);
        $issue5->setRoot($issue4);
        
        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            ProjectFixtures::class,
            TrackerFixtures::class,
            IssueStatusFixtures::class,
            EnumerationFixtures::class,
        ];
    }
}
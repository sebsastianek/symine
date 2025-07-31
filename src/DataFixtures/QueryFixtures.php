<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Query;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class QueryFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // My Open Issues (Personal query for John Smith)
        $query1 = new Query();
        $query1->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $query1->setProject(null); // Global query
        $query1->setName('My Open Issues');
        $query1->setFilters(json_encode([
            'status_id' => ['o'], // Open issues
            'assigned_to_id' => ['me'], // Assigned to me
        ]));
        $query1->setColumnNames(json_encode([
            'tracker', 'status', 'priority', 'subject', 'project', 'due_date', 'updated_on'
        ]));
        $query1->setSortCriteria(json_encode([
            ['priority', 'desc'],
            ['updated_on', 'desc']
        ]));
        $query1->setType('IssueQuery');
        $query1->setVisibility(0); // Private
        $query1->setDescription('All issues assigned to me that are currently open');
        
        $manager->persist($query1);
        $this->addReference('query-my-open-issues', $query1);

        // High Priority Bugs (Project query for E-commerce)
        $query2 = new Query();
        $query2->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $query2->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $query2->setName('High Priority Bugs');
        $query2->setFilters(json_encode([
            'status_id' => ['o'], // Open issues
            'tracker_id' => ['1'], // Bug tracker
            'priority_id' => ['4', '5'], // High and Urgent priorities
        ]));
        $query2->setColumnNames(json_encode([
            'status', 'priority', 'subject', 'assigned_to', 'created_on', 'due_date'
        ]));
        $query2->setSortCriteria(json_encode([
            ['priority', 'desc'],
            ['created_on', 'asc']
        ]));
        $query2->setType('IssueQuery');
        $query2->setVisibility(2); // Public for project
        $query2->setDescription('Critical and high priority bugs in the e-commerce project');
        
        $manager->persist($query2);
        $this->addReference('query-high-priority-bugs', $query2);

        // Features Due This Week (Global query)
        $query3 = new Query();
        $query3->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $query3->setProject(null);
        $query3->setName('Features Due This Week');
        $query3->setFilters(json_encode([
            'tracker_id' => ['2'], // Feature tracker
            'due_date' => ['w'], // This week
            'status_id' => ['o'], // Open
        ]));
        $query3->setColumnNames(json_encode([
            'project', 'subject', 'assigned_to', 'status', 'done_ratio', 'due_date'
        ]));
        $query3->setSortCriteria(json_encode([
            ['due_date', 'asc']
        ]));
        $query3->setGroupBy('project');
        $query3->setType('IssueQuery');
        $query3->setVisibility(1); // Public for all projects
        $query3->setDescription('All feature requests due within the current week');
        
        $manager->persist($query3);
        $this->addReference('query-features-due-week', $query3);

        // Overdue Issues (Global public query)
        $query4 = new Query();
        $query4->setUser($this->getReference('user-admin', \App\Entity\User::class));
        $query4->setProject(null);
        $query4->setName('Overdue Issues');
        $query4->setFilters(json_encode([
            'status_id' => ['o'], // Open
            'due_date' => ['<t-'], // Past due
        ]));
        $query4->setColumnNames(json_encode([
            'project', 'tracker', 'status', 'priority', 'subject', 'assigned_to', 'due_date', 'delay'
        ]));
        $query4->setSortCriteria(json_encode([
            ['due_date', 'asc']
        ]));
        $query4->setType('IssueQuery');
        $query4->setVisibility(1); // Public
        $query4->setDescription('All issues that are past their due date');
        
        $manager->persist($query4);
        $this->addReference('query-overdue-issues', $query4);

        // Testing Queue (QA team query)
        $query5 = new Query();
        $query5->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        $query5->setProject(null);
        $query5->setName('Testing Queue');
        $query5->setFilters(json_encode([
            'status_id' => ['4'], // Testing status
            'assigned_to_id' => ['me', $this->getReference('user-dbrown', \App\Entity\User::class)->getId()],
        ]));
        $query5->setColumnNames(json_encode([
            'project', 'tracker', 'subject', 'author', 'created_on', 'estimated_hours'
        ]));
        $query5->setSortCriteria(json_encode([
            ['created_on', 'asc']
        ]));
        $query5->setType('IssueQuery');
        $query5->setVisibility(0); // Private
        $query5->setDescription('Issues currently in testing status assigned to QA team');
        
        $manager->persist($query5);
        $this->addReference('query-testing-queue', $query5);

        // Recently Closed (CRM project)
        $query6 = new Query();
        $query6->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $query6->setProject($this->getReference('project-crm', \App\Entity\Project::class));
        $query6->setName('Recently Closed');
        $query6->setFilters(json_encode([
            'status_id' => ['c'], // Closed
            'closed' => ['<>'], // Within last 30 days
        ]));
        $query6->setColumnNames(json_encode([
            'tracker', 'subject', 'assigned_to', 'closed_on', 'spent_time'
        ]));
        $query6->setSortCriteria(json_encode([
            ['closed_on', 'desc']
        ]));
        $query6->setType('IssueQuery');
        $query6->setVisibility(2); // Public for project
        $query6->setDescription('Issues closed within the last 30 days in CRM project');
        
        $manager->persist($query6);
        $this->addReference('query-recently-closed', $query6);

        // Mobile App Issues (Project specific)
        $query7 = new Query();
        $query7->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $query7->setProject($this->getReference('project-mobile', \App\Entity\Project::class));
        $query7->setName('All Mobile Issues');
        $query7->setFilters(json_encode([
            'status_id' => ['*'], // All statuses
        ]));
        $query7->setColumnNames(json_encode([
            'tracker', 'status', 'priority', 'subject', 'assigned_to', 'done_ratio', 'created_on'
        ]));
        $query7->setSortCriteria(json_encode([
            ['created_on', 'desc']
        ]));
        $query7->setGroupBy('status');
        $query7->setType('IssueQuery');
        $query7->setVisibility(2); // Public for project
        $query7->setDescription('Complete view of all issues in the mobile application project');
        
        $manager->persist($query7);
        $this->addReference('query-mobile-all', $query7);

        // Support Issues for Alice (Client view)
        $query8 = new Query();
        $query8->setUser($this->getReference('user-alee', \App\Entity\User::class));
        $query8->setProject(null);
        $query8->setName('My Support Requests');
        $query8->setFilters(json_encode([
            'tracker_id' => ['3'], // Support tracker
            'author_id' => ['me'], // Created by me
        ]));
        $query8->setColumnNames(json_encode([
            'project', 'status', 'priority', 'subject', 'assigned_to', 'created_on', 'updated_on'
        ]));
        $query8->setSortCriteria(json_encode([
            ['updated_on', 'desc']
        ]));
        $query8->setType('IssueQuery');
        $query8->setVisibility(0); // Private
        $query8->setDescription('All support requests I have submitted');
        
        $manager->persist($query8);
        $this->addReference('query-my-support', $query8);

        // Time Tracking Query (for reports)
        $query9 = new Query();
        $query9->setUser($this->getReference('user-admin', \App\Entity\User::class));
        $query9->setProject(null);
        $query9->setName('Time Tracking Report');
        $query9->setFilters(json_encode([
            'status_id' => ['*'], // All statuses
            'spent_time' => ['>0'], // Has time logged
        ]));
        $query9->setColumnNames(json_encode([
            'project', 'tracker', 'subject', 'assigned_to', 'spent_time', 'estimated_hours'
        ]));
        $query9->setSortCriteria(json_encode([
            ['spent_time', 'desc']
        ]));
        $query9->setGroupBy('project');
        $query9->setType('IssueQuery');
        $query9->setVisibility(1); // Public
        $query9->setDescription('Issues with time tracking information for reporting');
        $query9->setOptions(json_encode([
            'totalable_names' => ['spent_time', 'estimated_hours'],
            'draw_relations' => true,
            'draw_progress_line' => true
        ]));
        
        $manager->persist($query9);
        $this->addReference('query-time-tracking', $query9);

        // Custom Fields Query (Advanced filtering)
        $query10 = new Query();
        $query10->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $query10->setProject(null);
        $query10->setName('High Business Priority');
        $query10->setFilters(json_encode([
            'status_id' => ['o'], // Open
            'cf_1' => ['High', 'Critical'], // Business Priority custom field
        ]));
        $query10->setColumnNames(json_encode([
            'project', 'tracker', 'subject', 'assigned_to', 'cf_1', 'cf_2', 'due_date'
        ]));
        $query10->setSortCriteria(json_encode([
            ['cf_1', 'desc'], // Sort by business priority
            ['due_date', 'asc']
        ]));
        $query10->setType('IssueQuery');
        $query10->setVisibility(1); // Public
        $query10->setDescription('Issues with high business priority using custom fields');
        
        $manager->persist($query10);
        $this->addReference('query-high-business-priority', $query10);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            ProjectFixtures::class,
            CustomFieldFixtures::class,
        ];
    }
}
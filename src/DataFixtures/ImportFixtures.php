<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Import;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ImportFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // User import (CSV)
        $import1 = new Import();
        $import1->setType('UserImport');
        $import1->setUser($this->getReference('user-admin', \App\Entity\User::class));
        $import1->setFilename('users_export_2024.csv');
        $import1->setSettings(json_encode([
            'separator' => ',',
            'wrapper' => '"',
            'encoding' => 'UTF-8',
            'date_format' => '%Y-%m-%d',
            'notifications' => false,
            'mapping' => [
                'login' => 0,
                'firstname' => 1,
                'lastname' => 2,
                'mail' => 3,
                'language' => 4,
                'admin' => 5,
                'auth_source' => 6
            ]
        ]));
        $import1->setTotalItems(150);
        $import1->setFinished(1);
        $import1->setCreatedAt(new \DateTime('2024-01-10 09:30:00'));
        $import1->setUpdatedAt(new \DateTime('2024-01-10 09:45:00'));
        
        $manager->persist($import1);
        $this->addReference('import-users', $import1);

        // Issue import (CSV)
        $import2 = new Import();
        $import2->setType('IssueImport');
        $import2->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $import2->setFilename('legacy_issues_migration.csv');
        $import2->setSettings(json_encode([
            'separator' => ';',
            'wrapper' => '"',
            'encoding' => 'UTF-8',
            'date_format' => '%d/%m/%Y',
            'notifications' => true,
            'project_id' => 1,
            'tracker_id' => 1,
            'status_id' => 1,
            'mapping' => [
                'subject' => 0,
                'description' => 1,
                'status' => 2,
                'priority' => 3,
                'assigned_to' => 4,
                'category' => 5,
                'target_version' => 6,
                'start_date' => 7,
                'due_date' => 8,
                'estimated_hours' => 9,
                'done_ratio' => 10
            ]
        ]));
        $import2->setTotalItems(85);
        $import2->setFinished(1);
        $import2->setCreatedAt(new \DateTime('2024-01-15 14:20:00'));
        $import2->setUpdatedAt(new \DateTime('2024-01-15 15:10:00'));
        
        $manager->persist($import2);
        $this->addReference('import-issues', $import2);

        // Time entries import (CSV)
        $import3 = new Import();
        $import3->setType('TimeEntryImport');
        $import3->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $import3->setFilename('time_tracking_Q4_2023.csv');
        $import3->setSettings(json_encode([
            'separator' => ',',
            'wrapper' => '"',
            'encoding' => 'UTF-8',
            'date_format' => '%Y-%m-%d',
            'notifications' => false,
            'mapping' => [
                'spent_on' => 0,
                'hours' => 1,
                'activity' => 2,
                'issue_id' => 3,
                'project_id' => 4,
                'user_id' => 5,
                'comments' => 6
            ]
        ]));
        $import3->setTotalItems(320);
        $import3->setFinished(1);
        $import3->setCreatedAt(new \DateTime('2024-01-20 11:00:00'));
        $import3->setUpdatedAt(new \DateTime('2024-01-20 12:30:00'));
        
        $manager->persist($import3);
        $this->addReference('import-time-entries', $import3);

        // Project import (XML)
        $import4 = new Import();
        $import4->setType('ProjectImport');
        $import4->setUser($this->getReference('user-admin', \App\Entity\User::class));
        $import4->setFilename('redmine_export_projects.xml');
        $import4->setSettings(json_encode([
            'format' => 'xml',
            'encoding' => 'UTF-8',
            'notifications' => true,
            'include_members' => true,
            'include_issues' => false,
            'include_wiki' => true,
            'include_versions' => true
        ]));
        $import4->setTotalItems(12);
        $import4->setFinished(1);
        $import4->setCreatedAt(new \DateTime('2024-01-25 16:45:00'));
        $import4->setUpdatedAt(new \DateTime('2024-01-25 17:15:00'));
        
        $manager->persist($import4);
        $this->addReference('import-projects', $import4);

        // Failed import example
        $import5 = new Import();
        $import5->setType('IssueImport');
        $import5->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $import5->setFilename('corrupted_data.csv');
        $import5->setSettings(json_encode([
            'separator' => ',',
            'wrapper' => '"',
            'encoding' => 'UTF-8',
            'date_format' => '%Y-%m-%d',
            'notifications' => false,
            'project_id' => 2,
            'mapping' => [
                'subject' => 0,
                'description' => 1,
                'priority' => 2
            ]
        ]));
        $import5->setTotalItems(45);
        $import5->setFinished(0); // Not finished due to errors
        $import5->setCreatedAt(new \DateTime('2024-01-28 10:30:00'));
        $import5->setUpdatedAt(new \DateTime('2024-01-28 10:35:00'));
        
        $manager->persist($import5);
        $this->addReference('import-failed', $import5);

        // In-progress import
        $import6 = new Import();
        $import6->setType('UserImport');
        $import6->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        $import6->setFilename('new_team_members.csv');
        $import6->setSettings(json_encode([
            'separator' => ',',
            'wrapper' => '"',
            'encoding' => 'UTF-8',
            'date_format' => '%Y-%m-%d',
            'notifications' => true,
            'mapping' => [
                'login' => 0,
                'firstname' => 1,
                'lastname' => 2,
                'mail' => 3,
                'department' => 4
            ]
        ]));
        $import6->setTotalItems(25);
        $import6->setFinished(0); // Still in progress
        $import6->setCreatedAt(new \DateTime('2024-02-01 08:15:00'));
        $import6->setUpdatedAt(new \DateTime('2024-02-01 08:20:00'));
        
        $manager->persist($import6);
        $this->addReference('import-in-progress', $import6);

        // Version import (CSV)
        $import7 = new Import();
        $import7->setType('VersionImport');
        $import7->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $import7->setFilename('releases_roadmap.csv');
        $import7->setSettings(json_encode([
            'separator' => ',',
            'wrapper' => '"',
            'encoding' => 'UTF-8',
            'date_format' => '%d/%m/%Y',
            'notifications' => false,
            'project_id' => 1,
            'mapping' => [
                'name' => 0,
                'description' => 1,
                'status' => 2,
                'due_date' => 3,
                'sharing' => 4
            ]
        ]));
        $import7->setTotalItems(8);
        $import7->setFinished(1);
        $import7->setCreatedAt(new \DateTime('2024-02-03 13:45:00'));
        $import7->setUpdatedAt(new \DateTime('2024-02-03 14:00:00'));
        
        $manager->persist($import7);
        $this->addReference('import-versions', $import7);

        // Custom field import (JSON)
        $import8 = new Import();
        $import8->setType('CustomFieldImport');
        $import8->setUser($this->getReference('user-admin', \App\Entity\User::class));
        $import8->setFilename('custom_fields_config.json');
        $import8->setSettings(json_encode([
            'format' => 'json',
            'encoding' => 'UTF-8',
            'notifications' => false,
            'validate_format' => true,
            'apply_to_projects' => [1, 2, 3]
        ]));
        $import8->setTotalItems(15);
        $import8->setFinished(1);
        $import8->setCreatedAt(new \DateTime('2024-02-05 09:20:00'));
        $import8->setUpdatedAt(new \DateTime('2024-02-05 09:25:00'));
        
        $manager->persist($import8);
        $this->addReference('import-custom-fields', $import8);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}
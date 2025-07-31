<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\QueriesRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class QueriesRoleFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // High Priority Issues query - accessible by Manager and Developer roles
        $queryRole1 = new QueriesRole();
        $queryRole1->setQuery($this->getReference('query-high-priority', \App\Entity\Query::class));
        $queryRole1->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        
        $manager->persist($queryRole1);
        $this->addReference('query-role-high-priority-manager', $queryRole1);

        $queryRole2 = new QueriesRole();
        $queryRole2->setQuery($this->getReference('query-high-priority', \App\Entity\Query::class));
        $queryRole2->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        
        $manager->persist($queryRole2);
        $this->addReference('query-role-high-priority-developer', $queryRole2);

        // Open Bugs query - accessible by Manager, Developer, and Tester roles
        $queryRole3 = new QueriesRole();
        $queryRole3->setQuery($this->getReference('query-open-bugs', \App\Entity\Query::class));
        $queryRole3->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        
        $manager->persist($queryRole3);
        $this->addReference('query-role-open-bugs-manager', $queryRole3);

        $queryRole4 = new QueriesRole();
        $queryRole4->setQuery($this->getReference('query-open-bugs', \App\Entity\Query::class));
        $queryRole4->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        
        $manager->persist($queryRole4);
        $this->addReference('query-role-open-bugs-developer', $queryRole4);

        $queryRole5 = new QueriesRole();
        $queryRole5->setQuery($this->getReference('query-open-bugs', \App\Entity\Query::class));
        $queryRole5->setRole($this->getReference('role-tester', \App\Entity\Role::class));
        
        $manager->persist($queryRole5);
        $this->addReference('query-role-open-bugs-tester', $queryRole5);

        // My Assigned Issues query - accessible by all roles
        $queryRole6 = new QueriesRole();
        $queryRole6->setQuery($this->getReference('query-my-assigned', \App\Entity\Query::class));
        $queryRole6->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        
        $manager->persist($queryRole6);
        $this->addReference('query-role-my-assigned-manager', $queryRole6);

        $queryRole7 = new QueriesRole();
        $queryRole7->setQuery($this->getReference('query-my-assigned', \App\Entity\Query::class));
        $queryRole7->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        
        $manager->persist($queryRole7);
        $this->addReference('query-role-my-assigned-developer', $queryRole7);

        $queryRole8 = new QueriesRole();
        $queryRole8->setQuery($this->getReference('query-my-assigned', \App\Entity\Query::class));
        $queryRole8->setRole($this->getReference('role-tester', \App\Entity\Role::class));
        
        $manager->persist($queryRole8);
        $this->addReference('query-role-my-assigned-tester', $queryRole8);

        $queryRole9 = new QueriesRole();
        $queryRole9->setQuery($this->getReference('query-my-assigned', \App\Entity\Query::class));
        $queryRole9->setRole($this->getReference('role-reporter', \App\Entity\Role::class));
        
        $manager->persist($queryRole9);
        $this->addReference('query-role-my-assigned-reporter', $queryRole9);

        // Recent Updates query - accessible by Manager and Developer roles
        $queryRole10 = new QueriesRole();
        $queryRole10->setQuery($this->getReference('query-recent-updates', \App\Entity\Query::class));
        $queryRole10->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        
        $manager->persist($queryRole10);
        $this->addReference('query-role-recent-updates-manager', $queryRole10);

        $queryRole11 = new QueriesRole();
        $queryRole11->setQuery($this->getReference('query-recent-updates', \App\Entity\Query::class));
        $queryRole11->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        
        $manager->persist($queryRole11);
        $this->addReference('query-role-recent-updates-developer', $queryRole11);

        // Project Tasks query - accessible by Manager and Developer roles
        $queryRole12 = new QueriesRole();
        $queryRole12->setQuery($this->getReference('query-project-tasks', \App\Entity\Query::class));
        $queryRole12->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        
        $manager->persist($queryRole12);
        $this->addReference('query-role-project-tasks-manager', $queryRole12);

        $queryRole13 = new QueriesRole();
        $queryRole13->setQuery($this->getReference('query-project-tasks', \App\Entity\Query::class));
        $queryRole13->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        
        $manager->persist($queryRole13);
        $this->addReference('query-role-project-tasks-developer', $queryRole13);

        // Features in Progress query - accessible by Manager role only
        $queryRole14 = new QueriesRole();
        $queryRole14->setQuery($this->getReference('query-features-progress', \App\Entity\Query::class));
        $queryRole14->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        
        $manager->persist($queryRole14);
        $this->addReference('query-role-features-progress-manager', $queryRole14);

        // Customer Issues query - accessible by Manager and Reporter roles
        $queryRole15 = new QueriesRole();
        $queryRole15->setQuery($this->getReference('query-customer-issues', \App\Entity\Query::class));
        $queryRole15->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        
        $manager->persist($queryRole15);
        $this->addReference('query-role-customer-issues-manager', $queryRole15);

        $queryRole16 = new QueriesRole();
        $queryRole16->setQuery($this->getReference('query-customer-issues', \App\Entity\Query::class));
        $queryRole16->setRole($this->getReference('role-reporter', \App\Entity\Role::class));
        
        $manager->persist($queryRole16);
        $this->addReference('query-role-customer-issues-reporter', $queryRole16);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            QueryFixtures::class,
            RoleFixtures::class,
        ];
    }
}
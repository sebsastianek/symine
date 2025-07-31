<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Role;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class RoleFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Create built-in Anonymous role
        $anonymous = new Role();
        $anonymous->setName('Anonymous');
        $anonymous->setPosition(0);
        $anonymous->setAssignable(0);
        $anonymous->setBuiltin(Role::BUILTIN_ANONYMOUS);
        $anonymous->setPermissions('--- []'); // Empty permissions serialized
        $anonymous->setIssuesVisibility('default');
        $anonymous->setUsersVisibility('members_of_visible_projects');
        $anonymous->setTimeEntriesVisibility('all');
        
        $manager->persist($anonymous);
        $this->addReference('role-anonymous', $anonymous);

        // Create built-in Non member role
        $nonMember = new Role();
        $nonMember->setName('Non member');
        $nonMember->setPosition(0);
        $nonMember->setAssignable(0);
        $nonMember->setBuiltin(Role::BUILTIN_NON_MEMBER);
        $nonMember->setPermissions('--- []'); // Basic read permissions
        $nonMember->setIssuesVisibility('default');
        $nonMember->setUsersVisibility('members_of_visible_projects');
        $nonMember->setTimeEntriesVisibility('own');
        
        $manager->persist($nonMember);
        $this->addReference('role-non-member', $nonMember);

        // Create Manager role
        $manager_role = new Role();
        $manager_role->setName('Manager');
        $manager_role->setPosition(1);
        $manager_role->setAssignable(1);
        $manager_role->setBuiltin(0);
        $manager_role->setAllRolesManaged(1);
        $manager_role->setPermissions('--- []'); // Full project permissions
        $manager_role->setIssuesVisibility('all');
        $manager_role->setUsersVisibility('all');
        $manager_role->setTimeEntriesVisibility('all');
        
        $manager->persist($manager_role);
        $this->addReference('role-manager', $manager_role);

        // Create Developer role
        $developer = new Role();
        $developer->setName('Developer');
        $developer->setPosition(2);
        $developer->setAssignable(1);
        $developer->setBuiltin(0);
        $developer->setAllRolesManaged(0);
        $developer->setPermissions('--- []'); // Development permissions
        $developer->setIssuesVisibility('default');
        $developer->setUsersVisibility('members_of_visible_projects');
        $developer->setTimeEntriesVisibility('all');
        
        $manager->persist($developer);
        $this->addReference('role-developer', $developer);

        // Create Reporter role
        $reporter = new Role();
        $reporter->setName('Reporter');
        $reporter->setPosition(3);
        $reporter->setAssignable(1);
        $reporter->setBuiltin(0);
        $reporter->setAllRolesManaged(0);
        $reporter->setPermissions('--- []'); // Basic reporting permissions
        $reporter->setIssuesVisibility('default');
        $reporter->setUsersVisibility('members_of_visible_projects');
        $reporter->setTimeEntriesVisibility('own');
        
        $manager->persist($reporter);
        $this->addReference('role-reporter', $reporter);

        // Create Tester role
        $tester = new Role();
        $tester->setName('Tester');
        $tester->setPosition(4);
        $tester->setAssignable(1);
        $tester->setBuiltin(0);
        $tester->setAllRolesManaged(0);
        $tester->setPermissions('--- []'); // Testing permissions
        $tester->setIssuesVisibility('default');
        $tester->setUsersVisibility('members_of_visible_projects');
        $tester->setTimeEntriesVisibility('own');
        
        $manager->persist($tester);
        $this->addReference('role-tester', $tester);

        // Create Client role
        $client = new Role();
        $client->setName('Client');
        $client->setPosition(5);
        $client->setAssignable(1);
        $client->setBuiltin(0);
        $client->setAllRolesManaged(0);
        $client->setPermissions('--- []'); // Limited client permissions
        $client->setIssuesVisibility('own');
        $client->setUsersVisibility('members_of_visible_projects');
        $client->setTimeEntriesVisibility('none');
        
        $manager->persist($client);
        $this->addReference('role-client', $client);

        // Create Viewer role (read-only)
        $viewer = new Role();
        $viewer->setName('Viewer');
        $viewer->setPosition(6);
        $viewer->setAssignable(1);
        $viewer->setBuiltin(0);
        $viewer->setAllRolesManaged(0);
        $viewer->setPermissions('--- []'); // Read-only permissions
        $viewer->setIssuesVisibility('default');
        $viewer->setUsersVisibility('members_of_visible_projects');
        $viewer->setTimeEntriesVisibility('none');
        
        $manager->persist($viewer);
        $this->addReference('role-viewer', $viewer);

        $manager->flush();
    }
}
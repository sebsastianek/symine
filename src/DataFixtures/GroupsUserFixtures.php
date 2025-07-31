<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\GroupsUser;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class GroupsUserFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Development Team group memberships
        // John Smith in Development Team
        $groupUser1 = new GroupsUser();
        $groupUser1->setGroup($this->getReference('group-development', \App\Entity\User::class));
        $groupUser1->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        
        $manager->persist($groupUser1);
        $this->addReference('groups-user-dev-jsmith', $groupUser1);

        // Mike Johnson in Development Team
        $groupUser2 = new GroupsUser();
        $groupUser2->setGroup($this->getReference('group-development', \App\Entity\User::class));
        $groupUser2->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        
        $manager->persist($groupUser2);
        $this->addReference('groups-user-dev-mjohnson', $groupUser2);

        // Sarah Garcia in Development Team
        $groupUser3 = new GroupsUser();
        $groupUser3->setGroup($this->getReference('group-development', \App\Entity\User::class));
        $groupUser3->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        
        $manager->persist($groupUser3);
        $this->addReference('groups-user-dev-sgarcia', $groupUser3);

        // Alice Lee in Development Team
        $groupUser4 = new GroupsUser();
        $groupUser4->setGroup($this->getReference('group-development', \App\Entity\User::class));
        $groupUser4->setUser($this->getReference('user-alee', \App\Entity\User::class));
        
        $manager->persist($groupUser4);
        $this->addReference('groups-user-dev-alee', $groupUser4);

        // QA Team group memberships
        // David Brown in QA Team
        $groupUser5 = new GroupsUser();
        $groupUser5->setGroup($this->getReference('group-qa', \App\Entity\User::class));
        $groupUser5->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        
        $manager->persist($groupUser5);
        $this->addReference('groups-user-qa-dbrown', $groupUser5);

        // Emily Chen in QA Team (if exists in User fixtures)
        $groupUser6 = new GroupsUser();
        $groupUser6->setGroup($this->getReference('group-qa', \App\Entity\User::class));
        $groupUser6->setUser($this->getReference('user-echen', \App\Entity\User::class));
        
        $manager->persist($groupUser6);
        $this->addReference('groups-user-qa-echen', $groupUser6);

        // Management group memberships
        // Admin in Management
        $groupUser7 = new GroupsUser();
        $groupUser7->setGroup($this->getReference('group-management', \App\Entity\User::class));
        $groupUser7->setUser($this->getReference('user-admin', \App\Entity\User::class));
        
        $manager->persist($groupUser7);
        $this->addReference('groups-user-mgmt-admin', $groupUser7);

        // John Smith in Management (project lead)
        $groupUser8 = new GroupsUser();
        $groupUser8->setGroup($this->getReference('group-management', \App\Entity\User::class));
        $groupUser8->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        
        $manager->persist($groupUser8);
        $this->addReference('groups-user-mgmt-jsmith', $groupUser8);

        // Robert Wilson in Management (if exists)
        $groupUser9 = new GroupsUser();
        $groupUser9->setGroup($this->getReference('group-management', \App\Entity\User::class));
        $groupUser9->setUser($this->getReference('user-rwilson', \App\Entity\User::class));
        
        $manager->persist($groupUser9);
        $this->addReference('groups-user-mgmt-rwilson', $groupUser9);

        // Linda Davis in Management (if exists)
        $groupUser10 = new GroupsUser();
        $groupUser10->setGroup($this->getReference('group-management', \App\Entity\User::class));
        $groupUser10->setUser($this->getReference('user-ldavis', \App\Entity\User::class));
        
        $manager->persist($groupUser10);
        $this->addReference('groups-user-mgmt-ldavis', $groupUser10);

        // Cross-group memberships (some users in multiple groups)
        // Sarah Garcia also in QA Team for cross-team collaboration
        $groupUser11 = new GroupsUser();
        $groupUser11->setGroup($this->getReference('group-qa', \App\Entity\User::class));
        $groupUser11->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        
        $manager->persist($groupUser11);
        $this->addReference('groups-user-qa-sgarcia', $groupUser11);

        // Alice Lee also in QA Team for frontend testing
        $groupUser12 = new GroupsUser();
        $groupUser12->setGroup($this->getReference('group-qa', \App\Entity\User::class));
        $groupUser12->setUser($this->getReference('user-alee', \App\Entity\User::class));
        
        $manager->persist($groupUser12);
        $this->addReference('groups-user-qa-alee', $groupUser12);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}
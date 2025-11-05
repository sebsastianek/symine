<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\MemberRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class MemberRoleFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Member Role 1: John Smith as Manager in E-commerce Project
        $memberRole1 = new MemberRole();
        $memberRole1->setMember($this->getReference('member-jsmith-ecommerce', \App\Entity\Member::class));
        $memberRole1->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        $memberRole1->setInheritedFrom(null);
        
        $manager->persist($memberRole1);
        $this->addReference('member-role-jsmith-manager', $memberRole1);

        // Member Role 2: John Smith as Developer in E-commerce Project
        $memberRole2 = new MemberRole();
        $memberRole2->setMember($this->getReference('member-jsmith-ecommerce', \App\Entity\Member::class));
        $memberRole2->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $memberRole2->setInheritedFrom(null);
        
        $manager->persist($memberRole2);
        $this->addReference('member-role-jsmith-developer', $memberRole2);

        // Member Role 3: Mike Johnson as Developer in E-commerce Project
        $memberRole3 = new MemberRole();
        $memberRole3->setMember($this->getReference('member-mjohnson-ecommerce', \App\Entity\Member::class));
        $memberRole3->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $memberRole3->setInheritedFrom(null);
        
        $manager->persist($memberRole3);
        $this->addReference('member-role-mjohnson-developer', $memberRole3);

        // Member Role 4: Sarah Garcia as Developer in E-commerce Project
        $memberRole4 = new MemberRole();
        $memberRole4->setMember($this->getReference('member-sgarcia-ecommerce', \App\Entity\Member::class));
        $memberRole4->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $memberRole4->setInheritedFrom(null);
        
        $manager->persist($memberRole4);
        $this->addReference('member-role-sgarcia-developer', $memberRole4);

        // Member Role 5: Emily Chen as Reporter in E-commerce Project
        $memberRole5 = new MemberRole();
        $memberRole5->setMember($this->getReference('member-dbrown-ecommerce', \App\Entity\Member::class));
        $memberRole5->setRole($this->getReference('role-reporter', \App\Entity\Role::class));
        $memberRole5->setInheritedFrom(null);
        
        $manager->persist($memberRole5);
        $this->addReference('member-role-echen-reporter', $memberRole5);

        // Member Role 6: Alice Lee as Developer in CRM Project
        $memberRole6 = new MemberRole();
        $memberRole6->setMember($this->getReference('member-alee-crm', \App\Entity\Member::class));
        $memberRole6->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $memberRole6->setInheritedFrom(null);
        
        $manager->persist($memberRole6);
        $this->addReference('member-role-alee-developer', $memberRole6);

        // Member Role 7: David Brown as QA in CRM Project
        $memberRole7 = new MemberRole();
        $memberRole7->setMember($this->getReference('member-sgarcia-crm', \App\Entity\Member::class));
        $memberRole7->setRole($this->getReference('role-tester', \App\Entity\Role::class));
        $memberRole7->setInheritedFrom(null);
        
        $manager->persist($memberRole7);
        $this->addReference('member-role-dbrown-tester', $memberRole7);

        // Member Role 8: Robert Wilson as Manager in CRM Project
        $memberRole8 = new MemberRole();
        $memberRole8->setMember($this->getReference('member-jsmith-crm', \App\Entity\Member::class));
        $memberRole8->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        $memberRole8->setInheritedFrom(null);
        
        $manager->persist($memberRole8);
        $this->addReference('member-role-rwilson-manager', $memberRole8);

        // Member Role 9: Linda Davis as Reporter in CRM Project
        $memberRole9 = new MemberRole();
        $memberRole9->setMember($this->getReference('member-alee-crm', \App\Entity\Member::class));
        $memberRole9->setRole($this->getReference('role-reporter', \App\Entity\Role::class));
        $memberRole9->setInheritedFrom(null);
        
        $manager->persist($memberRole9);
        $this->addReference('member-role-ldavis-reporter', $memberRole9);

        // Member Role 10: John Smith as Manager in Mobile Project
        $memberRole10 = new MemberRole();
        $memberRole10->setMember($this->getReference('member-jsmith-backend', \App\Entity\Member::class));
        $memberRole10->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        $memberRole10->setInheritedFrom(null);
        
        $manager->persist($memberRole10);
        $this->addReference('member-role-jsmith-mobile-manager', $memberRole10);

        // Member Role 11: Sarah Garcia as Developer in Mobile Project
        $memberRole11 = new MemberRole();
        $memberRole11->setMember($this->getReference('member-sgarcia-frontend', \App\Entity\Member::class));
        $memberRole11->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $memberRole11->setInheritedFrom(null);
        
        $manager->persist($memberRole11);
        $this->addReference('member-role-sgarcia-mobile-developer', $memberRole11);

        // Member Role 12: Mike Johnson as Developer in Analytics Project
        $memberRole12 = new MemberRole();
        $memberRole12->setMember($this->getReference('member-mjohnson-frontend', \App\Entity\Member::class));
        $memberRole12->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $memberRole12->setInheritedFrom(null);
        
        $manager->persist($memberRole12);
        $this->addReference('member-role-mjohnson-analytics', $memberRole12);

        // Member Role 13: Emily Chen as Tester in Analytics Project  
        $memberRole13 = new MemberRole();
        $memberRole13->setMember($this->getReference('member-alee-crm', \App\Entity\Member::class));
        $memberRole13->setRole($this->getReference('role-tester', \App\Entity\Role::class));
        $memberRole13->setInheritedFrom(null);
        
        $manager->persist($memberRole13);
        $this->addReference('member-role-echen-analytics-tester', $memberRole13);

        // Member Role 14: Alice Lee as Reporter in Website Project
        $memberRole14 = new MemberRole();
        $memberRole14->setMember($this->getReference('member-alee-crm', \App\Entity\Member::class));
        $memberRole14->setRole($this->getReference('role-reporter', \App\Entity\Role::class));
        $memberRole14->setInheritedFrom(null);
        
        $manager->persist($memberRole14);
        $this->addReference('member-role-alee-website-reporter', $memberRole14);

        // Member Role 15: David Brown as Developer in Website Project
        $memberRole15 = new MemberRole();
        $memberRole15->setMember($this->getReference('member-dbrown-docs', \App\Entity\Member::class));
        $memberRole15->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $memberRole15->setInheritedFrom(null);
        
        $manager->persist($memberRole15);
        $this->addReference('member-role-dbrown-website-developer', $memberRole15);

        // Member Role 16: Robert Wilson as Manager in Infrastructure Project
        $memberRole16 = new MemberRole();
        $memberRole16->setMember($this->getReference('member-jsmith-backend', \App\Entity\Member::class));
        $memberRole16->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        $memberRole16->setInheritedFrom(null);
        
        $manager->persist($memberRole16);
        $this->addReference('member-role-rwilson-infrastructure-manager', $memberRole16);

        // Member Role 17: Linda Davis as Developer in Research Project
        $memberRole17 = new MemberRole();
        $memberRole17->setMember($this->getReference('member-sgarcia-docs', \App\Entity\Member::class));
        $memberRole17->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $memberRole17->setInheritedFrom(null);
        
        $manager->persist($memberRole17);
        $this->addReference('member-role-ldavis-research-developer', $memberRole17);

        // Member Role 18: Inherited role example - Developer role inherited from parent project
        $memberRole18 = new MemberRole();
        $memberRole18->setMember($this->getReference('member-mjohnson-ecommerce', \App\Entity\Member::class));
        $memberRole18->setRole($this->getReference('role-tester', \App\Entity\Role::class));
        $memberRole18->setInheritedFrom($this->getReference('member-mjohnson-frontend', \App\Entity\Member::class));
        
        $manager->persist($memberRole18);
        $this->addReference('member-role-mjohnson-inherited', $memberRole18);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            MemberFixtures::class,
            RoleFixtures::class,
        ];
    }
}
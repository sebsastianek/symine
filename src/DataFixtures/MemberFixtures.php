<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Member;
use App\Entity\MemberRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class MemberFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // E-Commerce Platform Members
        
        // John Smith as Manager
        $member1 = new Member();
        $member1->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $member1->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $member1->setMailNotification(1);
        $member1->setCreatedOn(new \DateTime('2024-01-02 10:00:00'));
        
        $manager->persist($member1);
        $this->addReference('member-jsmith-ecommerce', $member1);

        $memberRole1 = new MemberRole();
        $memberRole1->setMember($member1);
        $memberRole1->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        $memberRole1->setInheritedFrom(null);
        
        $manager->persist($memberRole1);
        $member1->getMemberRoles()->add($memberRole1);

        // Mike Johnson as Developer
        $member2 = new Member();
        $member2->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $member2->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $member2->setMailNotification(1);
        $member2->setCreatedOn(new \DateTime('2024-01-20 14:15:00'));
        
        $manager->persist($member2);
        $this->addReference('member-mjohnson-ecommerce', $member2);

        $memberRole2 = new MemberRole();
        $memberRole2->setMember($member2);
        $memberRole2->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $memberRole2->setInheritedFrom(null);
        
        $manager->persist($memberRole2);
        $member2->getMemberRoles()->add($memberRole2);

        // Sarah Garcia as Developer
        $member3 = new Member();
        $member3->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $member3->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $member3->setMailNotification(1);
        $member3->setCreatedOn(new \DateTime('2024-02-01 11:45:00'));
        
        $manager->persist($member3);
        $this->addReference('member-sgarcia-ecommerce', $member3);

        $memberRole3 = new MemberRole();
        $memberRole3->setMember($member3);
        $memberRole3->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $memberRole3->setInheritedFrom(null);
        
        $manager->persist($memberRole3);
        $member3->getMemberRoles()->add($memberRole3);

        // David Brown as Tester
        $member4 = new Member();
        $member4->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        $member4->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $member4->setMailNotification(0);
        $member4->setCreatedOn(new \DateTime('2024-02-10 16:20:00'));
        
        $manager->persist($member4);
        $this->addReference('member-dbrown-ecommerce', $member4);

        $memberRole4 = new MemberRole();
        $memberRole4->setMember($member4);
        $memberRole4->setRole($this->getReference('role-tester', \App\Entity\Role::class));
        $memberRole4->setInheritedFrom(null);
        
        $manager->persist($memberRole4);
        $member4->getMemberRoles()->add($memberRole4);

        // Frontend Project Members (inherit from parent)
        
        // Mike Johnson as Developer in Frontend
        $member5 = new Member();
        $member5->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $member5->setProject($this->getReference('project-frontend', \App\Entity\Project::class));
        $member5->setMailNotification(1);
        $member5->setCreatedOn(new \DateTime('2024-01-20 14:15:00'));
        
        $manager->persist($member5);
        $this->addReference('member-mjohnson-frontend', $member5);

        $memberRole5 = new MemberRole();
        $memberRole5->setMember($member5);
        $memberRole5->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $memberRole5->setInheritedFrom($member2); // Inherited from parent project
        
        $manager->persist($memberRole5);
        $member5->getMemberRoles()->add($memberRole5);

        // Sarah Garcia as Developer in Frontend
        $member6 = new Member();
        $member6->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $member6->setProject($this->getReference('project-frontend', \App\Entity\Project::class));
        $member6->setMailNotification(1);
        $member6->setCreatedOn(new \DateTime('2024-02-01 11:45:00'));
        
        $manager->persist($member6);
        $this->addReference('member-sgarcia-frontend', $member6);

        $memberRole6 = new MemberRole();
        $memberRole6->setMember($member6);
        $memberRole6->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $memberRole6->setInheritedFrom($member3); // Inherited from parent project
        
        $manager->persist($memberRole6);
        $member6->getMemberRoles()->add($memberRole6);

        // Backend Project Members
        
        // John Smith as Manager in Backend
        $member7 = new Member();
        $member7->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $member7->setProject($this->getReference('project-backend', \App\Entity\Project::class));
        $member7->setMailNotification(1);
        $member7->setCreatedOn(new \DateTime('2024-01-05 11:00:00'));
        
        $manager->persist($member7);
        $this->addReference('member-jsmith-backend', $member7);

        $memberRole7 = new MemberRole();
        $memberRole7->setMember($member7);
        $memberRole7->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        $memberRole7->setInheritedFrom($member1); // Inherited from parent project
        
        $manager->persist($memberRole7);
        $member7->getMemberRoles()->add($memberRole7);

        // Mike Johnson as Developer in Backend
        $member8 = new Member();
        $member8->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $member8->setProject($this->getReference('project-backend', \App\Entity\Project::class));
        $member8->setMailNotification(1);
        $member8->setCreatedOn(new \DateTime('2024-01-20 14:15:00'));
        
        $manager->persist($member8);
        $this->addReference('member-mjohnson-backend', $member8);

        $memberRole8 = new MemberRole();
        $memberRole8->setMember($member8);
        $memberRole8->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $memberRole8->setInheritedFrom($member2); // Inherited from parent project
        
        $manager->persist($memberRole8);
        $member8->getMemberRoles()->add($memberRole8);

        // CRM Project Members
        
        // John Smith as Manager in CRM
        $member9 = new Member();
        $member9->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $member9->setProject($this->getReference('project-crm', \App\Entity\Project::class));
        $member9->setMailNotification(1);
        $member9->setCreatedOn(new \DateTime('2024-01-15 13:20:00'));
        
        $manager->persist($member9);
        $this->addReference('member-jsmith-crm', $member9);

        $memberRole9 = new MemberRole();
        $memberRole9->setMember($member9);
        $memberRole9->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        $memberRole9->setInheritedFrom(null);
        
        $manager->persist($memberRole9);
        $member9->getMemberRoles()->add($memberRole9);

        // Sarah Garcia as Developer in CRM
        $member10 = new Member();
        $member10->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $member10->setProject($this->getReference('project-crm', \App\Entity\Project::class));
        $member10->setMailNotification(1);
        $member10->setCreatedOn(new \DateTime('2024-02-01 11:45:00'));
        
        $manager->persist($member10);
        $this->addReference('member-sgarcia-crm', $member10);

        $memberRole10 = new MemberRole();
        $memberRole10->setMember($member10);
        $memberRole10->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        $memberRole10->setInheritedFrom(null);
        
        $manager->persist($memberRole10);
        $member10->getMemberRoles()->add($memberRole10);

        // Alice Lee as Client in CRM
        $member11 = new Member();
        $member11->setUser($this->getReference('user-alee', \App\Entity\User::class));
        $member11->setProject($this->getReference('project-crm', \App\Entity\Project::class));
        $member11->setMailNotification(1);
        $member11->setCreatedOn(new \DateTime('2024-02-15 13:10:00'));
        
        $manager->persist($member11);
        $this->addReference('member-alee-crm', $member11);

        $memberRole11 = new MemberRole();
        $memberRole11->setMember($member11);
        $memberRole11->setRole($this->getReference('role-client', \App\Entity\Role::class));
        $memberRole11->setInheritedFrom(null);
        
        $manager->persist($memberRole11);
        $member11->getMemberRoles()->add($memberRole11);

        // Documentation Project Members
        
        // John Smith as Manager in Documentation
        $member12 = new Member();
        $member12->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $member12->setProject($this->getReference('project-docs', \App\Entity\Project::class));
        $member12->setMailNotification(1);
        $member12->setCreatedOn(new \DateTime('2024-02-10 16:45:00'));
        
        $manager->persist($member12);
        $this->addReference('member-jsmith-docs', $member12);

        $memberRole12 = new MemberRole();
        $memberRole12->setMember($member12);
        $memberRole12->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        $memberRole12->setInheritedFrom(null);
        
        $manager->persist($memberRole12);
        $member12->getMemberRoles()->add($memberRole12);

        // Mike Johnson as Reporter in Documentation
        $member13 = new Member();
        $member13->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $member13->setProject($this->getReference('project-docs', \App\Entity\Project::class));
        $member13->setMailNotification(1);
        $member13->setCreatedOn(new \DateTime('2024-02-18 10:00:00'));
        
        $manager->persist($member13);
        $this->addReference('member-mjohnson-docs', $member13);

        $memberRole13 = new MemberRole();
        $memberRole13->setMember($member13);
        $memberRole13->setRole($this->getReference('role-reporter', \App\Entity\Role::class));
        $memberRole13->setInheritedFrom(null);
        
        $manager->persist($memberRole13);
        $member13->getMemberRoles()->add($memberRole13);

        // All team members as Viewers in Documentation
        $member14 = new Member();
        $member14->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $member14->setProject($this->getReference('project-docs', \App\Entity\Project::class));
        $member14->setMailNotification(0);
        $member14->setCreatedOn(new \DateTime('2024-02-20 09:00:00'));
        
        $manager->persist($member14);
        $this->addReference('member-sgarcia-docs', $member14);

        $memberRole14 = new MemberRole();
        $memberRole14->setMember($member14);
        $memberRole14->setRole($this->getReference('role-viewer', \App\Entity\Role::class));
        $memberRole14->setInheritedFrom(null);
        
        $manager->persist($memberRole14);
        $member14->getMemberRoles()->add($memberRole14);

        $member15 = new Member();
        $member15->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        $member15->setProject($this->getReference('project-docs', \App\Entity\Project::class));
        $member15->setMailNotification(0);
        $member15->setCreatedOn(new \DateTime('2024-02-20 09:00:00'));
        
        $manager->persist($member15);
        $this->addReference('member-dbrown-docs', $member15);

        $memberRole15 = new MemberRole();
        $memberRole15->setMember($member15);
        $memberRole15->setRole($this->getReference('role-viewer', \App\Entity\Role::class));
        $memberRole15->setInheritedFrom(null);
        
        $manager->persist($memberRole15);
        $member15->getMemberRoles()->add($memberRole15);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            RoleFixtures::class,
            ProjectFixtures::class,
        ];
    }
}
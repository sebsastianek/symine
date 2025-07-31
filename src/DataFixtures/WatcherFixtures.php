<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Watcher;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class WatcherFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Watcher 1: John Smith watching authentication issue
        $watcher1 = new Watcher();
        $watcher1->setWatchableType('Issue');
        $watcher1->setWatchableId($this->getReference('issue-auth', \App\Entity\Issue::class)->getId());
        $watcher1->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        
        $manager->persist($watcher1);
        $this->addReference('watcher-jsmith-auth', $watcher1);

        // Watcher 2: Admin watching security issue
        $watcher2 = new Watcher();
        $watcher2->setWatchableType('Issue');
        $watcher2->setWatchableId($this->getReference('issue-security-private', \App\Entity\Issue::class)->getId());
        $watcher2->setUser($this->getReference('user-admin', \App\Entity\User::class));
        
        $manager->persist($watcher2);
        $this->addReference('watcher-admin-security', $watcher2);

        // Watcher 3: John Smith also watching security issue
        $watcher3 = new Watcher();
        $watcher3->setWatchableType('Issue');
        $watcher3->setWatchableId($this->getReference('issue-security-private', \App\Entity\Issue::class)->getId());
        $watcher3->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        
        $manager->persist($watcher3);
        $this->addReference('watcher-jsmith-security', $watcher3);

        // Watcher 4: Sarah watching frontend layout issue
        $watcher4 = new Watcher();
        $watcher4->setWatchableType('Issue');
        $watcher4->setWatchableId($this->getReference('issue-frontend-layout', \App\Entity\Issue::class)->getId());
        $watcher4->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        
        $manager->persist($watcher4);
        $this->addReference('watcher-sgarcia-frontend', $watcher4);

        // Watcher 5: John Smith watching frontend layout issue (project manager oversight)
        $watcher5 = new Watcher();
        $watcher5->setWatchableType('Issue');
        $watcher5->setWatchableId($this->getReference('issue-frontend-layout', \App\Entity\Issue::class)->getId());
        $watcher5->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        
        $manager->persist($watcher5);
        $this->addReference('watcher-jsmith-frontend', $watcher5);

        // Watcher 6: David Brown watching login bug (tester)
        $watcher6 = new Watcher();
        $watcher6->setWatchableType('Issue');
        $watcher6->setWatchableId($this->getReference('issue-login-bug', \App\Entity\Issue::class)->getId());
        $watcher6->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        
        $manager->persist($watcher6);
        $this->addReference('watcher-dbrown-login-bug', $watcher6);

        // Watcher 7: Mike Johnson watching mobile development
        $watcher7 = new Watcher();
        $watcher7->setWatchableType('Issue');
        $watcher7->setWatchableId($this->getReference('issue-mobile-parent', \App\Entity\Issue::class)->getId());
        $watcher7->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        
        $manager->persist($watcher7);
        $this->addReference('watcher-mjohnson-mobile', $watcher7);

        // Watcher 8: John Smith watching mobile development (project oversight)
        $watcher8 = new Watcher();
        $watcher8->setWatchableType('Issue');
        $watcher8->setWatchableId($this->getReference('issue-mobile-parent', \App\Entity\Issue::class)->getId());
        $watcher8->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        
        $manager->persist($watcher8);
        $this->addReference('watcher-jsmith-mobile', $watcher8);

        // Watcher 9: Alice Lee watching CRM contacts issue (client interest)
        $watcher9 = new Watcher();
        $watcher9->setWatchableType('Issue');
        $watcher9->setWatchableId($this->getReference('issue-crm-contacts', \App\Entity\Issue::class)->getId());
        $watcher9->setUser($this->getReference('user-alee', \App\Entity\User::class));
        
        $manager->persist($watcher9);
        $this->addReference('watcher-alee-crm', $watcher9);

        // Watcher 10: Sarah Garcia watching CRM contacts (developer)
        $watcher10 = new Watcher();
        $watcher10->setWatchableType('Issue');
        $watcher10->setWatchableId($this->getReference('issue-crm-contacts', \App\Entity\Issue::class)->getId());
        $watcher10->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        
        $manager->persist($watcher10);
        $this->addReference('watcher-sgarcia-crm', $watcher10);

        // Watcher 11: Mike Johnson watching API documentation
        $watcher11 = new Watcher();
        $watcher11->setWatchableType('Issue');
        $watcher11->setWatchableId($this->getReference('issue-api-docs', \App\Entity\Issue::class)->getId());
        $watcher11->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        
        $manager->persist($watcher11);
        $this->addReference('watcher-mjohnson-docs', $watcher11);

        // Watcher 12: Alice Lee watching support login issue (affected user)
        $watcher12 = new Watcher();
        $watcher12->setWatchableType('Issue');
        $watcher12->setWatchableId($this->getReference('issue-support-login', \App\Entity\Issue::class)->getId());
        $watcher12->setUser($this->getReference('user-alee', \App\Entity\User::class));
        
        $manager->persist($watcher12);
        $this->addReference('watcher-alee-support', $watcher12);

        // Watcher 13: John Smith watching payment gateway (project manager)
        $watcher13 = new Watcher();
        $watcher13->setWatchableType('Issue');
        $watcher13->setWatchableId($this->getReference('issue-payment-gateway', \App\Entity\Issue::class)->getId());
        $watcher13->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        
        $manager->persist($watcher13);
        $this->addReference('watcher-jsmith-payment', $watcher13);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            IssueFixtures::class,
        ];
    }
}
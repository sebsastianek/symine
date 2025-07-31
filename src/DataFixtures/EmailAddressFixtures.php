<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\EmailAddress;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class EmailAddressFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Secondary email for John Smith
        $email1 = new EmailAddress();
        $email1->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $email1->setAddress('john.smith.pm@company.com');
        $email1->setIsDefault(0);
        $email1->setNotify(1);
        $email1->setCreatedOn(new \DateTime('2024-01-10 09:00:00'));
        $email1->setUpdatedOn(new \DateTime('2024-01-10 09:00:00'));
        
        $manager->persist($email1);
        $this->addReference('email-jsmith-secondary', $email1);

        // Personal email for John Smith
        $email2 = new EmailAddress();
        $email2->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $email2->setAddress('john.personal@gmail.com');
        $email2->setIsDefault(0);
        $email2->setNotify(0);
        $email2->setCreatedOn(new \DateTime('2024-01-10 09:05:00'));
        $email2->setUpdatedOn(new \DateTime('2024-01-10 09:05:00'));
        
        $manager->persist($email2);
        $this->addReference('email-jsmith-personal', $email2);

        // Alternative work email for Mike Johnson
        $email3 = new EmailAddress();
        $email3->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $email3->setAddress('mike.johnson.dev@company.com');
        $email3->setIsDefault(0);
        $email3->setNotify(1);
        $email3->setCreatedOn(new \DateTime('2024-01-20 14:15:00'));
        $email3->setUpdatedOn(new \DateTime('2024-01-20 14:15:00'));
        
        $manager->persist($email3);
        $this->addReference('email-mjohnson-alt', $email3);

        // Contractor email for Sarah Garcia
        $email4 = new EmailAddress();
        $email4->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $email4->setAddress('sarah.garcia.freelance@outlook.com');
        $email4->setIsDefault(0);
        $email4->setNotify(1);
        $email4->setCreatedOn(new \DateTime('2024-02-01 11:30:00'));
        $email4->setUpdatedOn(new \DateTime('2024-02-01 11:30:00'));
        
        $manager->persist($email4);
        $this->addReference('email-sgarcia-contractor', $email4);

        // QA team email for David Brown
        $email5 = new EmailAddress();
        $email5->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        $email5->setAddress('qa.david.brown@company.com');
        $email5->setIsDefault(0);
        $email5->setNotify(1);
        $email5->setCreatedOn(new \DateTime('2024-02-05 08:45:00'));
        $email5->setUpdatedOn(new \DateTime('2024-02-05 08:45:00'));
        
        $manager->persist($email5);
        $this->addReference('email-dbrown-qa', $email5);

        // Client communication email for Alice Lee
        $email6 = new EmailAddress();
        $email6->setUser($this->getReference('user-alee', \App\Entity\User::class));
        $email6->setAddress('alice.lee.client@business.com');
        $email6->setIsDefault(1); // This is her primary business email
        $email6->setNotify(1);
        $email6->setCreatedOn(new \DateTime('2024-02-10 16:00:00'));
        $email6->setUpdatedOn(new \DateTime('2024-02-10 16:00:00'));
        
        $manager->persist($email6);
        $this->addReference('email-alee-business', $email6);

        // Support email for Alice Lee
        $email7 = new EmailAddress();
        $email7->setUser($this->getReference('user-alee', \App\Entity\User::class));
        $email7->setAddress('alice.support@business.com');
        $email7->setIsDefault(0);
        $email7->setNotify(1);
        $email7->setCreatedOn(new \DateTime('2024-02-10 16:05:00'));
        $email7->setUpdatedOn(new \DateTime('2024-02-10 16:05:00'));
        
        $manager->persist($email7);
        $this->addReference('email-alee-support', $email7);

        // Admin system email
        $email8 = new EmailAddress();
        $email8->setUser($this->getReference('user-admin', \App\Entity\User::class));
        $email8->setAddress('system.admin@company.com');
        $email8->setIsDefault(0);
        $email8->setNotify(1);
        $email8->setCreatedOn(new \DateTime('2023-12-01 00:00:00'));
        $email8->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($email8);
        $this->addReference('email-admin-system', $email8);

        // Emergency contact email for Admin
        $email9 = new EmailAddress();
        $email9->setUser($this->getReference('user-admin', \App\Entity\User::class));
        $email9->setAddress('emergency.contact@company.com');
        $email9->setIsDefault(0);
        $email9->setNotify(1);
        $email9->setCreatedOn(new \DateTime('2023-12-01 00:05:00'));
        $email9->setUpdatedOn(new \DateTime('2024-01-01 00:05:00'));
        
        $manager->persist($email9);
        $this->addReference('email-admin-emergency', $email9);

        // Mobile development team email for Mike Johnson
        $email10 = new EmailAddress();
        $email10->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $email10->setAddress('mobile.team@company.com');
        $email10->setIsDefault(0);
        $email10->setNotify(0); // Team alias, no individual notifications
        $email10->setCreatedOn(new \DateTime('2024-02-25 10:00:00'));
        $email10->setUpdatedOn(new \DateTime('2024-02-25 10:00:00'));
        
        $manager->persist($email10);
        $this->addReference('email-mjohnson-mobile-team', $email10);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}
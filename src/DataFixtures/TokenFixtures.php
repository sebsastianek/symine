<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Token;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class TokenFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // API Token for John Smith (Project Manager)
        $apiToken1 = new Token();
        $apiToken1->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $apiToken1->setAction('api');
        $apiToken1->setValue('f8a7b6c5d4e3f2a1b0c9d8e7f6a5b4c3d2e1f0a9');
        $apiToken1->setCreatedOn(new \DateTime('2024-01-15 09:00:00'));
        $apiToken1->setUpdatedOn(new \DateTime('2024-01-15 09:00:00'));
        
        $manager->persist($apiToken1);
        $this->addReference('token-jsmith-api', $apiToken1);

        // API Token for Mike Johnson (Developer)
        $apiToken2 = new Token();
        $apiToken2->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $apiToken2->setAction('api');
        $apiToken2->setValue('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0');
        $apiToken2->setCreatedOn(new \DateTime('2024-02-01 14:30:00'));
        $apiToken2->setUpdatedOn(new \DateTime('2024-02-01 14:30:00'));
        
        $manager->persist($apiToken2);
        $this->addReference('token-mjohnson-api', $apiToken2);

        // Password Reset Token for Alice Lee (expired, for testing)
        $resetToken1 = new Token();
        $resetToken1->setUser($this->getReference('user-alee', \App\Entity\User::class));
        $resetToken1->setAction('recovery');
        $resetToken1->setValue('9b8a7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0b');
        $resetToken1->setCreatedOn(new \DateTime('2024-02-20 16:45:00'));
        
        $manager->persist($resetToken1);
        $this->addReference('token-alee-recovery', $resetToken1);

        // Recent Password Reset Token for David Brown
        $resetToken2 = new Token();
        $resetToken2->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        $resetToken2->setAction('recovery');
        $resetToken2->setValue('c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1');
        $resetToken2->setCreatedOn(new \DateTime('2024-03-01 10:15:00'));
        
        $manager->persist($resetToken2);
        $this->addReference('token-dbrown-recovery', $resetToken2);

        // Session Token for Sarah Garcia (design sessions)
        $sessionToken1 = new Token();
        $sessionToken1->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $sessionToken1->setAction('session');
        $sessionToken1->setValue('e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3');
        $sessionToken1->setCreatedOn(new \DateTime('2024-03-05 08:20:00'));
        $sessionToken1->setUpdatedOn(new \DateTime('2024-03-05 16:45:00'));
        
        $manager->persist($sessionToken1);
        $this->addReference('token-sgarcia-session', $sessionToken1);

        // API Token for Admin (system integration)
        $adminApiToken = new Token();
        $adminApiToken->setUser($this->getReference('user-admin', \App\Entity\User::class));
        $adminApiToken->setAction('api');
        $adminApiToken->setValue('admin123api456token789abc012def345ghi678');
        $adminApiToken->setCreatedOn(new \DateTime('2023-12-01 00:00:00'));
        $adminApiToken->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($adminApiToken);
        $this->addReference('token-admin-api', $adminApiToken);

        // Feed Token for John Smith (RSS access)
        $feedToken1 = new Token();
        $feedToken1->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $feedToken1->setAction('feeds');
        $feedToken1->setValue('feed789abc012def345ghi678jkl901mno234pqr');
        $feedToken1->setCreatedOn(new \DateTime('2024-01-20 12:00:00'));
        
        $manager->persist($feedToken1);
        $this->addReference('token-jsmith-feed', $feedToken1);

        // Import Token for bulk data import
        $importToken1 = new Token();
        $importToken1->setUser($this->getReference('user-admin', \App\Entity\User::class));
        $importToken1->setAction('import');
        $importToken1->setValue('import012def345ghi678jkl901mno234pqr567s');
        $importToken1->setCreatedOn(new \DateTime('2024-02-15 09:30:00'));
        $importToken1->setUpdatedOn(new \DateTime('2024-02-15 17:45:00'));
        
        $manager->persist($importToken1);
        $this->addReference('token-admin-import', $importToken1);

        // Autologin Token for Mike Johnson (remember me)
        $autologinToken1 = new Token();
        $autologinToken1->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $autologinToken1->setAction('autologin');
        $autologinToken1->setValue('auto345ghi678jkl901mno234pqr567stu890vw');
        $autologinToken1->setCreatedOn(new \DateTime('2024-02-28 15:20:00'));
        
        $manager->persist($autologinToken1);
        $this->addReference('token-mjohnson-autologin', $autologinToken1);

        // Recent API Token for Sarah Garcia (frontend integration)
        $apiToken3 = new Token();
        $apiToken3->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $apiToken3->setAction('api');
        $apiToken3->setValue('frontend901mno234pqr567stu890vwx123yz45');
        $apiToken3->setCreatedOn(new \DateTime('2024-03-10 11:15:00'));
        $apiToken3->setUpdatedOn(new \DateTime('2024-03-10 11:15:00'));
        
        $manager->persist($apiToken3);
        $this->addReference('token-sgarcia-api', $apiToken3);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}
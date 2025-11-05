<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Group;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Create admin user
        $admin = new User();
        $admin->setLogin('admin');
        $admin->setFirstname('System');
        $admin->setLastname('Administrator');
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin'));
        $admin->setAdmin(true);
        $admin->setStatus(User::STATUS_ACTIVE);
        $admin->setLanguage('en');
        $admin->setCreatedOn(new \DateTime('2024-01-01 10:00:00'));
        $admin->setUpdatedOn(new \DateTime());

        $manager->persist($admin);
        $this->addReference('user-admin', $admin);

        // Create project manager
        $manager1 = new User();
        $manager1->setLogin('jsmith');
        $manager1->setFirstname('John');
        $manager1->setLastname('Smith');
        $manager1->setPassword($this->passwordHasher->hashPassword($manager1, 'password123'));
        $manager1->setAdmin(false);
        $manager1->setStatus(User::STATUS_ACTIVE);
        $manager1->setLanguage('en');
        $manager1->setCreatedOn(new \DateTime('2024-01-15 09:30:00'));
        $manager1->setUpdatedOn(new \DateTime());

        $manager->persist($manager1);
        $this->addReference('user-jsmith', $manager1);

        // Create developer 1
        $dev1 = new User();
        $dev1->setLogin('mjohnson');
        $dev1->setFirstname('Mike');
        $dev1->setLastname('Johnson');
        $dev1->setPassword($this->passwordHasher->hashPassword($dev1, 'password123'));
        $dev1->setAdmin(false);
        $dev1->setStatus(User::STATUS_ACTIVE);
        $dev1->setLanguage('en');
        $dev1->setCreatedOn(new \DateTime('2024-01-20 14:15:00'));
        $dev1->setUpdatedOn(new \DateTime());

        $manager->persist($dev1);
        $this->addReference('user-mjohnson', $dev1);

        // Create developer 2
        $dev2 = new User();
        $dev2->setLogin('sgarcia');
        $dev2->setFirstname('Sarah');
        $dev2->setLastname('Garcia');
        $dev2->setPassword($this->passwordHasher->hashPassword($dev2, 'password123'));
        $dev2->setAdmin(false);
        $dev2->setStatus(User::STATUS_ACTIVE);
        $dev2->setLanguage('en');
        $dev2->setCreatedOn(new \DateTime('2024-02-01 11:45:00'));
        $dev2->setUpdatedOn(new \DateTime());

        $manager->persist($dev2);
        $this->addReference('user-sgarcia', $dev2);

        // Create tester
        $tester = new User();
        $tester->setLogin('dbrown');
        $tester->setFirstname('David');
        $tester->setLastname('Brown');
        $tester->setPassword($this->passwordHasher->hashPassword($tester, 'password123'));
        $tester->setAdmin(false);
        $tester->setStatus(User::STATUS_ACTIVE);
        $tester->setLanguage('en');
        $tester->setCreatedOn(new \DateTime('2024-02-10 16:20:00'));
        $tester->setUpdatedOn(new \DateTime());

        $manager->persist($tester);
        $this->addReference('user-dbrown', $tester);

        // Create client user
        $client = new User();
        $client->setLogin('alee');
        $client->setFirstname('Alice');
        $client->setLastname('Lee');
        $client->setPassword($this->passwordHasher->hashPassword($client, 'password123'));
        $client->setAdmin(false);
        $client->setStatus(User::STATUS_ACTIVE);
        $client->setLanguage('en');
        $client->setCreatedOn(new \DateTime('2024-02-15 13:10:00'));
        $client->setUpdatedOn(new \DateTime());

        $manager->persist($client);
        $this->addReference('user-alee', $client);

        // Create inactive user
        $inactive = new User();
        $inactive->setLogin('rjones');
        $inactive->setFirstname('Robert');
        $inactive->setLastname('Jones');
        $inactive->setPassword($this->passwordHasher->hashPassword($inactive, 'password123'));
        $inactive->setAdmin(false);
        $inactive->setStatus(User::STATUS_LOCKED);
        $inactive->setLanguage('en');
        $inactive->setCreatedOn(new \DateTime('2024-01-05 08:00:00'));
        $inactive->setUpdatedOn(new \DateTime());

        $manager->persist($inactive);
        $this->addReference('user-rjones', $inactive);

        // Create guest user (registered but not activated)
        $guest = new User();
        $guest->setLogin('guest');
        $guest->setFirstname('Guest');
        $guest->setLastname('User');
        $guest->setPassword($this->passwordHasher->hashPassword($guest, 'guest123'));
        $guest->setAdmin(false);
        $guest->setStatus(User::STATUS_REGISTERED);
        $guest->setLanguage('en');
        $guest->setCreatedOn(new \DateTime('2024-03-01 12:00:00'));
        $guest->setUpdatedOn(new \DateTime());

        $manager->persist($guest);
        $this->addReference('user-guest', $guest);

        // Create Development Team group
        $devGroup = new Group();
        $devGroup->setLogin('development-team');
        $devGroup->setFirstname('Development');
        $devGroup->setLastname('Team');
        $devGroup->setStatus(User::STATUS_ACTIVE);
        $devGroup->setLanguage('en');
        $devGroup->setCreatedOn(new \DateTime('2024-01-01 10:00:00'));
        $devGroup->setUpdatedOn(new \DateTime());

        $manager->persist($devGroup);
        $this->addReference('group-development', $devGroup);

        // Create QA Team group
        $qaGroup = new Group();
        $qaGroup->setLogin('qa-team');
        $qaGroup->setFirstname('QA');
        $qaGroup->setLastname('Team');
        $qaGroup->setStatus(User::STATUS_ACTIVE);
        $qaGroup->setLanguage('en');
        $qaGroup->setCreatedOn(new \DateTime('2024-01-01 10:00:00'));
        $qaGroup->setUpdatedOn(new \DateTime());

        $manager->persist($qaGroup);
        $this->addReference('group-qa', $qaGroup);

        // Create Management group
        $mgmtGroup = new Group();
        $mgmtGroup->setLogin('management');
        $mgmtGroup->setFirstname('Management');
        $mgmtGroup->setLastname('Team');
        $mgmtGroup->setStatus(User::STATUS_ACTIVE);
        $mgmtGroup->setLanguage('en');
        $mgmtGroup->setCreatedOn(new \DateTime('2024-01-01 10:00:00'));
        $mgmtGroup->setUpdatedOn(new \DateTime());

        $manager->persist($mgmtGroup);
        $this->addReference('group-management', $mgmtGroup);

        $manager->flush();
    }
}

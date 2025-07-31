<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // This fixture serves as the main entry point
        // All other fixtures are loaded through dependencies
        
        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            // Load in correct order based on dependencies
            UserFixtures::class,
            RoleFixtures::class,
            ProjectFixtures::class,
            TrackerFixtures::class,
            IssueStatusFixtures::class,
            EnumerationFixtures::class,
            VersionFixtures::class,
            WikiFixtures::class,
            DocumentFixtures::class,
            BoardFixtures::class,
            RepositoryFixtures::class,
            MemberFixtures::class,
            IssueFixtures::class,
            TimeEntryFixtures::class,
            NewsFixtures::class,
        ];
    }
}
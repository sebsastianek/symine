<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Version;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class VersionFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // E-Commerce Platform versions
        $version1 = new Version();
        $version1->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $version1->setName('v1.0 - MVP');
        $version1->setDescription('Minimum Viable Product with basic e-commerce functionality');
        $version1->setStatus('open');
        $version1->setEffectiveDate(new \DateTime('2024-06-30'));
        $version1->setSharing('none');
        $version1->setWikiPageTitle('v1.0 Release Notes');
        $version1->setCreatedOn(new \DateTime('2024-01-01 10:00:00'));
        $version1->setUpdatedOn(new \DateTime());
        
        $manager->persist($version1);
        $this->addReference('version-ecommerce-v1', $version1);

        $version2 = new Version();
        $version2->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $version2->setName('v1.1 - Mobile Support');
        $version2->setDescription('Mobile application support and responsive improvements');
        $version2->setStatus('open');
        $version2->setEffectiveDate(new \DateTime('2024-09-30'));
        $version2->setSharing('none');
        $version2->setCreatedOn(new \DateTime('2024-02-01 14:00:00'));
        $version2->setUpdatedOn(new \DateTime());
        
        $manager->persist($version2);
        $this->addReference('version-ecommerce-v1-1', $version2);

        // Frontend versions
        $version3 = new Version();
        $version3->setProject($this->getReference('project-frontend', \App\Entity\Project::class));
        $version3->setName('v1.0 - Initial Release');
        $version3->setDescription('First version of the frontend application');
        $version3->setStatus('open');
        $version3->setEffectiveDate(new \DateTime('2024-05-15'));
        $version3->setSharing('none');
        $version3->setCreatedOn(new \DateTime('2024-01-05 11:00:00'));
        $version3->setUpdatedOn(new \DateTime());
        
        $manager->persist($version3);
        $this->addReference('version-frontend-v1', $version3);

        // Backend versions
        $version4 = new Version();
        $version4->setProject($this->getReference('project-backend', \App\Entity\Project::class));
        $version4->setName('v1.0 - Core API');
        $version4->setDescription('Core API endpoints with authentication and basic CRUD operations');
        $version4->setStatus('closed');
        $version4->setEffectiveDate(new \DateTime('2024-03-31'));
        $version4->setSharing('none');
        $version4->setCreatedOn(new \DateTime('2024-01-05 11:30:00'));
        $version4->setUpdatedOn(new \DateTime('2024-03-31 17:00:00'));
        
        $manager->persist($version4);
        $this->addReference('version-backend-v1', $version4);

        $version5 = new Version();
        $version5->setProject($this->getReference('project-backend', \App\Entity\Project::class));
        $version5->setName('v2.0 - Advanced Features');
        $version5->setDescription('Advanced features including analytics, reporting, and integrations');
        $version5->setStatus('open');
        $version5->setEffectiveDate(new \DateTime('2024-08-31'));
        $version5->setSharing('none');
        $version5->setCreatedOn(new \DateTime('2024-03-31 17:00:00'));
        $version5->setUpdatedOn(new \DateTime());
        
        $manager->persist($version5);
        $this->addReference('version-backend-v2', $version5);

        // CRM versions
        $version6 = new Version();
        $version6->setProject($this->getReference('project-crm', \App\Entity\Project::class));
        $version6->setName('v1.0 - Contact Management');
        $version6->setDescription('Basic contact and customer management features');
        $version6->setStatus('open');
        $version6->setEffectiveDate(new \DateTime('2024-07-31'));
        $version6->setSharing('none');
        $version6->setCreatedOn(new \DateTime('2024-01-15 13:30:00'));
        $version6->setUpdatedOn(new \DateTime());
        
        $manager->persist($version6);
        $this->addReference('version-crm-v1', $version6);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ProjectFixtures::class,
        ];
    }
}
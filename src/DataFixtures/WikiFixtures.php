<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Wiki;
use App\Entity\WikiPage;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class WikiFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Create Wiki for E-Commerce project
        $wiki1 = new Wiki();
        $wiki1->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $wiki1->setStartPage('Home');
        $wiki1->setStatus(1);
        
        $manager->persist($wiki1);
        $this->addReference('wiki-ecommerce', $wiki1);

        // Home page for E-Commerce wiki
        $page1 = new WikiPage();
        $page1->setWiki($wiki1);
        $page1->setTitle('Home');
        $page1->setProtected(0);
        $page1->setParent(null);
        $page1->setCreatedOn(new \DateTime('2024-01-02 10:30:00'));
        
        $manager->persist($page1);
        $this->addReference('wiki-page-ecommerce-home', $page1);

        // Setup Guide page
        $page2 = new WikiPage();
        $page2->setWiki($wiki1);
        $page2->setTitle('Setup Guide');
        $page2->setProtected(0);
        $page2->setParent($page1);
        $page2->setCreatedOn(new \DateTime('2024-01-05 14:00:00'));
        
        $manager->persist($page2);
        $this->addReference('wiki-page-setup-guide', $page2);

        // API Documentation page
        $page3 = new WikiPage();
        $page3->setWiki($wiki1);
        $page3->setTitle('API Documentation');
        $page3->setProtected(1); // Protected page
        $page3->setParent($page1);
        $page3->setCreatedOn(new \DateTime('2024-01-10 16:20:00'));
        
        $manager->persist($page3);
        $this->addReference('wiki-page-api-docs', $page3);

        // Create Wiki for Documentation project
        $wiki2 = new Wiki();
        $wiki2->setProject($this->getReference('project-docs', \App\Entity\Project::class));
        $wiki2->setStartPage('Documentation Home');
        $wiki2->setStatus(1);
        
        $manager->persist($wiki2);
        $this->addReference('wiki-docs', $wiki2);

        // Home page for Documentation wiki
        $page4 = new WikiPage();
        $page4->setWiki($wiki2);
        $page4->setTitle('Documentation Home');
        $page4->setProtected(0);
        $page4->setParent(null);
        $page4->setCreatedOn(new \DateTime('2024-02-10 17:00:00'));
        
        $manager->persist($page4);
        $this->addReference('wiki-page-docs-home', $page4);

        // User Guide page
        $page5 = new WikiPage();
        $page5->setWiki($wiki2);
        $page5->setTitle('User Guide');
        $page5->setProtected(0);
        $page5->setParent($page4);
        $page5->setCreatedOn(new \DateTime('2024-02-12 10:15:00'));
        
        $manager->persist($page5);
        $this->addReference('wiki-page-user-guide', $page5);

        // Technical Specifications page
        $page6 = new WikiPage();
        $page6->setWiki($wiki2);
        $page6->setTitle('Technical Specifications');
        $page6->setProtected(1); // Protected page
        $page6->setParent($page4);
        $page6->setCreatedOn(new \DateTime('2024-02-15 13:45:00'));
        
        $manager->persist($page6);
        $this->addReference('wiki-page-tech-specs', $page6);

        // Create Wiki for CRM project
        $wiki3 = new Wiki();
        $wiki3->setProject($this->getReference('project-crm', \App\Entity\Project::class));
        $wiki3->setStartPage('CRM Home');
        $wiki3->setStatus(1);
        
        $manager->persist($wiki3);
        $this->addReference('wiki-crm', $wiki3);

        // Home page for CRM wiki
        $page7 = new WikiPage();
        $page7->setWiki($wiki3);
        $page7->setTitle('CRM Home');
        $page7->setProtected(0);
        $page7->setParent(null);
        $page7->setCreatedOn(new \DateTime('2024-01-16 09:30:00'));
        
        $manager->persist($page7);
        $this->addReference('wiki-page-crm-home', $page7);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ProjectFixtures::class,
        ];
    }
}
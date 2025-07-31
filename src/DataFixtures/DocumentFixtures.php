<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Document;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class DocumentFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Document 1: Project requirements
        $doc1 = new Document();
        $doc1->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $doc1->setCategory($this->getReference('doc-category-specifications', \App\Entity\Enumeration::class));
        $doc1->setTitle('E-Commerce Platform Requirements');
        $doc1->setDescription('Detailed requirements and specifications for the e-commerce platform development project.');
        $doc1->setCreatedOn(new \DateTime('2024-01-01 09:00:00'));
        
        $manager->persist($doc1);
        $this->addReference('doc-requirements', $doc1);

        // Document 2: API documentation
        $doc2 = new Document();
        $doc2->setProject($this->getReference('project-backend', \App\Entity\Project::class));
        $doc2->setCategory($this->getReference('doc-category-technical', \App\Entity\Enumeration::class));
        $doc2->setTitle('Backend API Documentation');
        $doc2->setDescription('Complete API documentation including endpoints, authentication, and examples.');
        $doc2->setCreatedOn(new \DateTime('2024-02-15 14:30:00'));
        
        $manager->persist($doc2);
        $this->addReference('doc-api', $doc2);

        // Document 3: User manual
        $doc3 = new Document();
        $doc3->setProject($this->getReference('project-docs', \App\Entity\Project::class));
        $doc3->setCategory($this->getReference('doc-category-user-guide', \App\Entity\Enumeration::class));
        $doc3->setTitle('User Manual v1.0');
        $doc3->setDescription('Comprehensive user manual for the e-commerce platform covering all features and functionality.');
        $doc3->setCreatedOn(new \DateTime('2024-02-20 11:15:00'));
        
        $manager->persist($doc3);
        $this->addReference('doc-user-manual', $doc3);

        // Document 4: Architecture diagram
        $doc4 = new Document();
        $doc4->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $doc4->setCategory($this->getReference('doc-category-technical', \App\Entity\Enumeration::class));
        $doc4->setTitle('System Architecture Diagram');
        $doc4->setDescription('High-level system architecture diagram showing components, data flow, and integrations.');
        $doc4->setCreatedOn(new \DateTime('2024-01-10 16:00:00'));
        
        $manager->persist($doc4);
        $this->addReference('doc-architecture', $doc4);

        // Document 5: Setup guide
        $doc5 = new Document();
        $doc5->setProject($this->getReference('project-docs', \App\Entity\Project::class));
        $doc5->setCategory($this->getReference('doc-category-user-guide', \App\Entity\Enumeration::class));
        $doc5->setTitle('Development Environment Setup');
        $doc5->setDescription('Step-by-step guide for setting up the development environment for all project components.');
        $doc5->setCreatedOn(new \DateTime('2024-01-08 13:45:00'));
        
        $manager->persist($doc5);
        $this->addReference('doc-setup', $doc5);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ProjectFixtures::class,
            EnumerationFixtures::class,
        ];
    }
}
<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Project;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class ProjectFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Create parent project - E-Commerce Platform
        $ecommerce = new Project();
        $ecommerce->setName('E-Commerce Platform');
        $ecommerce->setDescription('A comprehensive e-commerce platform with modern features and scalable architecture.');
        $ecommerce->setHomepage('https://ecommerce.example.com');
        $ecommerce->setIsPublic(true);
        $ecommerce->setInheritMembers(false);
        $ecommerce->setIdentifier('ecommerce');
        $ecommerce->setStatus(1); // Active
        $ecommerce->setLft(1);
        $ecommerce->setRgt(8);
        $ecommerce->setCreatedOn(new \DateTime('2024-01-01 09:00:00'));
        $ecommerce->setUpdatedOn(new \DateTime());

        $manager->persist($ecommerce);
        $this->addReference('project-ecommerce', $ecommerce);

        // Create child project - Frontend
        $frontend = new Project();
        $frontend->setName('Frontend Application');
        $frontend->setDescription('React-based frontend application with modern UI/UX.');
        $frontend->setHomepage('https://ecommerce.example.com/app');
        $frontend->setIsPublic(true);
        $frontend->setInheritMembers(true);
        $frontend->setParent($ecommerce);
        $frontend->setIdentifier('ecommerce-frontend');
        $frontend->setStatus(1);
        $frontend->setLft(2);
        $frontend->setRgt(3);
        $frontend->setCreatedOn(new \DateTime('2024-01-05 10:30:00'));
        $frontend->setUpdatedOn(new \DateTime());

        $manager->persist($frontend);
        $this->addReference('project-frontend', $frontend);

        // Create child project - Backend API
        $backend = new Project();
        $backend->setName('Backend API');
        $backend->setDescription('RESTful API backend with microservices architecture.');
        $backend->setIsPublic(false); // Private project
        $backend->setInheritMembers(true);
        $backend->setParent($ecommerce);
        $backend->setIdentifier('ecommerce-api');
        $backend->setStatus(1);
        $backend->setLft(4);
        $backend->setRgt(5);
        $backend->setCreatedOn(new \DateTime('2024-01-05 11:00:00'));
        $backend->setUpdatedOn(new \DateTime());

        $manager->persist($backend);
        $this->addReference('project-backend', $backend);

        // Create child project - Mobile App
        $mobile = new Project();
        $mobile->setName('Mobile Application');
        $mobile->setDescription('Cross-platform mobile application using React Native.');
        $mobile->setIsPublic(false);
        $mobile->setInheritMembers(true);
        $mobile->setParent($ecommerce);
        $mobile->setIdentifier('ecommerce-mobile');
        $mobile->setStatus(1);
        $mobile->setLft(6);
        $mobile->setRgt(7);
        $mobile->setCreatedOn(new \DateTime('2024-02-01 14:00:00'));
        $mobile->setUpdatedOn(new \DateTime());

        $manager->persist($mobile);
        $this->addReference('project-mobile', $mobile);

        // Create standalone project - CRM System
        $crm = new Project();
        $crm->setName('Customer Relationship Management');
        $crm->setDescription('Internal CRM system for managing customer relationships and sales pipeline.');
        $crm->setIsPublic(false);
        $crm->setInheritMembers(false);
        $crm->setIdentifier('crm-system');
        $crm->setStatus(1);
        $crm->setLft(9);
        $crm->setRgt(10);
        $crm->setCreatedOn(new \DateTime('2024-01-15 13:20:00'));
        $crm->setUpdatedOn(new \DateTime());

        $manager->persist($crm);
        $this->addReference('project-crm', $crm);

        // Create standalone project - Documentation Portal
        $docs = new Project();
        $docs->setName('Documentation Portal');
        $docs->setDescription('Centralized documentation portal for all company projects and processes.');
        $docs->setHomepage('https://docs.example.com');
        $docs->setIsPublic(true);
        $docs->setInheritMembers(false);
        $docs->setIdentifier('documentation');
        $docs->setStatus(1);
        $docs->setLft(11);
        $docs->setRgt(12);
        $docs->setCreatedOn(new \DateTime('2024-02-10 16:45:00'));
        $docs->setUpdatedOn(new \DateTime());

        $manager->persist($docs);
        $this->addReference('project-docs', $docs);

        // Create project - Internal Tools
        $tools = new Project();
        $tools->setName('Internal Tools');
        $tools->setDescription('Collection of internal tools and utilities for development and operations.');
        $tools->setIsPublic(false);
        $tools->setInheritMembers(false);
        $tools->setIdentifier('internal-tools');
        $tools->setStatus(1);
        $tools->setLft(13);
        $tools->setRgt(14);
        $tools->setCreatedOn(new \DateTime('2024-02-20 09:15:00'));
        $tools->setUpdatedOn(new \DateTime());

        $manager->persist($tools);
        $this->addReference('project-tools', $tools);

        // Create archived project
        $legacy = new Project();
        $legacy->setName('Legacy System Migration');
        $legacy->setDescription('Migration project for legacy systems - completed and archived.');
        $legacy->setIsPublic(false);
        $legacy->setInheritMembers(false);
        $legacy->setIdentifier('legacy-migration');
        $legacy->setStatus(5); // Archived
        $legacy->setLft(15);
        $legacy->setRgt(16);
        $legacy->setCreatedOn(new \DateTime('2023-06-01 08:00:00'));
        $legacy->setUpdatedOn(new \DateTime('2024-01-31 17:00:00'));

        $manager->persist($legacy);
        $this->addReference('project-legacy', $legacy);

        // Create closed project
        $prototype = new Project();
        $prototype->setName('AI Prototype');
        $prototype->setDescription('Experimental AI features prototype - project closed.');
        $prototype->setIsPublic(false);
        $prototype->setInheritMembers(false);
        $prototype->setIdentifier('ai-prototype');
        $prototype->setStatus(5); // Closed
        $prototype->setLft(17);
        $prototype->setRgt(18);
        $prototype->setCreatedOn(new \DateTime('2024-01-10 12:00:00'));
        $prototype->setUpdatedOn(new \DateTime('2024-03-15 16:30:00'));

        $manager->persist($prototype);
        $this->addReference('project-prototype', $prototype);

        $manager->flush();
    }
}

<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\CustomFieldsProject;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class CustomFieldsProjectFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // E-commerce project custom fields
        // Budget custom field for E-commerce project
        $cfProject1 = new CustomFieldsProject();
        $cfProject1->setCustomField($this->getReference('custom-field-budget', \App\Entity\CustomField::class));
        $cfProject1->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        
        $manager->persist($cfProject1);
        $this->addReference('cf-project-ecommerce-budget', $cfProject1);

        // Environment custom field for E-commerce project
        $cfProject2 = new CustomFieldsProject();
        $cfProject2->setCustomField($this->getReference('custom-field-environment', \App\Entity\CustomField::class));
        $cfProject2->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        
        $manager->persist($cfProject2);
        $this->addReference('cf-project-ecommerce-environment', $cfProject2);

        // CRM project custom fields
        // Budget custom field for CRM project
        $cfProject3 = new CustomFieldsProject();
        $cfProject3->setCustomField($this->getReference('custom-field-budget', \App\Entity\CustomField::class));
        $cfProject3->setProject($this->getReference('project-crm', \App\Entity\Project::class));
        
        $manager->persist($cfProject3);
        $this->addReference('cf-project-crm-budget', $cfProject3);

        // Environment custom field for CRM project
        $cfProject4 = new CustomFieldsProject();
        $cfProject4->setCustomField($this->getReference('custom-field-environment', \App\Entity\CustomField::class));
        $cfProject4->setProject($this->getReference('project-crm', \App\Entity\Project::class));
        
        $manager->persist($cfProject4);
        $this->addReference('cf-project-crm-environment', $cfProject4);

        // Mobile project custom fields
        // Budget custom field for Mobile project
        $cfProject5 = new CustomFieldsProject();
        $cfProject5->setCustomField($this->getReference('custom-field-budget', \App\Entity\CustomField::class));
        $cfProject5->setProject($this->getReference('project-mobile', \App\Entity\Project::class));
        
        $manager->persist($cfProject5);
        $this->addReference('cf-project-mobile-budget', $cfProject5);

        // Analytics project custom fields
        // Budget custom field for Analytics project
        $cfProject6 = new CustomFieldsProject();
        $cfProject6->setCustomField($this->getReference('custom-field-budget', \App\Entity\CustomField::class));
        $cfProject6->setProject($this->getReference('project-analytics', \App\Entity\Project::class));
        
        $manager->persist($cfProject6);
        $this->addReference('cf-project-analytics-budget', $cfProject6);

        // Environment custom field for Analytics project
        $cfProject7 = new CustomFieldsProject();
        $cfProject7->setCustomField($this->getReference('custom-field-environment', \App\Entity\CustomField::class));
        $cfProject7->setProject($this->getReference('project-analytics', \App\Entity\Project::class));
        
        $manager->persist($cfProject7);
        $this->addReference('cf-project-analytics-environment', $cfProject7);

        // Website project custom fields
        // Environment custom field for Website project
        $cfProject8 = new CustomFieldsProject();
        $cfProject8->setCustomField($this->getReference('custom-field-environment', \App\Entity\CustomField::class));
        $cfProject8->setProject($this->getReference('project-website', \App\Entity\Project::class));
        
        $manager->persist($cfProject8);
        $this->addReference('cf-project-website-environment', $cfProject8);

        // Infrastructure project custom fields
        // Budget custom field for Infrastructure project
        $cfProject9 = new CustomFieldsProject();
        $cfProject9->setCustomField($this->getReference('custom-field-budget', \App\Entity\CustomField::class));
        $cfProject9->setProject($this->getReference('project-infrastructure', \App\Entity\Project::class));
        
        $manager->persist($cfProject9);
        $this->addReference('cf-project-infrastructure-budget', $cfProject9);

        // Environment custom field for Infrastructure project
        $cfProject10 = new CustomFieldsProject();
        $cfProject10->setCustomField($this->getReference('custom-field-environment', \App\Entity\CustomField::class));
        $cfProject10->setProject($this->getReference('project-infrastructure', \App\Entity\Project::class));
        
        $manager->persist($cfProject10);
        $this->addReference('cf-project-infrastructure-environment', $cfProject10);

        // Research project custom fields
        // Budget custom field for Research project
        $cfProject11 = new CustomFieldsProject();
        $cfProject11->setCustomField($this->getReference('custom-field-budget', \App\Entity\CustomField::class));
        $cfProject11->setProject($this->getReference('project-research', \App\Entity\Project::class));
        
        $manager->persist($cfProject11);
        $this->addReference('cf-project-research-budget', $cfProject11);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CustomFieldFixtures::class,
            ProjectFixtures::class,
        ];
    }
}
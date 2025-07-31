<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\CustomFieldEnumeration;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class CustomFieldEnumerationFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Business Priority enumeration values
        $enum1 = new CustomFieldEnumeration();
        $enum1->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
        $enum1->setName('Low');
        $enum1->setActive(1);
        $enum1->setPosition(1);
        
        $manager->persist($enum1);
        $this->addReference('enum-priority-low', $enum1);

        $enum2 = new CustomFieldEnumeration();
        $enum2->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
        $enum2->setName('Medium');
        $enum2->setActive(1);
        $enum2->setPosition(2);
        
        $manager->persist($enum2);
        $this->addReference('enum-priority-medium', $enum2);

        $enum3 = new CustomFieldEnumeration();
        $enum3->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
        $enum3->setName('High');
        $enum3->setActive(1);
        $enum3->setPosition(3);
        
        $manager->persist($enum3);
        $this->addReference('enum-priority-high', $enum3);

        $enum4 = new CustomFieldEnumeration();
        $enum4->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
        $enum4->setName('Critical');
        $enum4->setActive(1);
        $enum4->setPosition(4);
        
        $manager->persist($enum4);
        $this->addReference('enum-priority-critical', $enum4);

        // Severity Level enumeration values
        $enum5 = new CustomFieldEnumeration();
        $enum5->setCustomField($this->getReference('custom-field-severity-level', \App\Entity\CustomField::class));
        $enum5->setName('Minor');
        $enum5->setActive(1);
        $enum5->setPosition(1);
        
        $manager->persist($enum5);
        $this->addReference('enum-severity-minor', $enum5);

        $enum6 = new CustomFieldEnumeration();
        $enum6->setCustomField($this->getReference('custom-field-severity-level', \App\Entity\CustomField::class));
        $enum6->setName('Major');
        $enum6->setActive(1);
        $enum6->setPosition(2);
        
        $manager->persist($enum6);
        $this->addReference('enum-severity-major', $enum6);

        $enum7 = new CustomFieldEnumeration();
        $enum7->setCustomField($this->getReference('custom-field-severity-level', \App\Entity\CustomField::class));
        $enum7->setName('Critical');
        $enum7->setActive(1);
        $enum7->setPosition(3);
        
        $manager->persist($enum7);
        $this->addReference('enum-severity-critical', $enum7);

        $enum8 = new CustomFieldEnumeration();
        $enum8->setCustomField($this->getReference('custom-field-severity-level', \App\Entity\CustomField::class));
        $enum8->setName('Blocker');
        $enum8->setActive(1);
        $enum8->setPosition(4);
        
        $manager->persist($enum8);
        $this->addReference('enum-severity-blocker', $enum8);

        // Testing Phase enumeration values
        $enum9 = new CustomFieldEnumeration();
        $enum9->setCustomField($this->getReference('custom-field-testing-phase', \App\Entity\CustomField::class));
        $enum9->setName('Unit Testing');
        $enum9->setActive(1);
        $enum9->setPosition(1);
        
        $manager->persist($enum9);
        $this->addReference('enum-testing-unit', $enum9);

        $enum10 = new CustomFieldEnumeration();
        $enum10->setCustomField($this->getReference('custom-field-testing-phase', \App\Entity\CustomField::class));
        $enum10->setName('Integration Testing');
        $enum10->setActive(1);
        $enum10->setPosition(2);
        
        $manager->persist($enum10);
        $this->addReference('enum-testing-integration', $enum10);

        $enum11 = new CustomFieldEnumeration();
        $enum11->setCustomField($this->getReference('custom-field-testing-phase', \App\Entity\CustomField::class));
        $enum11->setName('System Testing');
        $enum11->setActive(1);
        $enum11->setPosition(3);
        
        $manager->persist($enum11);
        $this->addReference('enum-testing-system', $enum11);

        $enum12 = new CustomFieldEnumeration();
        $enum12->setCustomField($this->getReference('custom-field-testing-phase', \App\Entity\CustomField::class));
        $enum12->setName('User Acceptance Testing');
        $enum12->setActive(1);
        $enum12->setPosition(4);
        
        $manager->persist($enum12);
        $this->addReference('enum-testing-uat', $enum12);

        $enum13 = new CustomFieldEnumeration();
        $enum13->setCustomField($this->getReference('custom-field-testing-phase', \App\Entity\CustomField::class));
        $enum13->setName('Performance Testing');
        $enum13->setActive(1);
        $enum13->setPosition(5);
        
        $manager->persist($enum13);
        $this->addReference('enum-testing-performance', $enum13);

        // Resolution Type enumeration values
        $enum14 = new CustomFieldEnumeration();
        $enum14->setCustomField($this->getReference('custom-field-resolution-type', \App\Entity\CustomField::class));
        $enum14->setName('Fixed');
        $enum14->setActive(1);
        $enum14->setPosition(1);
        
        $manager->persist($enum14);
        $this->addReference('enum-resolution-fixed', $enum14);

        $enum15 = new CustomFieldEnumeration();
        $enum15->setCustomField($this->getReference('custom-field-resolution-type', \App\Entity\CustomField::class));
        $enum15->setName('Won\'t Fix');
        $enum15->setActive(1);
        $enum15->setPosition(2);
        
        $manager->persist($enum15);
        $this->addReference('enum-resolution-wontfix', $enum15);

        $enum16 = new CustomFieldEnumeration();
        $enum16->setCustomField($this->getReference('custom-field-resolution-type', \App\Entity\CustomField::class));
        $enum16->setName('Duplicate');
        $enum16->setActive(1);
        $enum16->setPosition(3);
        
        $manager->persist($enum16);
        $this->addReference('enum-resolution-duplicate', $enum16);

        $enum17 = new CustomFieldEnumeration();
        $enum17->setCustomField($this->getReference('custom-field-resolution-type', \App\Entity\CustomField::class));
        $enum17->setName('Invalid');
        $enum17->setActive(1);
        $enum17->setPosition(4);
        
        $manager->persist($enum17);
        $this->addReference('enum-resolution-invalid', $enum17);

        $enum18 = new CustomFieldEnumeration();
        $enum18->setCustomField($this->getReference('custom-field-resolution-type', \App\Entity\CustomField::class));
        $enum18->setName('Works for Me');
        $enum18->setActive(1);
        $enum18->setPosition(5);
        
        $manager->persist($enum18);
        $this->addReference('enum-resolution-worksforme', $enum18);

        // Component enumeration values (for user custom field)
        $enum19 = new CustomFieldEnumeration();
        $enum19->setCustomField($this->getReference('custom-field-preferred-component', \App\Entity\CustomField::class));
        $enum19->setName('Frontend');
        $enum19->setActive(1);
        $enum19->setPosition(1);
        
        $manager->persist($enum19);
        $this->addReference('enum-component-frontend', $enum19);

        $enum20 = new CustomFieldEnumeration();
        $enum20->setCustomField($this->getReference('custom-field-preferred-component', \App\Entity\CustomField::class));
        $enum20->setName('Backend');
        $enum20->setActive(1);
        $enum20->setPosition(2);
        
        $manager->persist($enum20);
        $this->addReference('enum-component-backend', $enum20);

        $enum21 = new CustomFieldEnumeration();
        $enum21->setCustomField($this->getReference('custom-field-preferred-component', \App\Entity\CustomField::class));
        $enum21->setName('Database');
        $enum21->setActive(1);
        $enum21->setPosition(3);
        
        $manager->persist($enum21);
        $this->addReference('enum-component-database', $enum21);

        $enum22 = new CustomFieldEnumeration();
        $enum22->setCustomField($this->getReference('custom-field-preferred-component', \App\Entity\CustomField::class));
        $enum22->setName('DevOps');
        $enum22->setActive(1);
        $enum22->setPosition(4);
        
        $manager->persist($enum22);
        $this->addReference('enum-component-devops', $enum22);

        $enum23 = new CustomFieldEnumeration();
        $enum23->setCustomField($this->getReference('custom-field-preferred-component', \App\Entity\CustomField::class));
        $enum23->setName('Testing');
        $enum23->setActive(1);
        $enum23->setPosition(5);
        
        $manager->persist($enum23);
        $this->addReference('enum-component-testing', $enum23);

        // Environment enumeration values (for project custom field)
        $enum24 = new CustomFieldEnumeration();
        $enum24->setCustomField($this->getReference('custom-field-environment', \App\Entity\CustomField::class));
        $enum24->setName('Development');
        $enum24->setActive(1);
        $enum24->setPosition(1);
        
        $manager->persist($enum24);
        $this->addReference('enum-env-development', $enum24);

        $enum25 = new CustomFieldEnumeration();
        $enum25->setCustomField($this->getReference('custom-field-environment', \App\Entity\CustomField::class));
        $enum25->setName('Staging');
        $enum25->setActive(1);
        $enum25->setPosition(2);
        
        $manager->persist($enum25);
        $this->addReference('enum-env-staging', $enum25);

        $enum26 = new CustomFieldEnumeration();
        $enum26->setCustomField($this->getReference('custom-field-environment', \App\Entity\CustomField::class));
        $enum26->setName('Production');
        $enum26->setActive(1);
        $enum26->setPosition(3);
        
        $manager->persist($enum26);
        $this->addReference('enum-env-production', $enum26);

        $enum27 = new CustomFieldEnumeration();
        $enum27->setCustomField($this->getReference('custom-field-environment', \App\Entity\CustomField::class));
        $enum27->setName('Testing');
        $enum27->setActive(1);
        $enum27->setPosition(4);
        
        $manager->persist($enum27);
        $this->addReference('enum-env-testing', $enum27);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CustomFieldFixtures::class,
        ];
    }
}
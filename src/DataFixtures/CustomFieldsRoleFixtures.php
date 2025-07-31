<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\CustomFieldsRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class CustomFieldsRoleFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Business Priority custom field - available to Manager and Developer roles
        $cfRole1 = new CustomFieldsRole();
        $cfRole1->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
        $cfRole1->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        
        $manager->persist($cfRole1);
        $this->addReference('cf-role-business-manager', $cfRole1);

        $cfRole2 = new CustomFieldsRole();
        $cfRole2->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
        $cfRole2->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        
        $manager->persist($cfRole2);
        $this->addReference('cf-role-business-developer', $cfRole2);

        // Customer Contact custom field - available to Manager and Reporter roles
        $cfRole3 = new CustomFieldsRole();
        $cfRole3->setCustomField($this->getReference('custom-field-customer-contact', \App\Entity\CustomField::class));
        $cfRole3->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        
        $manager->persist($cfRole3);
        $this->addReference('cf-role-contact-manager', $cfRole3);

        $cfRole4 = new CustomFieldsRole();
        $cfRole4->setCustomField($this->getReference('custom-field-customer-contact', \App\Entity\CustomField::class));
        $cfRole4->setRole($this->getReference('role-reporter', \App\Entity\Role::class));
        
        $manager->persist($cfRole4);
        $this->addReference('cf-role-contact-reporter', $cfRole4);

        // Budget custom field - available to Manager role only
        $cfRole5 = new CustomFieldsRole();
        $cfRole5->setCustomField($this->getReference('custom-field-budget', \App\Entity\CustomField::class));
        $cfRole5->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        
        $manager->persist($cfRole5);
        $this->addReference('cf-role-budget-manager', $cfRole5);

        // Department custom field - available to Manager and Reporter roles
        $cfRole6 = new CustomFieldsRole();
        $cfRole6->setCustomField($this->getReference('custom-field-department', \App\Entity\CustomField::class));
        $cfRole6->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        
        $manager->persist($cfRole6);
        $this->addReference('cf-role-dept-manager', $cfRole6);

        $cfRole7 = new CustomFieldsRole();
        $cfRole7->setCustomField($this->getReference('custom-field-department', \App\Entity\CustomField::class));
        $cfRole7->setRole($this->getReference('role-reporter', \App\Entity\Role::class));
        
        $manager->persist($cfRole7);
        $this->addReference('cf-role-dept-reporter', $cfRole7);

        // Testing Notes custom field - available to Developer and Manager roles
        $cfRole8 = new CustomFieldsRole();
        $cfRole8->setCustomField($this->getReference('custom-field-testing-notes', \App\Entity\CustomField::class));
        $cfRole8->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        
        $manager->persist($cfRole8);
        $this->addReference('cf-role-testing-developer', $cfRole8);

        $cfRole9 = new CustomFieldsRole();
        $cfRole9->setCustomField($this->getReference('custom-field-testing-notes', \App\Entity\CustomField::class));
        $cfRole9->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        
        $manager->persist($cfRole9);
        $this->addReference('cf-role-testing-manager', $cfRole9);

        // Phone custom field - available to all roles
        $cfRole10 = new CustomFieldsRole();
        $cfRole10->setCustomField($this->getReference('custom-field-phone', \App\Entity\CustomField::class));
        $cfRole10->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        
        $manager->persist($cfRole10);
        $this->addReference('cf-role-phone-manager', $cfRole10);

        $cfRole11 = new CustomFieldsRole();
        $cfRole11->setCustomField($this->getReference('custom-field-phone', \App\Entity\CustomField::class));
        $cfRole11->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        
        $manager->persist($cfRole11);
        $this->addReference('cf-role-phone-developer', $cfRole11);

        $cfRole12 = new CustomFieldsRole();
        $cfRole12->setCustomField($this->getReference('custom-field-phone', \App\Entity\CustomField::class));
        $cfRole12->setRole($this->getReference('role-reporter', \App\Entity\Role::class));
        
        $manager->persist($cfRole12);
        $this->addReference('cf-role-phone-reporter', $cfRole12);

        // Review Status custom field - available to Developer and Manager roles only
        $cfRole13 = new CustomFieldsRole();
        $cfRole13->setCustomField($this->getReference('custom-field-review-status', \App\Entity\CustomField::class));
        $cfRole13->setRole($this->getReference('role-developer', \App\Entity\Role::class));
        
        $manager->persist($cfRole13);
        $this->addReference('cf-role-review-developer', $cfRole13);

        $cfRole14 = new CustomFieldsRole();
        $cfRole14->setCustomField($this->getReference('custom-field-review-status', \App\Entity\CustomField::class));
        $cfRole14->setRole($this->getReference('role-manager', \App\Entity\Role::class));
        
        $manager->persist($cfRole14);
        $this->addReference('cf-role-review-manager', $cfRole14);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CustomFieldFixtures::class,
            RoleFixtures::class,
        ];
    }
}
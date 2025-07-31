<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\CustomFieldsTracker;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class CustomFieldsTrackerFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Bug tracker custom fields
        // Business Priority for Bug tracker
        $cfTracker1 = new CustomFieldsTracker();
        $cfTracker1->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
        $cfTracker1->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        
        $manager->persist($cfTracker1);
        $this->addReference('cf-tracker-bug-priority', $cfTracker1);

        // Severity Level for Bug tracker
        $cfTracker2 = new CustomFieldsTracker();
        $cfTracker2->setCustomField($this->getReference('custom-field-severity-level', \App\Entity\CustomField::class));
        $cfTracker2->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        
        $manager->persist($cfTracker2);
        $this->addReference('cf-tracker-bug-severity', $cfTracker2);

        // Testing Phase for Bug tracker
        $cfTracker3 = new CustomFieldsTracker();
        $cfTracker3->setCustomField($this->getReference('custom-field-testing-phase', \App\Entity\CustomField::class));
        $cfTracker3->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        
        $manager->persist($cfTracker3);
        $this->addReference('cf-tracker-bug-testing', $cfTracker3);

        // Resolution Type for Bug tracker
        $cfTracker4 = new CustomFieldsTracker();
        $cfTracker4->setCustomField($this->getReference('custom-field-resolution-type', \App\Entity\CustomField::class));
        $cfTracker4->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        
        $manager->persist($cfTracker4);
        $this->addReference('cf-tracker-bug-resolution', $cfTracker4);

        // Customer Contact for Bug tracker
        $cfTracker5 = new CustomFieldsTracker();
        $cfTracker5->setCustomField($this->getReference('custom-field-customer-contact', \App\Entity\CustomField::class));
        $cfTracker5->setTracker($this->getReference('tracker-bug', \App\Entity\Tracker::class));
        
        $manager->persist($cfTracker5);
        $this->addReference('cf-tracker-bug-customer', $cfTracker5);

        // Feature tracker custom fields
        // Business Priority for Feature tracker
        $cfTracker6 = new CustomFieldsTracker();
        $cfTracker6->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
        $cfTracker6->setTracker($this->getReference('tracker-feature', \App\Entity\Tracker::class));
        
        $manager->persist($cfTracker6);
        $this->addReference('cf-tracker-feature-priority', $cfTracker6);

        // Customer Contact for Feature tracker
        $cfTracker7 = new CustomFieldsTracker();
        $cfTracker7->setCustomField($this->getReference('custom-field-customer-contact', \App\Entity\CustomField::class));
        $cfTracker7->setTracker($this->getReference('tracker-feature', \App\Entity\Tracker::class));
        
        $manager->persist($cfTracker7);
        $this->addReference('cf-tracker-feature-customer', $cfTracker7);

        // Task tracker custom fields
        // Business Priority for Task tracker
        $cfTracker8 = new CustomFieldsTracker();
        $cfTracker8->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
        $cfTracker8->setTracker($this->getReference('tracker-task', \App\Entity\Tracker::class));
        
        $manager->persist($cfTracker8);
        $this->addReference('cf-tracker-task-priority', $cfTracker8);

        // Testing Phase for Task tracker
        $cfTracker9 = new CustomFieldsTracker();
        $cfTracker9->setCustomField($this->getReference('custom-field-testing-phase', \App\Entity\CustomField::class));
        $cfTracker9->setTracker($this->getReference('tracker-task', \App\Entity\Tracker::class));
        
        $manager->persist($cfTracker9);
        $this->addReference('cf-tracker-task-testing', $cfTracker9);

        // Support tracker custom fields
        // Business Priority for Support tracker
        $cfTracker10 = new CustomFieldsTracker();
        $cfTracker10->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
        $cfTracker10->setTracker($this->getReference('tracker-support', \App\Entity\Tracker::class));
        
        $manager->persist($cfTracker10);
        $this->addReference('cf-tracker-support-priority', $cfTracker10);

        // Severity Level for Support tracker
        $cfTracker11 = new CustomFieldsTracker();
        $cfTracker11->setCustomField($this->getReference('custom-field-severity-level', \App\Entity\CustomField::class));
        $cfTracker11->setTracker($this->getReference('tracker-support', \App\Entity\Tracker::class));
        
        $manager->persist($cfTracker11);
        $this->addReference('cf-tracker-support-severity', $cfTracker11);

        // Customer Contact for Support tracker
        $cfTracker12 = new CustomFieldsTracker();
        $cfTracker12->setCustomField($this->getReference('custom-field-customer-contact', \App\Entity\CustomField::class));
        $cfTracker12->setTracker($this->getReference('tracker-support', \App\Entity\Tracker::class));
        
        $manager->persist($cfTracker12);
        $this->addReference('cf-tracker-support-customer', $cfTracker12);

        // Resolution Type for Support tracker
        $cfTracker13 = new CustomFieldsTracker();
        $cfTracker13->setCustomField($this->getReference('custom-field-resolution-type', \App\Entity\CustomField::class));
        $cfTracker13->setTracker($this->getReference('tracker-support', \App\Entity\Tracker::class));
        
        $manager->persist($cfTracker13);
        $this->addReference('cf-tracker-support-resolution', $cfTracker13);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CustomFieldFixtures::class,
            TrackerFixtures::class,
        ];
    }
}
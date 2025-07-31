<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\CustomValue;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class CustomValueFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Issue Custom Values
        
        // Business Priority for authentication issue
        $customValue1 = new CustomValue();
        $customValue1->setCustomizedType('Issue');
        $customValue1->setCustomizedId($this->getReference('issue-auth', \App\Entity\Issue::class)->getId());
        $customValue1->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
        $customValue1->setValue('High');
        
        $manager->persist($customValue1);
        $this->addReference('custom-value-auth-priority', $customValue1);

        // Estimated Effort for authentication issue
        $customValue2 = new CustomValue();
        $customValue2->setCustomizedType('Issue');
        $customValue2->setCustomizedId($this->getReference('issue-auth', \App\Entity\Issue::class)->getId());
        $customValue2->setCustomField($this->getReference('custom-field-estimated-effort', \App\Entity\CustomField::class));
        $customValue2->setValue('5.0');
        
        $manager->persist($customValue2);
        $this->addReference('custom-value-auth-effort', $customValue2);

        // Code Review Status for authentication issue
        $customValue3 = new CustomValue();
        $customValue3->setCustomizedType('Issue');
        $customValue3->setCustomizedId($this->getReference('issue-auth', \App\Entity\Issue::class)->getId());
        $customValue3->setCustomField($this->getReference('custom-field-review-status', \App\Entity\CustomField::class));
        $customValue3->setValue('In Review');
        
        $manager->persist($customValue3);
        $this->addReference('custom-value-auth-review', $customValue3);

        // Business Priority for login bug
        $customValue4 = new CustomValue();
        $customValue4->setCustomizedType('Issue');
        $customValue4->setCustomizedId($this->getReference('issue-login-bug', \App\Entity\Issue::class)->getId());
        $customValue4->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
        $customValue4->setValue('Critical');
        
        $manager->persist($customValue4);
        $this->addReference('custom-value-login-priority', $customValue4);

        // Customer Contact for login bug
        $customValue5 = new CustomValue();
        $customValue5->setCustomizedType('Issue');
        $customValue5->setCustomizedId($this->getReference('issue-login-bug', \App\Entity\Issue::class)->getId());
        $customValue5->setCustomField($this->getReference('custom-field-customer-contact', \App\Entity\CustomField::class));
        $customValue5->setValue('alice.lee@client.com');
        
        $manager->persist($customValue5);
        $this->addReference('custom-value-login-contact', $customValue5);

        // Testing Notes for login bug
        $customValue6 = new CustomValue();
        $customValue6->setCustomizedType('Issue');
        $customValue6->setCustomizedId($this->getReference('issue-login-bug', \App\Entity\Issue::class)->getId());
        $customValue6->setCustomField($this->getReference('custom-field-testing-notes', \App\Entity\CustomField::class));
        $customValue6->setValue('Tested on Chrome 120, Firefox 121, and Safari 17. Issue consistently reproducible when email has leading/trailing spaces.');
        
        $manager->persist($customValue6);
        $this->addReference('custom-value-login-testing', $customValue6);

        // Business Priority for frontend layout
        $customValue7 = new CustomValue();
        $customValue7->setCustomizedType('Issue');
        $customValue7->setCustomizedId($this->getReference('issue-frontend-layout', \App\Entity\Issue::class)->getId());
        $customValue7->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
        $customValue7->setValue('Medium');
        
        $manager->persist($customValue7);
        $this->addReference('custom-value-frontend-priority', $customValue7);

        // Estimated Effort for frontend layout
        $customValue8 = new CustomValue();
        $customValue8->setCustomizedType('Issue');
        $customValue8->setCustomizedId($this->getReference('issue-frontend-layout', \App\Entity\Issue::class)->getId());
        $customValue8->setCustomField($this->getReference('custom-field-estimated-effort', \App\Entity\CustomField::class));
        $customValue8->setValue('8.0');
        
        $manager->persist($customValue8);
        $this->addReference('custom-value-frontend-effort', $customValue8);

        // Code Review Status for frontend layout
        $customValue9 = new CustomValue();
        $customValue9->setCustomizedType('Issue');
        $customValue9->setCustomizedId($this->getReference('issue-frontend-layout', \App\Entity\Issue::class)->getId());
        $customValue9->setCustomField($this->getReference('custom-field-review-status', \App\Entity\CustomField::class));
        $customValue9->setValue('Approved');
        
        $manager->persist($customValue9);
        $this->addReference('custom-value-frontend-review', $customValue9);

        // Business Priority for API docs
        $customValue10 = new CustomValue();
        $customValue10->setCustomizedType('Issue');
        $customValue10->setCustomizedId($this->getReference('issue-api-docs', \App\Entity\Issue::class)->getId());
        $customValue10->setCustomField($this->getReference('custom-field-business-priority', \App\Entity\CustomField::class));
        $customValue10->setValue('Medium');
        
        $manager->persist($customValue10);
        $this->addReference('custom-value-docs-priority', $customValue10);

        // User Custom Values
        
        // Department for John Smith
        $customValue11 = new CustomValue();
        $customValue11->setCustomizedType('User');
        $customValue11->setCustomizedId($this->getReference('user-jsmith', \App\Entity\User::class)->getId());
        $customValue11->setCustomField($this->getReference('custom-field-department', \App\Entity\CustomField::class));
        $customValue11->setValue('Development');
        
        $manager->persist($customValue11);
        $this->addReference('custom-value-jsmith-dept', $customValue11);

        // Phone for John Smith
        $customValue12 = new CustomValue();
        $customValue12->setCustomizedType('User');
        $customValue12->setCustomizedId($this->getReference('user-jsmith', \App\Entity\User::class)->getId());
        $customValue12->setCustomField($this->getReference('custom-field-phone', \App\Entity\CustomField::class));
        $customValue12->setValue('+1-555-0123');
        
        $manager->persist($customValue12);
        $this->addReference('custom-value-jsmith-phone', $customValue12);

        // Department for Sarah Garcia
        $customValue13 = new CustomValue();
        $customValue13->setCustomizedType('User');
        $customValue13->setCustomizedId($this->getReference('user-sgarcia', \App\Entity\User::class)->getId());
        $customValue13->setCustomField($this->getReference('custom-field-department', \App\Entity\CustomField::class));
        $customValue13->setValue('Design');
        
        $manager->persist($customValue13);
        $this->addReference('custom-value-sgarcia-dept', $customValue13);

        // Department for David Brown
        $customValue14 = new CustomValue();
        $customValue14->setCustomizedType('User');
        $customValue14->setCustomizedId($this->getReference('user-dbrown', \App\Entity\User::class)->getId());
        $customValue14->setCustomField($this->getReference('custom-field-department', \App\Entity\CustomField::class));
        $customValue14->setValue('QA');
        
        $manager->persist($customValue14);
        $this->addReference('custom-value-dbrown-dept', $customValue14);

        // Phone for David Brown
        $customValue15 = new CustomValue();
        $customValue15->setCustomizedType('User');
        $customValue15->setCustomizedId($this->getReference('user-dbrown', \App\Entity\User::class)->getId());
        $customValue15->setCustomField($this->getReference('custom-field-phone', \App\Entity\CustomField::class));
        $customValue15->setValue('+1-555-0456');
        
        $manager->persist($customValue15);
        $this->addReference('custom-value-dbrown-phone', $customValue15);

        // Department for Mike Johnson
        $customValue16 = new CustomValue();
        $customValue16->setCustomizedType('User');
        $customValue16->setCustomizedId($this->getReference('user-mjohnson', \App\Entity\User::class)->getId());
        $customValue16->setCustomField($this->getReference('custom-field-department', \App\Entity\CustomField::class));
        $customValue16->setValue('Development');
        
        $manager->persist($customValue16);
        $this->addReference('custom-value-mjohnson-dept', $customValue16);

        // Project Custom Values
        
        // Budget for E-commerce project
        $customValue17 = new CustomValue();
        $customValue17->setCustomizedType('Project');
        $customValue17->setCustomizedId($this->getReference('project-ecommerce', \App\Entity\Project::class)->getId());
        $customValue17->setCustomField($this->getReference('custom-field-budget', \App\Entity\CustomField::class));
        $customValue17->setValue('150000.00');
        
        $manager->persist($customValue17);
        $this->addReference('custom-value-ecommerce-budget', $customValue17);

        // Client Type for E-commerce project
        $customValue18 = new CustomValue();
        $customValue18->setCustomizedType('Project');
        $customValue18->setCustomizedId($this->getReference('project-ecommerce', \App\Entity\Project::class)->getId());
        $customValue18->setCustomField($this->getReference('custom-field-client-type', \App\Entity\CustomField::class));
        $customValue18->setValue('External - Enterprise');
        
        $manager->persist($customValue18);
        $this->addReference('custom-value-ecommerce-client', $customValue18);

        // Budget for Mobile App project
        $customValue19 = new CustomValue();
        $customValue19->setCustomizedType('Project');
        $customValue19->setCustomizedId($this->getReference('project-mobile', \App\Entity\Project::class)->getId());
        $customValue19->setCustomField($this->getReference('custom-field-budget', \App\Entity\CustomField::class));
        $customValue19->setValue('80000.00');
        
        $manager->persist($customValue19);
        $this->addReference('custom-value-mobile-budget', $customValue19);

        // Client Type for Mobile App project
        $customValue20 = new CustomValue();
        $customValue20->setCustomizedType('Project');
        $customValue20->setCustomizedId($this->getReference('project-mobile', \App\Entity\Project::class)->getId());
        $customValue20->setCustomField($this->getReference('custom-field-client-type', \App\Entity\CustomField::class));
        $customValue20->setValue('External - Startup');
        
        $manager->persist($customValue20);
        $this->addReference('custom-value-mobile-client', $customValue20);

        // Budget for CRM System project
        $customValue21 = new CustomValue();
        $customValue21->setCustomizedType('Project');
        $customValue21->setCustomizedId($this->getReference('project-crm', \App\Entity\Project::class)->getId());
        $customValue21->setCustomField($this->getReference('custom-field-budget', \App\Entity\CustomField::class));
        $customValue21->setValue('200000.00');
        
        $manager->persist($customValue21);
        $this->addReference('custom-value-crm-budget', $customValue21);

        // Client Type for CRM System project
        $customValue22 = new CustomValue();
        $customValue22->setCustomizedType('Project');
        $customValue22->setCustomizedId($this->getReference('project-crm', \App\Entity\Project::class)->getId());
        $customValue22->setCustomField($this->getReference('custom-field-client-type', \App\Entity\CustomField::class));
        $customValue22->setValue('External - SMB');
        
        $manager->persist($customValue22);
        $this->addReference('custom-value-crm-client', $customValue22);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CustomFieldFixtures::class,
            UserFixtures::class,
            ProjectFixtures::class,
            IssueFixtures::class,
        ];
    }
}
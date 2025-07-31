<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\CustomField;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class CustomFieldFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Custom Field 1: Priority Level (Issue)
        $customField1 = new CustomField();
        $customField1->setType('IssueCustomField');
        $customField1->setName('Business Priority');
        $customField1->setFieldFormat('list');
        $customField1->setPossibleValues("Low\nMedium\nHigh\nCritical");
        $customField1->setIsRequired(0);
        $customField1->setIsForAll(1);
        $customField1->setIsFilter(1);
        $customField1->setSearchable(1);
        $customField1->setVisible(1);
        $customField1->setEditable(1);
        $customField1->setPosition(1);
        
        $manager->persist($customField1);
        $this->addReference('custom-field-business-priority', $customField1);

        // Custom Field 2: Estimated Effort (Issue)
        $customField2 = new CustomField();
        $customField2->setType('IssueCustomField');
        $customField2->setName('Estimated Effort (days)');
        $customField2->setFieldFormat('float');
        $customField2->setIsRequired(0);
        $customField2->setIsForAll(1);
        $customField2->setIsFilter(1);
        $customField2->setSearchable(1);
        $customField2->setVisible(1);
        $customField2->setEditable(1);
        $customField2->setPosition(2);
        $customField2->setMinLength(null);
        $customField2->setMaxLength(null);
        
        $manager->persist($customField2);
        $this->addReference('custom-field-estimated-effort', $customField2);

        // Custom Field 3: Customer Contact (Issue)
        $customField3 = new CustomField();
        $customField3->setType('IssueCustomField');
        $customField3->setName('Customer Contact');
        $customField3->setFieldFormat('string');
        $customField3->setIsRequired(0);
        $customField3->setIsForAll(0);
        $customField3->setIsFilter(1);
        $customField3->setSearchable(1);
        $customField3->setVisible(1);
        $customField3->setEditable(1);
        $customField3->setPosition(3);
        $customField3->setMaxLength(255);
        
        $manager->persist($customField3);
        $this->addReference('custom-field-customer-contact', $customField3);

        // Custom Field 4: Review Status (Issue)
        $customField4 = new CustomField();
        $customField4->setType('IssueCustomField');
        $customField4->setName('Code Review Status');
        $customField4->setFieldFormat('list');
        $customField4->setPossibleValues("Pending\nIn Review\nApproved\nRejected\nNot Required");
        $customField4->setIsRequired(0);
        $customField4->setIsForAll(1);
        $customField4->setIsFilter(1);
        $customField4->setSearchable(1);
        $customField4->setVisible(1);
        $customField4->setEditable(1);
        $customField4->setPosition(4);
        
        $manager->persist($customField4);
        $this->addReference('custom-field-review-status', $customField4);

        // Custom Field 5: Department (User)
        $customField5 = new CustomField();
        $customField5->setType('UserCustomField');
        $customField5->setName('Department');
        $customField5->setFieldFormat('list');
        $customField5->setPossibleValues("Development\nQA\nProduct Management\nDesign\nMarketing\nSales");
        $customField5->setIsRequired(0);
        $customField5->setIsForAll(1);
        $customField5->setIsFilter(1);
        $customField5->setSearchable(1);
        $customField5->setVisible(1);
        $customField5->setEditable(1);
        $customField5->setPosition(1);
        
        $manager->persist($customField5);
        $this->addReference('custom-field-department', $customField5);

        // Custom Field 6: Phone Number (User)
        $customField6 = new CustomField();
        $customField6->setType('UserCustomField');
        $customField6->setName('Phone Number');
        $customField6->setFieldFormat('string');
        $customField6->setRegexp('^[\+]?[0-9\-\(\)\s]+$');
        $customField6->setIsRequired(0);
        $customField6->setIsForAll(1);
        $customField6->setIsFilter(0);
        $customField6->setSearchable(1);
        $customField6->setVisible(1);
        $customField6->setEditable(1);
        $customField6->setPosition(2);
        $customField6->setMaxLength(50);
        
        $manager->persist($customField6);
        $this->addReference('custom-field-phone', $customField6);

        // Custom Field 7: Project Budget (Project)
        $customField7 = new CustomField();
        $customField7->setType('ProjectCustomField');
        $customField7->setName('Budget (USD)');
        $customField7->setFieldFormat('float');
        $customField7->setIsRequired(0);
        $customField7->setIsForAll(1);
        $customField7->setIsFilter(1);
        $customField7->setSearchable(1);
        $customField7->setVisible(1);
        $customField7->setEditable(1);
        $customField7->setPosition(1);
        
        $manager->persist($customField7);
        $this->addReference('custom-field-budget', $customField7);

        // Custom Field 8: Client Type (Project)
        $customField8 = new CustomField();
        $customField8->setType('ProjectCustomField');
        $customField8->setName('Client Type');
        $customField8->setFieldFormat('list');
        $customField8->setPossibleValues("Internal\nExternal - Enterprise\nExternal - SMB\nExternal - Startup\nOSS Project");
        $customField8->setIsRequired(0);
        $customField8->setIsForAll(1);
        $customField8->setIsFilter(1);
        $customField8->setSearchable(1);
        $customField8->setVisible(1);
        $customField8->setEditable(1);
        $customField8->setPosition(2);
        
        $manager->persist($customField8);
        $this->addReference('custom-field-client-type', $customField8);

        // Custom Field 9: Testing Notes (Issue)
        $customField9 = new CustomField();
        $customField9->setType('IssueCustomField');
        $customField9->setName('Testing Notes');
        $customField9->setFieldFormat('text');
        $customField9->setIsRequired(0);
        $customField9->setIsForAll(0);
        $customField9->setIsFilter(0);
        $customField9->setSearchable(1);
        $customField9->setVisible(1);
        $customField9->setEditable(1);
        $customField9->setPosition(5);
        
        $manager->persist($customField9);
        $this->addReference('custom-field-testing-notes', $customField9);

        // Custom Field 10: Version Affected (Issue)
        $customField10 = new CustomField();
        $customField10->setType('IssueCustomField');
        $customField10->setName('Version Affected');
        $customField10->setFieldFormat('version');
        $customField10->setIsRequired(0);
        $customField10->setIsForAll(1);
        $customField10->setIsFilter(1);
        $customField10->setSearchable(1);
        $customField10->setVisible(1);
        $customField10->setEditable(1);
        $customField10->setPosition(6);
        
        $manager->persist($customField10);
        $this->addReference('custom-field-version-affected', $customField10);

        $manager->flush();
    }
}
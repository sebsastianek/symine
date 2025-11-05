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
        $customField1->setIsRequired(false);
        $customField1->setIsForAll(true);
        $customField1->setIsFilter(true);
        $customField1->setSearchable(true);
        $customField1->setVisible(true);
        $customField1->setEditable(true);
        $customField1->setPosition(1);
        
        $manager->persist($customField1);
        $this->addReference('custom-field-business-priority', $customField1);

        // Custom Field 2: Estimated Effort (Issue)
        $customField2 = new CustomField();
        $customField2->setType('IssueCustomField');
        $customField2->setName('Estimated Effort (days)');
        $customField2->setFieldFormat('float');
        $customField2->setIsRequired(false);
        $customField2->setIsForAll(true);
        $customField2->setIsFilter(true);
        $customField2->setSearchable(true);
        $customField2->setVisible(true);
        $customField2->setEditable(true);
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
        $customField3->setIsRequired(false);
        $customField3->setIsForAll(false);
        $customField3->setIsFilter(true);
        $customField3->setSearchable(true);
        $customField3->setVisible(true);
        $customField3->setEditable(true);
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
        $customField4->setIsRequired(false);
        $customField4->setIsForAll(true);
        $customField4->setIsFilter(true);
        $customField4->setSearchable(true);
        $customField4->setVisible(true);
        $customField4->setEditable(true);
        $customField4->setPosition(4);
        
        $manager->persist($customField4);
        $this->addReference('custom-field-review-status', $customField4);

        // Custom Field 5: Department (User)
        $customField5 = new CustomField();
        $customField5->setType('UserCustomField');
        $customField5->setName('Department');
        $customField5->setFieldFormat('list');
        $customField5->setPossibleValues("Development\nQA\nProduct Management\nDesign\nMarketing\nSales");
        $customField5->setIsRequired(false);
        $customField5->setIsForAll(true);
        $customField5->setIsFilter(true);
        $customField5->setSearchable(true);
        $customField5->setVisible(true);
        $customField5->setEditable(true);
        $customField5->setPosition(1);
        
        $manager->persist($customField5);
        $this->addReference('custom-field-department', $customField5);

        // Custom Field 6: Phone Number (User)
        $customField6 = new CustomField();
        $customField6->setType('UserCustomField');
        $customField6->setName('Phone Number');
        $customField6->setFieldFormat('string');
        $customField6->setRegexp('^[\+]?[0-9\-\(\)\s]+$');
        $customField6->setIsRequired(false);
        $customField6->setIsForAll(true);
        $customField6->setIsFilter(false);
        $customField6->setSearchable(true);
        $customField6->setVisible(true);
        $customField6->setEditable(true);
        $customField6->setPosition(2);
        $customField6->setMaxLength(50);
        
        $manager->persist($customField6);
        $this->addReference('custom-field-phone', $customField6);

        // Custom Field 7: Project Budget (Project)
        $customField7 = new CustomField();
        $customField7->setType('ProjectCustomField');
        $customField7->setName('Budget (USD)');
        $customField7->setFieldFormat('float');
        $customField7->setIsRequired(false);
        $customField7->setIsForAll(true);
        $customField7->setIsFilter(true);
        $customField7->setSearchable(true);
        $customField7->setVisible(true);
        $customField7->setEditable(true);
        $customField7->setPosition(1);
        
        $manager->persist($customField7);
        $this->addReference('custom-field-budget', $customField7);

        // Custom Field 8: Client Type (Project)
        $customField8 = new CustomField();
        $customField8->setType('ProjectCustomField');
        $customField8->setName('Client Type');
        $customField8->setFieldFormat('list');
        $customField8->setPossibleValues("Internal\nExternal - Enterprise\nExternal - SMB\nExternal - Startup\nOSS Project");
        $customField8->setIsRequired(false);
        $customField8->setIsForAll(true);
        $customField8->setIsFilter(true);
        $customField8->setSearchable(true);
        $customField8->setVisible(true);
        $customField8->setEditable(true);
        $customField8->setPosition(2);
        
        $manager->persist($customField8);
        $this->addReference('custom-field-client-type', $customField8);

        // Custom Field 9: Testing Notes (Issue)
        $customField9 = new CustomField();
        $customField9->setType('IssueCustomField');
        $customField9->setName('Testing Notes');
        $customField9->setFieldFormat('text');
        $customField9->setIsRequired(false);
        $customField9->setIsForAll(false);
        $customField9->setIsFilter(false);
        $customField9->setSearchable(true);
        $customField9->setVisible(true);
        $customField9->setEditable(true);
        $customField9->setPosition(5);
        
        $manager->persist($customField9);
        $this->addReference('custom-field-testing-notes', $customField9);

        // Custom Field 10: Version Affected (Issue)
        $customField10 = new CustomField();
        $customField10->setType('IssueCustomField');
        $customField10->setName('Version Affected');
        $customField10->setFieldFormat('version');
        $customField10->setIsRequired(false);
        $customField10->setIsForAll(true);
        $customField10->setIsFilter(true);
        $customField10->setSearchable(true);
        $customField10->setVisible(true);
        $customField10->setEditable(true);
        $customField10->setPosition(6);
        
        $manager->persist($customField10);
        $this->addReference('custom-field-version-affected', $customField10);

        // Custom Field 11: Severity Level (Issue)
        $customField11 = new CustomField();
        $customField11->setType('IssueCustomField');
        $customField11->setName('Severity Level');
        $customField11->setFieldFormat('list');
        $customField11->setIsRequired(false);
        $customField11->setIsForAll(true);
        $customField11->setIsFilter(true);
        $customField11->setSearchable(true);
        $customField11->setVisible(true);
        $customField11->setEditable(true);
        $customField11->setPosition(7);

        $manager->persist($customField11);
        $this->addReference('custom-field-severity-level', $customField11);

        // Custom Field 12: Testing Phase (Issue)
        $customField12 = new CustomField();
        $customField12->setType('IssueCustomField');
        $customField12->setName('Testing Phase');
        $customField12->setFieldFormat('list');
        $customField12->setIsRequired(false);
        $customField12->setIsForAll(true);
        $customField12->setIsFilter(true);
        $customField12->setSearchable(true);
        $customField12->setVisible(true);
        $customField12->setEditable(true);
        $customField12->setPosition(8);

        $manager->persist($customField12);
        $this->addReference('custom-field-testing-phase', $customField12);

        // Custom Field 13: Resolution Type (Issue)
        $customField13 = new CustomField();
        $customField13->setType('IssueCustomField');
        $customField13->setName('Resolution Type');
        $customField13->setFieldFormat('list');
        $customField13->setIsRequired(false);
        $customField13->setIsForAll(true);
        $customField13->setIsFilter(true);
        $customField13->setSearchable(true);
        $customField13->setVisible(true);
        $customField13->setEditable(true);
        $customField13->setPosition(9);

        $manager->persist($customField13);
        $this->addReference('custom-field-resolution-type', $customField13);

        // Custom Field 14: Preferred Component (User)
        $customField14 = new CustomField();
        $customField14->setType('UserCustomField');
        $customField14->setName('Preferred Component');
        $customField14->setFieldFormat('list');
        $customField14->setIsRequired(false);
        $customField14->setIsForAll(true);
        $customField14->setIsFilter(false);
        $customField14->setSearchable(false);
        $customField14->setVisible(true);
        $customField14->setEditable(true);
        $customField14->setPosition(1);

        $manager->persist($customField14);
        $this->addReference('custom-field-preferred-component', $customField14);

        // Custom Field 15: Environment (Project)
        $customField15 = new CustomField();
        $customField15->setType('ProjectCustomField');
        $customField15->setName('Environment');
        $customField15->setFieldFormat('list');
        $customField15->setIsRequired(false);
        $customField15->setIsForAll(true);
        $customField15->setIsFilter(true);
        $customField15->setSearchable(true);
        $customField15->setVisible(true);
        $customField15->setEditable(true);
        $customField15->setPosition(1);

        $manager->persist($customField15);
        $this->addReference('custom-field-environment', $customField15);

        $manager->flush();
    }
}
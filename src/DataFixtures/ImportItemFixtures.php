<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ImportItem;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ImportItemFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Import items for user import
        $item1 = new ImportItem();
        $item1->setImport($this->getReference('import-users', \App\Entity\Import::class));
        $item1->setPosition(1);
        $item1->setObjId(15); // User ID that was created
        $item1->setMessage('User "alice.johnson" imported successfully');
        $item1->setUniqueId('user_001');
        
        $manager->persist($item1);
        $this->addReference('import-item-user-1', $item1);

        $item2 = new ImportItem();
        $item2->setImport($this->getReference('import-users', \App\Entity\Import::class));
        $item2->setPosition(2);
        $item2->setObjId(16);
        $item2->setMessage('User "bob.wilson" imported successfully');
        $item2->setUniqueId('user_002');
        
        $manager->persist($item2);
        $this->addReference('import-item-user-2', $item2);

        $item3 = new ImportItem();
        $item3->setImport($this->getReference('import-users', \App\Entity\Import::class));
        $item3->setPosition(3);
        $item3->setObjId(null); // Failed import
        $item3->setMessage('Error: Invalid email format for user "charlie.brown"');
        $item3->setUniqueId('user_003');
        
        $manager->persist($item3);
        $this->addReference('import-item-user-3', $item3);

        $item4 = new ImportItem();
        $item4->setImport($this->getReference('import-users', \App\Entity\Import::class));
        $item4->setPosition(4);
        $item4->setObjId(17);
        $item4->setMessage('User "diana.prince" imported successfully');
        $item4->setUniqueId('user_004');
        
        $manager->persist($item4);
        $this->addReference('import-item-user-4', $item4);

        // Import items for issue import
        $item5 = new ImportItem();
        $item5->setImport($this->getReference('import-issues', \App\Entity\Import::class));
        $item5->setPosition(1);
        $item5->setObjId(25); // Issue ID that was created
        $item5->setMessage('Issue "Login page not responsive" imported successfully');
        $item5->setUniqueId('issue_001');
        
        $manager->persist($item5);
        $this->addReference('import-item-issue-1', $item5);

        $item6 = new ImportItem();
        $item6->setImport($this->getReference('import-issues', \App\Entity\Import::class));
        $item6->setPosition(2);
        $item6->setObjId(26);
        $item6->setMessage('Issue "Database connection timeout" imported successfully');
        $item6->setUniqueId('issue_002');
        
        $manager->persist($item6);
        $this->addReference('import-item-issue-2', $item6);

        $item7 = new ImportItem();
        $item7->setImport($this->getReference('import-issues', \App\Entity\Import::class));
        $item7->setPosition(3);
        $item7->setObjId(null);
        $item7->setMessage('Error: Invalid priority value "Super High"');
        $item7->setUniqueId('issue_003');
        
        $manager->persist($item7);
        $this->addReference('import-item-issue-3', $item7);

        $item8 = new ImportItem();
        $item8->setImport($this->getReference('import-issues', \App\Entity\Import::class));
        $item8->setPosition(4);
        $item8->setObjId(27);
        $item8->setMessage('Issue "Add search functionality" imported successfully');
        $item8->setUniqueId('issue_004');
        
        $manager->persist($item8);
        $this->addReference('import-item-issue-4', $item8);

        $item9 = new ImportItem();
        $item9->setImport($this->getReference('import-issues', \App\Entity\Import::class));
        $item9->setPosition(5);
        $item9->setObjId(null);
        $item9->setMessage('Warning: Assigned user "unknown.user" not found, set to unassigned');
        $item9->setUniqueId('issue_005');
        
        $manager->persist($item9);
        $this->addReference('import-item-issue-5', $item9);

        // Import items for time entries import
        $item10 = new ImportItem();
        $item10->setImport($this->getReference('import-time-entries', \App\Entity\Import::class));
        $item10->setPosition(1);
        $item10->setObjId(45); // TimeEntry ID
        $item10->setMessage('Time entry for 8.5 hours imported successfully');
        $item10->setUniqueId('time_001');
        
        $manager->persist($item10);
        $this->addReference('import-item-time-1', $item10);

        $item11 = new ImportItem();
        $item11->setImport($this->getReference('import-time-entries', \App\Entity\Import::class));
        $item11->setPosition(2);
        $item11->setObjId(46);
        $item11->setMessage('Time entry for 4.0 hours imported successfully');
        $item11->setUniqueId('time_002');
        
        $manager->persist($item11);
        $this->addReference('import-item-time-2', $item11);

        $item12 = new ImportItem();
        $item12->setImport($this->getReference('import-time-entries', \App\Entity\Import::class));
        $item12->setPosition(3);
        $item12->setObjId(null);
        $item12->setMessage('Error: Invalid hours value "ten"');
        $item12->setUniqueId('time_003');
        
        $manager->persist($item12);
        $this->addReference('import-item-time-3', $item12);

        // Import items for failed import
        $item13 = new ImportItem();
        $item13->setImport($this->getReference('import-failed', \App\Entity\Import::class));
        $item13->setPosition(1);
        $item13->setObjId(null);
        $item13->setMessage('Error: Missing required field "subject"');
        $item13->setUniqueId('failed_001');
        
        $manager->persist($item13);
        $this->addReference('import-item-failed-1', $item13);

        $item14 = new ImportItem();
        $item14->setImport($this->getReference('import-failed', \App\Entity\Import::class));
        $item14->setPosition(2);
        $item14->setObjId(null);
        $item14->setMessage('Error: Corrupted data in line 2');
        $item14->setUniqueId('failed_002');
        
        $manager->persist($item14);
        $this->addReference('import-item-failed-2', $item14);

        $item15 = new ImportItem();
        $item15->setImport($this->getReference('import-failed', \App\Entity\Import::class));
        $item15->setPosition(3);
        $item15->setObjId(null);
        $item15->setMessage('Error: Invalid date format in "due_date" field');
        $item15->setUniqueId('failed_003');
        
        $manager->persist($item15);
        $this->addReference('import-item-failed-3', $item15);

        // Import items for project import
        $item16 = new ImportItem();
        $item16->setImport($this->getReference('import-projects', \App\Entity\Import::class));
        $item16->setPosition(1);
        $item16->setObjId(8); // Project ID
        $item16->setMessage('Project "Customer Portal" imported successfully');
        $item16->setUniqueId('project_001');
        
        $manager->persist($item16);
        $this->addReference('import-item-project-1', $item16);

        $item17 = new ImportItem();
        $item17->setImport($this->getReference('import-projects', \App\Entity\Import::class));
        $item17->setPosition(2);
        $item17->setObjId(9);
        $item17->setMessage('Project "Internal Tools" imported successfully');
        $item17->setUniqueId('project_002');
        
        $manager->persist($item17);
        $this->addReference('import-item-project-2', $item17);

        $item18 = new ImportItem();
        $item18->setImport($this->getReference('import-projects', \App\Entity\Import::class));
        $item18->setPosition(3);
        $item18->setObjId(null);
        $item18->setMessage('Warning: Project identifier "legacy-sys" already exists, skipped');
        $item18->setUniqueId('project_003');
        
        $manager->persist($item18);
        $this->addReference('import-item-project-3', $item18);

        // Import items for version import
        $item19 = new ImportItem();
        $item19->setImport($this->getReference('import-versions', \App\Entity\Import::class));
        $item19->setPosition(1);
        $item19->setObjId(12); // Version ID
        $item19->setMessage('Version "v2.1.0" imported successfully');
        $item19->setUniqueId('version_001');
        
        $manager->persist($item19);
        $this->addReference('import-item-version-1', $item19);

        $item20 = new ImportItem();
        $item20->setImport($this->getReference('import-versions', \App\Entity\Import::class));
        $item20->setPosition(2);
        $item20->setObjId(13);
        $item20->setMessage('Version "v2.2.0" imported successfully');
        $item20->setUniqueId('version_002');
        
        $manager->persist($item20);
        $this->addReference('import-item-version-2', $item20);

        // Import items for custom field import
        $item21 = new ImportItem();
        $item21->setImport($this->getReference('import-custom-fields', \App\Entity\Import::class));
        $item21->setPosition(1);
        $item21->setObjId(15); // CustomField ID
        $item21->setMessage('Custom field "Customer Priority" imported successfully');
        $item21->setUniqueId('cf_001');
        
        $manager->persist($item21);
        $this->addReference('import-item-cf-1', $item21);

        $item22 = new ImportItem();
        $item22->setImport($this->getReference('import-custom-fields', \App\Entity\Import::class));
        $item22->setPosition(2);
        $item22->setObjId(16);
        $item22->setMessage('Custom field "Department" imported successfully');
        $item22->setUniqueId('cf_002');
        
        $manager->persist($item22);
        $this->addReference('import-item-cf-2', $item22);

        $item23 = new ImportItem();
        $item23->setImport($this->getReference('import-custom-fields', \App\Entity\Import::class));
        $item23->setPosition(3);
        $item23->setObjId(null);
        $item23->setMessage('Error: Invalid field type "multi-checkbox"');
        $item23->setUniqueId('cf_003');
        
        $manager->persist($item23);
        $this->addReference('import-item-cf-3', $item23);

        // Import items for in-progress import
        $item24 = new ImportItem();
        $item24->setImport($this->getReference('import-in-progress', \App\Entity\Import::class));
        $item24->setPosition(1);
        $item24->setObjId(18); // User ID
        $item24->setMessage('User "emma.watson" imported successfully');
        $item24->setUniqueId('new_user_001');
        
        $manager->persist($item24);
        $this->addReference('import-item-progress-1', $item24);

        $item25 = new ImportItem();
        $item25->setImport($this->getReference('import-in-progress', \App\Entity\Import::class));
        $item25->setPosition(2);
        $item25->setObjId(19);
        $item25->setMessage('User "frank.sinatra" imported successfully');
        $item25->setUniqueId('new_user_002');
        
        $manager->persist($item25);
        $this->addReference('import-item-progress-2', $item25);

        // Items with different status/result types
        $item26 = new ImportItem();
        $item26->setImport($this->getReference('import-users', \App\Entity\Import::class));
        $item26->setPosition(150); // Last item
        $item26->setObjId(null);
        $item26->setMessage('Error: Duplicate login "admin" already exists');
        $item26->setUniqueId('user_150');
        
        $manager->persist($item26);
        $this->addReference('import-item-duplicate', $item26);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ImportFixtures::class,
        ];
    }
}
<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\JournalDetail;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class JournalDetailFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Journal Detail 1: Status change from New to In Progress
        $detail1 = new JournalDetail();
        $detail1->setJournal($this->getReference('journal-issue-1-status', \App\Entity\Journal::class));
        $detail1->setProperty('attr');
        $detail1->setPropKey('status_id');
        $detail1->setOldValue('1'); // New status ID
        $detail1->setValue('2'); // In Progress status ID
        
        $manager->persist($detail1);
        $this->addReference('journal-detail-status-change', $detail1);

        // Journal Detail 2: Assignee change  
        $detail2 = new JournalDetail();
        $detail2->setJournal($this->getReference('journal-issue-1-assigned', \App\Entity\Journal::class));
        $detail2->setProperty('attr');
        $detail2->setPropKey('assigned_to_id');
        $detail2->setOldValue(''); // Unassigned
        $detail2->setValue('2'); // Assigned to user ID 2
        
        $manager->persist($detail2);
        $this->addReference('journal-detail-assignee-change', $detail2);

        // Journal Detail 3: Priority change from Normal to High
        $detail3 = new JournalDetail();
        $detail3->setJournal($this->getReference('journal-issue-2-priority', \App\Entity\Journal::class));
        $detail3->setProperty('attr');
        $detail3->setPropKey('priority_id');
        $detail3->setOldValue('2'); // Normal priority
        $detail3->setValue('4'); // High priority
        
        $manager->persist($detail3);
        $this->addReference('journal-detail-priority-change', $detail3);

        // Journal Detail 4: Subject change
        $detail4 = new JournalDetail();
        $detail4->setJournal($this->getReference('journal-issue-3-subject', \App\Entity\Journal::class));
        $detail4->setProperty('attr');
        $detail4->setPropKey('subject');
        $detail4->setOldValue('Fix login issue');
        $detail4->setValue('Fix user authentication bug in login form');
        
        $manager->persist($detail4);
        $this->addReference('journal-detail-subject-change', $detail4);

        // Journal Detail 5: Description change
        $detail5 = new JournalDetail();
        $detail5->setJournal($this->getReference('journal-issue-3-subject', \App\Entity\Journal::class));
        $detail5->setProperty('attr');
        $detail5->setPropKey('description');
        $detail5->setOldValue('Users cannot log in');
        $detail5->setValue('Users are experiencing authentication failures when attempting to log in. The issue appears to be related to session handling and occurs intermittently across different browsers.');
        
        $manager->persist($detail5);
        $this->addReference('journal-detail-description-change', $detail5);

        // Journal Detail 6: Custom field change - Business Priority
        $detail6 = new JournalDetail();
        $detail6->setJournal($this->getReference('journal-issue-4-custom', \App\Entity\Journal::class));
        $detail6->setProperty('cf');
        $detail6->setPropKey('1'); // Custom field ID 1 (Business Priority)
        $detail6->setOldValue('Medium');
        $detail6->setValue('High');
        
        $manager->persist($detail6);
        $this->addReference('journal-detail-custom-field', $detail6);

        // Journal Detail 7: Due date change
        $detail7 = new JournalDetail();
        $detail7->setJournal($this->getReference('journal-issue-5-due-date', \App\Entity\Journal::class));
        $detail7->setProperty('attr');
        $detail7->setPropKey('due_date');
        $detail7->setOldValue('2024-03-15');
        $detail7->setValue('2024-03-20');
        
        $manager->persist($detail7);
        $this->addReference('journal-detail-due-date', $detail7);

        // Journal Detail 8: Estimated time change
        $detail8 = new JournalDetail();
        $detail8->setJournal($this->getReference('journal-issue-5-due-date', \App\Entity\Journal::class));
        $detail8->setProperty('attr');
        $detail8->setPropKey('estimated_hours');
        $detail8->setOldValue('8.0');
        $detail8->setValue('12.0');
        
        $manager->persist($detail8);
        $this->addReference('journal-detail-estimated-time', $detail8);

        // Journal Detail 9: Done ratio change
        $detail9 = new JournalDetail();
        $detail9->setJournal($this->getReference('journal-issue-6-progress', \App\Entity\Journal::class));
        $detail9->setProperty('attr');
        $detail9->setPropKey('done_ratio');
        $detail9->setOldValue('0');
        $detail9->setValue('25');
        
        $manager->persist($detail9);
        $this->addReference('journal-detail-done-ratio', $detail9);

        // Journal Detail 10: Category change
        $detail10 = new JournalDetail();
        $detail10->setJournal($this->getReference('journal-issue-7-category', \App\Entity\Journal::class));
        $detail10->setProperty('attr');
        $detail10->setPropKey('category_id');
        $detail10->setOldValue(''); // No category
        $detail10->setValue('1'); // Backend category
        
        $manager->persist($detail10);
        $this->addReference('journal-detail-category-change', $detail10);

        // Journal Detail 11: Version change
        $detail11 = new JournalDetail();
        $detail11->setJournal($this->getReference('journal-issue-8-version', \App\Entity\Journal::class));
        $detail11->setProperty('attr');
        $detail11->setPropKey('fixed_version_id');
        $detail11->setOldValue('');
        $detail11->setValue('1'); // Version 1.0
        
        $manager->persist($detail11);
        $this->addReference('journal-detail-version-change', $detail11);

        // Journal Detail 12: Tracker change from Bug to Feature
        $detail12 = new JournalDetail();
        $detail12->setJournal($this->getReference('journal-issue-9-tracker', \App\Entity\Journal::class));
        $detail12->setProperty('attr');
        $detail12->setPropKey('tracker_id');
        $detail12->setOldValue('1'); // Bug tracker
        $detail12->setValue('2'); // Feature tracker
        
        $manager->persist($detail12);
        $this->addReference('journal-detail-tracker-change', $detail12);

        // Journal Detail 13: Start date change
        $detail13 = new JournalDetail();
        $detail13->setJournal($this->getReference('journal-issue-10-start-date', \App\Entity\Journal::class));
        $detail13->setProperty('attr');
        $detail13->setPropKey('start_date');
        $detail13->setOldValue('');
        $detail13->setValue('2024-02-20');
        
        $manager->persist($detail13);
        $this->addReference('journal-detail-start-date', $detail13);

        // Journal Detail 14: Custom field - Customer Contact
        $detail14 = new JournalDetail();
        $detail14->setJournal($this->getReference('journal-issue-4-custom', \App\Entity\Journal::class));
        $detail14->setProperty('cf');
        $detail14->setPropKey('2'); // Custom field ID 2 (Customer Contact)
        $detail14->setOldValue('');
        $detail14->setValue('john.doe@example.com');
        
        $manager->persist($detail14);
        $this->addReference('journal-detail-customer-contact', $detail14);

        // Journal Detail 15: Multiple attachments added
        $detail15 = new JournalDetail();
        $detail15->setJournal($this->getReference('journal-issue-11-attachment', \App\Entity\Journal::class));
        $detail15->setProperty('attachment');
        $detail15->setPropKey('1'); // Attachment ID
        $detail15->setOldValue('');
        $detail15->setValue('screenshot.png');
        
        $manager->persist($detail15);
        $this->addReference('journal-detail-attachment', $detail15);

        // Journal Detail 16: Parent issue set
        $detail16 = new JournalDetail();
        $detail16->setJournal($this->getReference('journal-issue-12-parent', \App\Entity\Journal::class));
        $detail16->setProperty('attr');
        $detail16->setPropKey('parent_id');
        $detail16->setOldValue('');
        $detail16->setValue('1'); // Parent issue ID
        
        $manager->persist($detail16);
        $this->addReference('journal-detail-parent-issue', $detail16);

        // Journal Detail 17: Watcher added
        $detail17 = new JournalDetail();
        $detail17->setJournal($this->getReference('journal-issue-13-watcher', \App\Entity\Journal::class));
        $detail17->setProperty('attr');
        $detail17->setPropKey('watcher_user_ids');
        $detail17->setOldValue('2, 3'); // Previous watchers
        $detail17->setValue('2, 3, 4'); // Added user 4 as watcher
        
        $manager->persist($detail17);
        $this->addReference('journal-detail-watcher', $detail17);

        // Journal Detail 18: Status change to Resolved
        $detail18 = new JournalDetail();
        $detail18->setJournal($this->getReference('journal-issue-14-resolved', \App\Entity\Journal::class));
        $detail18->setProperty('attr');
        $detail18->setPropKey('status_id');
        $detail18->setOldValue('2'); // In Progress
        $detail18->setValue('3'); // Resolved
        
        $manager->persist($detail18);
        $this->addReference('journal-detail-resolved', $detail18);

        // Journal Detail 19: Done ratio set to 100%
        $detail19 = new JournalDetail();
        $detail19->setJournal($this->getReference('journal-issue-14-resolved', \App\Entity\Journal::class));
        $detail19->setProperty('attr');
        $detail19->setPropKey('done_ratio');
        $detail19->setOldValue('75');
        $detail19->setValue('100');
        
        $manager->persist($detail19);
        $this->addReference('journal-detail-completed', $detail19);

        // Journal Detail 20: Private journal note (no property changes)
        $detail20 = new JournalDetail();
        $detail20->setJournal($this->getReference('journal-issue-15-private', \App\Entity\Journal::class));
        $detail20->setProperty('');
        $detail20->setPropKey('');
        $detail20->setOldValue('');
        $detail20->setValue(''); // Private note with no field changes
        
        $manager->persist($detail20);
        $this->addReference('journal-detail-private-note', $detail20);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            JournalFixtures::class,
        ];
    }
}
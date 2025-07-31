<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Journal;
use App\Entity\JournalDetail;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class JournalFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Journal 1: Issue status change (authentication issue)
        $journal1 = new Journal();
        $journal1->setJournalized($this->getReference('issue-auth', \App\Entity\Issue::class));
        $journal1->setJournalizedType('Issue');
        $journal1->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $journal1->setNotes('Updated issue status and progress');
        $journal1->setCreatedOn(new \DateTime('2024-01-15 10:30:00'));
        
        $manager->persist($journal1);
        $this->addReference('journal-auth-status-change', $journal1);

        // Journal detail: Status change from New to In Progress
        $detail1 = new JournalDetail();
        $detail1->setJournal($journal1);
        $detail1->setProperty('attr');
        $detail1->setPropKey('status_id');
        $detail1->setOldValue('1'); // New
        $detail1->setValue('2'); // In Progress
        
        $manager->persist($detail1);

        // Journal detail: Done ratio change
        $detail2 = new JournalDetail();
        $detail2->setJournal($journal1);
        $detail2->setProperty('attr');
        $detail2->setPropKey('done_ratio');
        $detail2->setOldValue('0');
        $detail2->setValue('25');
        
        $manager->persist($detail2);

        // Journal 2: Issue resolution
        $journal2 = new Journal();
        $journal2->setJournalized($this->getReference('issue-auth', \App\Entity\Issue::class));
        $journal2->setJournalizedType('Issue');
        $journal2->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $journal2->setNotes('Completed authentication system implementation. All tests pass.');
        $journal2->setCreatedOn(new \DateTime('2024-01-25 16:45:00'));
        
        $manager->persist($journal2);
        $this->addReference('journal-auth-completion', $journal2);

        // Journal detail: Status change to Resolved
        $detail3 = new JournalDetail();
        $detail3->setJournal($journal2);
        $detail3->setProperty('attr');
        $detail3->setPropKey('status_id');
        $detail3->setOldValue('2'); // In Progress
        $detail3->setValue('5'); // Resolved
        
        $manager->persist($detail3);

        // Journal detail: Done ratio to 100%
        $detail4 = new JournalDetail();
        $detail4->setJournal($journal2);
        $detail4->setProperty('attr');
        $detail4->setPropKey('done_ratio');
        $detail4->setOldValue('25');
        $detail4->setValue('100');
        
        $manager->persist($detail4);

        // Journal 3: Priority change for frontend layout issue
        $journal3 = new Journal();
        $journal3->setJournalized($this->getReference('issue-frontend-layout', \App\Entity\Issue::class));
        $journal3->setJournalizedType('Issue');
        $journal3->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $journal3->setNotes('Increased priority due to client feedback');
        $journal3->setCreatedOn(new \DateTime('2024-02-10 14:20:00'));
        
        $manager->persist($journal3);
        $this->addReference('journal-frontend-priority-change', $journal3);

        // Journal detail: Priority change from Normal to High
        $detail5 = new JournalDetail();
        $detail5->setJournal($journal3);
        $detail5->setProperty('attr');
        $detail5->setPropKey('priority_id');
        $detail5->setOldValue('2'); // Normal
        $detail5->setValue('3'); // High
        
        $manager->persist($detail5);

        // Journal 4: Assignment change for login bug
        $journal4 = new Journal();
        $journal4->setJournalized($this->getReference('issue-login-bug', \App\Entity\Issue::class));
        $journal4->setJournalizedType('Issue');
        $journal4->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $journal4->setNotes('Reassigned to frontend developer for urgent fix');
        $journal4->setCreatedOn(new \DateTime('2024-02-14 12:00:00'));
        
        $manager->persist($journal4);
        $this->addReference('journal-login-bug-assignment', $journal4);

        // Journal detail: Assigned to change
        $detail6 = new JournalDetail();
        $detail6->setJournal($journal4);
        $detail6->setProperty('attr');
        $detail6->setPropKey('assigned_to_id');
        $detail6->setOldValue(''); // Unassigned
        $detail6->setValue('3'); // Sarah Garcia
        
        $manager->persist($detail6);

        // Journal 5: Due date change for mobile app
        $journal5 = new Journal();
        $journal5->setJournalized($this->getReference('issue-mobile-parent', \App\Entity\Issue::class));
        $journal5->setJournalizedType('Issue');
        $journal5->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $journal5->setNotes('Extended due date after scope increase');
        $journal5->setCreatedOn(new \DateTime('2024-03-01 11:30:00'));
        
        $manager->persist($journal5);
        $this->addReference('journal-mobile-due-date-change', $journal5);

        // Journal detail: Due date change
        $detail7 = new JournalDetail();
        $detail7->setJournal($journal5);
        $detail7->setProperty('attr');
        $detail7->setPropKey('due_date');
        $detail7->setOldValue('2024-04-15');
        $detail7->setValue('2024-04-30');
        
        $manager->persist($detail7);

        // Journal 6: Comment addition
        $journal6 = new Journal();
        $journal6->setJournalized($this->getReference('issue-support-login', \App\Entity\Issue::class));
        $journal6->setJournalizedType('Issue');
        $journal6->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        $journal6->setNotes('Contacted customer for additional information about login attempts and browser details.');
        $journal6->setCreatedOn(new \DateTime('2024-02-28 17:15:00'));
        
        $manager->persist($journal6);
        $this->addReference('journal-support-comment', $journal6);

        // Journal 7: Tracker change for research task
        $journal7 = new Journal();
        $journal7->setJournalized($this->getReference('issue-security-private', \App\Entity\Issue::class));
        $journal7->setJournalizedType('Issue');
        $journal7->setUser($this->getReference('user-admin', \App\Entity\User::class));
        $journal7->setNotes('Changed tracker type to better categorize this security assessment');
        $journal7->setCreatedOn(new \DateTime('2024-02-24 09:45:00'));
        
        $manager->persist($journal7);
        $this->addReference('journal-security-tracker-change', $journal7);

        // Journal detail: Tracker change
        $detail8 = new JournalDetail();
        $detail8->setJournal($journal7);
        $detail8->setProperty('attr');
        $detail8->setPropKey('tracker_id');
        $detail8->setOldValue('1'); // Bug
        $detail8->setValue('6'); // Research
        
        $manager->persist($detail8);

        // Journal 8: Estimated hours update
        $journal8 = new Journal();
        $journal8->setJournalized($this->getReference('issue-api-docs', \App\Entity\Issue::class));
        $journal8->setJournalizedType('Issue');
        $journal8->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $journal8->setNotes('Updated time estimate based on actual progress');
        $journal8->setCreatedOn(new \DateTime('2024-03-02 10:15:00'));
        
        $manager->persist($journal8);
        $this->addReference('journal-api-docs-estimate', $journal8);

        // Journal detail: Estimated hours change
        $detail9 = new JournalDetail();
        $detail9->setJournal($journal8);
        $detail9->setProperty('attr');
        $detail9->setPropKey('estimated_hours');
        $detail9->setOldValue('24.0');
        $detail9->setValue('32.0');
        
        $manager->persist($detail9);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            IssueFixtures::class,
        ];
    }
}
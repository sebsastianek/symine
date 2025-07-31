<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\IssueStatuse;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class IssueStatusFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Create New status
        $new = new IssueStatuse();
        $new->setName('New');
        $new->setIsClosed(0);
        $new->setPosition(1);
        $new->setDefaultDoneRatio(null);
        
        $manager->persist($new);
        $this->addReference('status-new', $new);

        // Create In Progress status
        $inProgress = new IssueStatuse();
        $inProgress->setName('In Progress');
        $inProgress->setIsClosed(0);
        $inProgress->setPosition(2);
        $inProgress->setDefaultDoneRatio(10);
        
        $manager->persist($inProgress);
        $this->addReference('status-in-progress', $inProgress);

        // Create Under Review status
        $underReview = new IssueStatuse();
        $underReview->setName('Under Review');
        $underReview->setIsClosed(0);
        $underReview->setPosition(3);
        $underReview->setDefaultDoneRatio(70);
        
        $manager->persist($underReview);
        $this->addReference('status-under-review', $underReview);

        // Create Testing status
        $testing = new IssueStatuse();
        $testing->setName('Testing');
        $testing->setIsClosed(0);
        $testing->setPosition(4);
        $testing->setDefaultDoneRatio(80);
        
        $manager->persist($testing);
        $this->addReference('status-testing', $testing);

        // Create Resolved status
        $resolved = new IssueStatuse();
        $resolved->setName('Resolved');
        $resolved->setIsClosed(1);
        $resolved->setPosition(5);
        $resolved->setDefaultDoneRatio(100);
        
        $manager->persist($resolved);
        $this->addReference('status-resolved', $resolved);

        // Create Closed status
        $closed = new IssueStatuse();
        $closed->setName('Closed');
        $closed->setIsClosed(1);
        $closed->setPosition(6);
        $closed->setDefaultDoneRatio(100);
        
        $manager->persist($closed);
        $this->addReference('status-closed', $closed);

        // Create Rejected status
        $rejected = new IssueStatuse();
        $rejected->setName('Rejected');
        $rejected->setIsClosed(1);
        $rejected->setPosition(7);
        $rejected->setDefaultDoneRatio(0);
        
        $manager->persist($rejected);
        $this->addReference('status-rejected', $rejected);

        // Create On Hold status
        $onHold = new IssueStatuse();
        $onHold->setName('On Hold');
        $onHold->setIsClosed(0);
        $onHold->setPosition(8);
        $onHold->setDefaultDoneRatio(null);
        
        $manager->persist($onHold);
        $this->addReference('status-on-hold', $onHold);

        // Create Needs Information status
        $needsInfo = new IssueStatuse();
        $needsInfo->setName('Needs Information');
        $needsInfo->setIsClosed(0);
        $needsInfo->setPosition(9);
        $needsInfo->setDefaultDoneRatio(null);
        
        $manager->persist($needsInfo);
        $this->addReference('status-needs-info', $needsInfo);

        // Create Duplicate status
        $duplicate = new IssueStatuse();
        $duplicate->setName('Duplicate');
        $duplicate->setIsClosed(1);
        $duplicate->setPosition(10);
        $duplicate->setDefaultDoneRatio(0);
        
        $manager->persist($duplicate);
        $this->addReference('status-duplicate', $duplicate);

        $manager->flush();
    }
}
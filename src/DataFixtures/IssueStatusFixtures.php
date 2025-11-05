<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\IssueStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class IssueStatusFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Create New status
        $new = new IssueStatus();
        $new->setName('New');
        $new->setIsClosed(false);
        $new->setPosition(1);
        $new->setDefaultDoneRatio(null);

        $manager->persist($new);
        $this->addReference('status-new', $new);

        // Create In Progress status
        $inProgress = new IssueStatus();
        $inProgress->setName('In Progress');
        $inProgress->setIsClosed(false);
        $inProgress->setPosition(2);
        $inProgress->setDefaultDoneRatio(10);

        $manager->persist($inProgress);
        $this->addReference('status-in-progress', $inProgress);

        // Create Under Review status
        $underReview = new IssueStatus();
        $underReview->setName('Under Review');
        $underReview->setIsClosed(false);
        $underReview->setPosition(3);
        $underReview->setDefaultDoneRatio(70);

        $manager->persist($underReview);
        $this->addReference('status-under-review', $underReview);

        // Create Testing status
        $testing = new IssueStatus();
        $testing->setName('Testing');
        $testing->setIsClosed(false);
        $testing->setPosition(4);
        $testing->setDefaultDoneRatio(80);

        $manager->persist($testing);
        $this->addReference('status-testing', $testing);

        // Create Resolved status
        $resolved = new IssueStatus();
        $resolved->setName('Resolved');
        $resolved->setIsClosed(true);
        $resolved->setPosition(5);
        $resolved->setDefaultDoneRatio(100);

        $manager->persist($resolved);
        $this->addReference('status-resolved', $resolved);

        // Create Closed status
        $closed = new IssueStatus();
        $closed->setName('Closed');
        $closed->setIsClosed(true);
        $closed->setPosition(6);
        $closed->setDefaultDoneRatio(100);

        $manager->persist($closed);
        $this->addReference('status-closed', $closed);

        // Create Rejected status
        $rejected = new IssueStatus();
        $rejected->setName('Rejected');
        $rejected->setIsClosed(true);
        $rejected->setPosition(7);
        $rejected->setDefaultDoneRatio(0);

        $manager->persist($rejected);
        $this->addReference('status-rejected', $rejected);

        // Create On Hold status
        $onHold = new IssueStatus();
        $onHold->setName('On Hold');
        $onHold->setIsClosed(false);
        $onHold->setPosition(8);
        $onHold->setDefaultDoneRatio(null);

        $manager->persist($onHold);
        $this->addReference('status-on-hold', $onHold);

        // Create Needs Information status
        $needsInfo = new IssueStatus();
        $needsInfo->setName('Needs Information');
        $needsInfo->setIsClosed(false);
        $needsInfo->setPosition(9);
        $needsInfo->setDefaultDoneRatio(null);

        $manager->persist($needsInfo);
        $this->addReference('status-needs-info', $needsInfo);

        // Create Duplicate status
        $duplicate = new IssueStatus();
        $duplicate->setName('Duplicate');
        $duplicate->setIsClosed(true);
        $duplicate->setPosition(10);
        $duplicate->setDefaultDoneRatio(0);

        $manager->persist($duplicate);
        $this->addReference('status-duplicate', $duplicate);

        $manager->flush();
    }
}

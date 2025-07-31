<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Tracker;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class TrackerFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Create Bug tracker
        $bug = new Tracker();
        $bug->setName('Bug');
        $bug->setDescription('Software defects and issues that need to be fixed');
        $bug->setDefaultStatusId(null); // Will be set after IssueStatus fixtures
        $bug->setIsInRoadmap(0);
        $bug->setPosition(1);
        
        $manager->persist($bug);
        $this->addReference('tracker-bug', $bug);

        // Create Feature tracker
        $feature = new Tracker();
        $feature->setName('Feature');
        $feature->setDescription('New features and enhancements');
        $feature->setDefaultStatusId(null);
        $feature->setIsInRoadmap(1);
        $feature->setPosition(2);
        
        $manager->persist($feature);
        $this->addReference('tracker-feature', $feature);

        // Create Task tracker
        $task = new Tracker();
        $task->setName('Task');
        $task->setDescription('General tasks and work items');
        $task->setDefaultStatusId(null);
        $task->setIsInRoadmap(1);
        $task->setPosition(3);
        
        $manager->persist($task);
        $this->addReference('tracker-task', $task);

        // Create Support tracker
        $support = new Tracker();
        $support->setName('Support');
        $support->setDescription('Customer support requests and inquiries');
        $support->setDefaultStatusId(null);
        $support->setIsInRoadmap(0);
        $support->setPosition(4);
        
        $manager->persist($support);
        $this->addReference('tracker-support', $support);

        // Create Documentation tracker
        $documentation = new Tracker();
        $documentation->setName('Documentation');
        $documentation->setDescription('Documentation tasks and improvements');
        $documentation->setDefaultStatusId(null);
        $documentation->setIsInRoadmap(1);
        $documentation->setPosition(5);
        
        $manager->persist($documentation);
        $this->addReference('tracker-documentation', $documentation);

        // Create Research tracker
        $research = new Tracker();
        $research->setName('Research');
        $research->setDescription('Research and investigation tasks');
        $research->setDefaultStatusId(null);
        $research->setIsInRoadmap(0);
        $research->setPosition(6);
        
        $manager->persist($research);
        $this->addReference('tracker-research', $research);

        // Create Testing tracker
        $testing = new Tracker();
        $testing->setName('Testing');
        $testing->setDescription('Testing and quality assurance tasks');
        $testing->setDefaultStatusId(null);
        $testing->setIsInRoadmap(1);
        $testing->setPosition(7);
        
        $manager->persist($testing);
        $this->addReference('tracker-testing', $testing);

        // Create Deployment tracker
        $deployment = new Tracker();
        $deployment->setName('Deployment');
        $deployment->setDescription('Deployment and release management tasks');
        $deployment->setDefaultStatusId(null);
        $deployment->setIsInRoadmap(1);
        $deployment->setPosition(8);
        
        $manager->persist($deployment);
        $this->addReference('tracker-deployment', $deployment);

        $manager->flush();
    }
}
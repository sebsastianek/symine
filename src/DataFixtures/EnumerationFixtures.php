<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Enumeration;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class EnumerationFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Create Issue Priorities
        $low = new Enumeration();
        $low->setName('Low');
        $low->setPosition(1);
        $low->setIsDefault(0);
        $low->setType('IssuePriority');
        $low->setActive(1);
        $low->setProjectId(null); // Global enumeration
        
        $manager->persist($low);
        $this->addReference('priority-low', $low);

        $normal = new Enumeration();
        $normal->setName('Normal');
        $normal->setPosition(2);
        $normal->setIsDefault(1); // Default priority
        $normal->setType('IssuePriority');
        $normal->setActive(1);
        $normal->setProjectId(null);
        
        $manager->persist($normal);
        $this->addReference('priority-normal', $normal);

        $high = new Enumeration();
        $high->setName('High');
        $high->setPosition(3);
        $high->setIsDefault(0);
        $high->setType('IssuePriority');
        $high->setActive(1);
        $high->setProjectId(null);
        
        $manager->persist($high);
        $this->addReference('priority-high', $high);

        $urgent = new Enumeration();
        $urgent->setName('Urgent');
        $urgent->setPosition(4);
        $urgent->setIsDefault(0);
        $urgent->setType('IssuePriority');
        $urgent->setActive(1);
        $urgent->setProjectId(null);
        
        $manager->persist($urgent);
        $this->addReference('priority-urgent', $urgent);

        $immediate = new Enumeration();
        $immediate->setName('Immediate');
        $immediate->setPosition(5);
        $immediate->setIsDefault(0);
        $immediate->setType('IssuePriority');
        $immediate->setActive(1);
        $immediate->setProjectId(null);
        
        $manager->persist($immediate);
        $this->addReference('priority-immediate', $immediate);

        // Create Time Entry Activities
        $development = new Enumeration();
        $development->setName('Development');
        $development->setPosition(1);
        $development->setIsDefault(1); // Default activity
        $development->setType('TimeEntryActivity');
        $development->setActive(1);
        $development->setProjectId(null);
        
        $manager->persist($development);
        $this->addReference('activity-development', $development);

        $design = new Enumeration();
        $design->setName('Design');
        $design->setPosition(2);
        $design->setIsDefault(0);
        $design->setType('TimeEntryActivity');
        $design->setActive(1);
        $design->setProjectId(null);
        
        $manager->persist($design);
        $this->addReference('activity-design', $design);

        $testing = new Enumeration();
        $testing->setName('Testing');
        $testing->setPosition(3);
        $testing->setIsDefault(0);
        $testing->setType('TimeEntryActivity');
        $testing->setActive(1);
        $testing->setProjectId(null);
        
        $manager->persist($testing);
        $this->addReference('activity-testing', $testing);

        $documentation = new Enumeration();
        $documentation->setName('Documentation');
        $documentation->setPosition(4);
        $documentation->setIsDefault(0);
        $documentation->setType('TimeEntryActivity');
        $documentation->setActive(1);
        $documentation->setProjectId(null);
        
        $manager->persist($documentation);
        $this->addReference('activity-documentation', $documentation);

        $meeting = new Enumeration();
        $meeting->setName('Meeting');
        $meeting->setPosition(5);
        $meeting->setIsDefault(0);
        $meeting->setType('TimeEntryActivity');
        $meeting->setActive(1);
        $meeting->setProjectId(null);
        
        $manager->persist($meeting);
        $this->addReference('activity-meeting', $meeting);

        $research = new Enumeration();
        $research->setName('Research');
        $research->setPosition(6);
        $research->setIsDefault(0);
        $research->setType('TimeEntryActivity');
        $research->setActive(1);
        $research->setProjectId(null);
        
        $manager->persist($research);
        $this->addReference('activity-research', $research);

        $bugfixing = new Enumeration();
        $bugfixing->setName('Bug Fixing');
        $bugfixing->setPosition(7);
        $bugfixing->setIsDefault(0);
        $bugfixing->setType('TimeEntryActivity');
        $bugfixing->setActive(1);
        $bugfixing->setProjectId(null);
        
        $manager->persist($bugfixing);
        $this->addReference('activity-bugfixing', $bugfixing);

        $deployment = new Enumeration();
        $deployment->setName('Deployment');
        $deployment->setPosition(8);
        $deployment->setIsDefault(0);
        $deployment->setType('TimeEntryActivity');
        $deployment->setActive(1);
        $deployment->setProjectId(null);
        
        $manager->persist($deployment);
        $this->addReference('activity-deployment', $deployment);

        $support = new Enumeration();
        $support->setName('Support');
        $support->setPosition(9);
        $support->setIsDefault(0);
        $support->setType('TimeEntryActivity');
        $support->setActive(1);
        $support->setProjectId(null);
        
        $manager->persist($support);
        $this->addReference('activity-support', $support);

        $planning = new Enumeration();
        $planning->setName('Planning');
        $planning->setPosition(10);
        $planning->setIsDefault(0);
        $planning->setType('TimeEntryActivity');
        $planning->setActive(1);
        $planning->setProjectId(null);
        
        $manager->persist($planning);
        $this->addReference('activity-planning', $planning);

        // Create Document Categories
        $userGuide = new Enumeration();
        $userGuide->setName('User Guide');
        $userGuide->setPosition(1);
        $userGuide->setIsDefault(1);
        $userGuide->setType('DocumentCategory');
        $userGuide->setActive(1);
        $userGuide->setProjectId(null);
        
        $manager->persist($userGuide);
        $this->addReference('doc-category-user-guide', $userGuide);

        $technical = new Enumeration();
        $technical->setName('Technical Documentation');
        $technical->setPosition(2);
        $technical->setIsDefault(0);
        $technical->setType('DocumentCategory');
        $technical->setActive(1);
        $technical->setProjectId(null);
        
        $manager->persist($technical);
        $this->addReference('doc-category-technical', $technical);

        $specifications = new Enumeration();
        $specifications->setName('Specifications');
        $specifications->setPosition(3);
        $specifications->setIsDefault(0);
        $specifications->setType('DocumentCategory');
        $specifications->setActive(1);
        $specifications->setProjectId(null);
        
        $manager->persist($specifications);
        $this->addReference('doc-category-specifications', $specifications);

        $manager->flush();
    }
}
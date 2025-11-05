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
        $low->setIsDefault(false);
        $low->setType('IssuePriority');
        $low->setActive(true);
        $low->setProjectId(null); // Global enumeration

        $manager->persist($low);
        $this->addReference('priority-low', $low);

        $normal = new Enumeration();
        $normal->setName('Normal');
        $normal->setPosition(2);
        $normal->setIsDefault(true); // Default priority
        $normal->setType('IssuePriority');
        $normal->setActive(true);
        $normal->setProjectId(null);

        $manager->persist($normal);
        $this->addReference('priority-normal', $normal);

        $high = new Enumeration();
        $high->setName('High');
        $high->setPosition(3);
        $high->setIsDefault(false);
        $high->setType('IssuePriority');
        $high->setActive(true);
        $high->setProjectId(null);

        $manager->persist($high);
        $this->addReference('priority-high', $high);

        $urgent = new Enumeration();
        $urgent->setName('Urgent');
        $urgent->setPosition(4);
        $urgent->setIsDefault(false);
        $urgent->setType('IssuePriority');
        $urgent->setActive(true);
        $urgent->setProjectId(null);

        $manager->persist($urgent);
        $this->addReference('priority-urgent', $urgent);

        $immediate = new Enumeration();
        $immediate->setName('Immediate');
        $immediate->setPosition(5);
        $immediate->setIsDefault(false);
        $immediate->setType('IssuePriority');
        $immediate->setActive(true);
        $immediate->setProjectId(null);

        $manager->persist($immediate);
        $this->addReference('priority-immediate', $immediate);

        // Create Time Entry Activities
        $development = new Enumeration();
        $development->setName('Development');
        $development->setPosition(1);
        $development->setIsDefault(true); // Default activity
        $development->setType('TimeEntryActivity');
        $development->setActive(true);
        $development->setProjectId(null);

        $manager->persist($development);
        $this->addReference('activity-development', $development);

        $design = new Enumeration();
        $design->setName('Design');
        $design->setPosition(2);
        $design->setIsDefault(false);
        $design->setType('TimeEntryActivity');
        $design->setActive(true);
        $design->setProjectId(null);

        $manager->persist($design);
        $this->addReference('activity-design', $design);

        $testing = new Enumeration();
        $testing->setName('Testing');
        $testing->setPosition(3);
        $testing->setIsDefault(false);
        $testing->setType('TimeEntryActivity');
        $testing->setActive(true);
        $testing->setProjectId(null);

        $manager->persist($testing);
        $this->addReference('activity-testing', $testing);

        $documentation = new Enumeration();
        $documentation->setName('Documentation');
        $documentation->setPosition(4);
        $documentation->setIsDefault(false);
        $documentation->setType('TimeEntryActivity');
        $documentation->setActive(true);
        $documentation->setProjectId(null);

        $manager->persist($documentation);
        $this->addReference('activity-documentation', $documentation);

        $meeting = new Enumeration();
        $meeting->setName('Meeting');
        $meeting->setPosition(5);
        $meeting->setIsDefault(false);
        $meeting->setType('TimeEntryActivity');
        $meeting->setActive(true);
        $meeting->setProjectId(null);

        $manager->persist($meeting);
        $this->addReference('activity-meeting', $meeting);

        $research = new Enumeration();
        $research->setName('Research');
        $research->setPosition(6);
        $research->setIsDefault(false);
        $research->setType('TimeEntryActivity');
        $research->setActive(true);
        $research->setProjectId(null);

        $manager->persist($research);
        $this->addReference('activity-research', $research);

        $bugfixing = new Enumeration();
        $bugfixing->setName('Bug Fixing');
        $bugfixing->setPosition(7);
        $bugfixing->setIsDefault(false);
        $bugfixing->setType('TimeEntryActivity');
        $bugfixing->setActive(true);
        $bugfixing->setProjectId(null);

        $manager->persist($bugfixing);
        $this->addReference('activity-bugfixing', $bugfixing);

        $deployment = new Enumeration();
        $deployment->setName('Deployment');
        $deployment->setPosition(8);
        $deployment->setIsDefault(false);
        $deployment->setType('TimeEntryActivity');
        $deployment->setActive(true);
        $deployment->setProjectId(null);

        $manager->persist($deployment);
        $this->addReference('activity-deployment', $deployment);

        $support = new Enumeration();
        $support->setName('Support');
        $support->setPosition(9);
        $support->setIsDefault(false);
        $support->setType('TimeEntryActivity');
        $support->setActive(true);
        $support->setProjectId(null);

        $manager->persist($support);
        $this->addReference('activity-support', $support);

        $planning = new Enumeration();
        $planning->setName('Planning');
        $planning->setPosition(10);
        $planning->setIsDefault(false);
        $planning->setType('TimeEntryActivity');
        $planning->setActive(true);
        $planning->setProjectId(null);

        $manager->persist($planning);
        $this->addReference('activity-planning', $planning);

        // Create Document Categories
        $userGuide = new Enumeration();
        $userGuide->setName('User Guide');
        $userGuide->setPosition(1);
        $userGuide->setIsDefault(true);
        $userGuide->setType('DocumentCategory');
        $userGuide->setActive(true);
        $userGuide->setProjectId(null);

        $manager->persist($userGuide);
        $this->addReference('doc-category-user-guide', $userGuide);

        $technical = new Enumeration();
        $technical->setName('Technical Documentation');
        $technical->setPosition(2);
        $technical->setIsDefault(false);
        $technical->setType('DocumentCategory');
        $technical->setActive(true);
        $technical->setProjectId(null);

        $manager->persist($technical);
        $this->addReference('doc-category-technical', $technical);

        $specifications = new Enumeration();
        $specifications->setName('Specifications');
        $specifications->setPosition(3);
        $specifications->setIsDefault(false);
        $specifications->setType('DocumentCategory');
        $specifications->setActive(true);
        $specifications->setProjectId(null);

        $manager->persist($specifications);
        $this->addReference('doc-category-specifications', $specifications);

        $manager->flush();
    }
}

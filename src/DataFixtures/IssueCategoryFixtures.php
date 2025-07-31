<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\IssueCategory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class IssueCategoryFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // E-Commerce Platform Categories
        $frontend = new IssueCategory();
        $frontend->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $frontend->setName('Frontend');
        $frontend->setAssignedTo($this->getReference('user-sgarcia', \App\Entity\User::class));
        
        $manager->persist($frontend);
        $this->addReference('category-frontend', $frontend);

        $backend = new IssueCategory();
        $backend->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $backend->setName('Backend API');
        $backend->setAssignedTo($this->getReference('user-mjohnson', \App\Entity\User::class));
        
        $manager->persist($backend);
        $this->addReference('category-backend', $backend);

        $database = new IssueCategory();
        $database->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $database->setName('Database');
        $database->setAssignedTo($this->getReference('user-mjohnson', \App\Entity\User::class));
        
        $manager->persist($database);
        $this->addReference('category-database', $database);

        $security = new IssueCategory();
        $security->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $security->setName('Security');
        $security->setAssignedTo($this->getReference('user-jsmith', \App\Entity\User::class));
        
        $manager->persist($security);
        $this->addReference('category-security', $security);

        $testing = new IssueCategory();
        $testing->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $testing->setName('Testing');
        $testing->setAssignedTo($this->getReference('user-dbrown', \App\Entity\User::class));
        
        $manager->persist($testing);
        $this->addReference('category-testing', $testing);

        // Frontend Project Categories
        $ui = new IssueCategory();
        $ui->setProject($this->getReference('project-frontend', \App\Entity\Project::class));
        $ui->setName('User Interface');
        $ui->setAssignedTo($this->getReference('user-sgarcia', \App\Entity\User::class));
        
        $manager->persist($ui);
        $this->addReference('category-ui', $ui);

        $responsive = new IssueCategory();
        $responsive->setProject($this->getReference('project-frontend', \App\Entity\Project::class));
        $responsive->setName('Responsive Design');
        $responsive->setAssignedTo($this->getReference('user-sgarcia', \App\Entity\User::class));
        
        $manager->persist($responsive);
        $this->addReference('category-responsive', $responsive);

        // Backend Project Categories
        $api = new IssueCategory();
        $api->setProject($this->getReference('project-backend', \App\Entity\Project::class));
        $api->setName('API Development');
        $api->setAssignedTo($this->getReference('user-mjohnson', \App\Entity\User::class));
        
        $manager->persist($api);
        $this->addReference('category-api', $api);

        $authentication = new IssueCategory();
        $authentication->setProject($this->getReference('project-backend', \App\Entity\Project::class));
        $authentication->setName('Authentication');
        $authentication->setAssignedTo($this->getReference('user-mjohnson', \App\Entity\User::class));
        
        $manager->persist($authentication);
        $this->addReference('category-authentication', $authentication);

        // Mobile Project Categories
        $ios = new IssueCategory();
        $ios->setProject($this->getReference('project-mobile', \App\Entity\Project::class));
        $ios->setName('iOS Development');
        $ios->setAssignedTo($this->getReference('user-mjohnson', \App\Entity\User::class));
        
        $manager->persist($ios);
        $this->addReference('category-ios', $ios);

        $android = new IssueCategory();
        $android->setProject($this->getReference('project-mobile', \App\Entity\Project::class));
        $android->setName('Android Development');
        $android->setAssignedTo($this->getReference('user-mjohnson', \App\Entity\User::class));
        
        $manager->persist($android);
        $this->addReference('category-android', $android);

        // CRM Project Categories
        $contacts = new IssueCategory();
        $contacts->setProject($this->getReference('project-crm', \App\Entity\Project::class));
        $contacts->setName('Contact Management');
        $contacts->setAssignedTo($this->getReference('user-sgarcia', \App\Entity\User::class));
        
        $manager->persist($contacts);
        $this->addReference('category-contacts', $contacts);

        $reports = new IssueCategory();
        $reports->setProject($this->getReference('project-crm', \App\Entity\Project::class));
        $reports->setName('Reporting');
        $reports->setAssignedTo($this->getReference('user-jsmith', \App\Entity\User::class));
        
        $manager->persist($reports);
        $this->addReference('category-reports', $reports);

        // Documentation Project Categories
        $userDocs = new IssueCategory();
        $userDocs->setProject($this->getReference('project-docs', \App\Entity\Project::class));
        $userDocs->setName('User Documentation');
        $userDocs->setAssignedTo($this->getReference('user-mjohnson', \App\Entity\User::class));
        
        $manager->persist($userDocs);
        $this->addReference('category-user-docs', $userDocs);

        $techDocs = new IssueCategory();
        $techDocs->setProject($this->getReference('project-docs', \App\Entity\Project::class));
        $techDocs->setName('Technical Documentation');
        $techDocs->setAssignedTo($this->getReference('user-mjohnson', \App\Entity\User::class));
        
        $manager->persist($techDocs);
        $this->addReference('category-tech-docs', $techDocs);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            ProjectFixtures::class,
        ];
    }
}
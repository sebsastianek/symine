<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\News;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class NewsFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // News 1: Project launch announcement
        $news1 = new News();
        $news1->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $news1->setTitle('E-Commerce Platform Development Started');
        $news1->setSummary('We are excited to announce the start of our new e-commerce platform development project.');
        $news1->setDescription('Our team has officially begun development of a comprehensive e-commerce platform that will feature modern technology stack, scalable architecture, and user-friendly interface. The project aims to deliver a complete solution for online businesses.');
        $news1->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $news1->setCommentsCount(0);
        $news1->setCreatedOn(new \DateTime('2024-01-02 11:00:00'));
        
        $manager->persist($news1);
        $this->addReference('news-ecommerce-launch', $news1);

        // News 2: Release announcement
        $news2 = new News();
        $news2->setProject($this->getReference('project-backend', \App\Entity\Project::class));
        $news2->setTitle('Backend API v1.0 Released');
        $news2->setSummary('The first version of our backend API is now available with core functionality.');
        $news2->setDescription('We are pleased to announce the release of Backend API v1.0. This version includes user authentication, basic CRUD operations, and security features. The API is now ready for frontend integration and testing.');
        $news2->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $news2->setCommentsCount(3);
        $news2->setCreatedOn(new \DateTime('2024-03-31 16:00:00'));
        
        $manager->persist($news2);
        $this->addReference('news-backend-release', $news2);

        // News 3: Team addition
        $news3 = new News();
        $news3->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $news3->setTitle('Welcome New Team Members');
        $news3->setSummary('We are happy to welcome Sarah Garcia and David Brown to our development team.');
        $news3->setDescription('Our team continues to grow! We are excited to welcome Sarah Garcia as Frontend Developer and David Brown as QA Tester. Their expertise will help us deliver high-quality software and meet our project milestones.');
        $news3->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $news3->setCommentsCount(5);
        $news3->setCreatedOn(new \DateTime('2024-02-01 14:30:00'));
        
        $manager->persist($news3);
        $this->addReference('news-team-addition', $news3);

        // News 4: Sprint completion
        $news4 = new News();
        $news4->setProject($this->getReference('project-frontend', \App\Entity\Project::class));
        $news4->setTitle('Sprint 2 Completed Successfully');
        $news4->setSummary('Frontend team has completed Sprint 2 with all planned features delivered.');
        $news4->setDescription('The frontend development team has successfully completed Sprint 2, delivering responsive layout components, user authentication interface, and improved navigation. All features have been tested and are ready for integration.');
        $news4->setAuthor($this->getReference('user-sgarcia', \App\Entity\User::class));
        $news4->setCommentsCount(2);
        $news4->setCreatedOn(new \DateTime('2024-02-20 17:45:00'));
        
        $manager->persist($news4);
        $this->addReference('news-sprint-completion', $news4);

        // News 5: Documentation update
        $news5 = new News();
        $news5->setProject($this->getReference('project-docs', \App\Entity\Project::class));
        $news5->setTitle('Documentation Portal Goes Live');
        $news5->setSummary('Our new documentation portal is now available with comprehensive guides and API documentation.');
        $news5->setDescription('The documentation portal is now live and accessible to all team members. It includes user guides, technical specifications, API documentation, and setup instructions. Regular updates will be made as the project progresses.');
        $news5->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $news5->setCommentsCount(1);
        $news5->setCreatedOn(new \DateTime('2024-02-25 10:00:00'));
        
        $manager->persist($news5);
        $this->addReference('news-docs-portal', $news5);

        // News 6: Security update
        $news6 = new News();
        $news6->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $news6->setTitle('Security Enhancements Implemented');
        $news6->setSummary('Important security updates have been implemented across all project components.');
        $news6->setDescription('Following our security audit, we have implemented several important security enhancements including improved authentication mechanisms, data encryption, and access controls. All systems have been updated and tested.');
        $news6->setAuthor($this->getReference('user-admin', \App\Entity\User::class));
        $news6->setCommentsCount(0);
        $news6->setCreatedOn(new \DateTime('2024-03-05 09:15:00'));
        
        $manager->persist($news6);
        $this->addReference('news-security-update', $news6);

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
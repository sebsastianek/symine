<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Repository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class RepositoryFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Repository 1: Frontend repository
        $repo1 = new Repository();
        $repo1->setProject($this->getReference('project-frontend', \App\Entity\Project::class));
        $repo1->setUrl('https://github.com/company/ecommerce-frontend.git');
        $repo1->setLogin('');
        $repo1->setPassword('');
        $repo1->setRootUrl('');
        $repo1->setType('Git');
        $repo1->setPathEncoding('UTF-8');
        $repo1->setLogEncoding('UTF-8');
        $repo1->setExtraInfo('Frontend React application repository');
        $repo1->setIdentifier('frontend-repo');
        $repo1->setIsDefault(true);
        $repo1->setCreatedOn(new \DateTime('2024-01-05 11:30:00'));
        
        $manager->persist($repo1);
        $this->addReference('repo-frontend', $repo1);

        // Repository 2: Backend repository
        $repo2 = new Repository();
        $repo2->setProject($this->getReference('project-backend', \App\Entity\Project::class));
        $repo2->setUrl('https://github.com/company/ecommerce-backend.git');
        $repo2->setLogin('');
        $repo2->setPassword('');
        $repo2->setRootUrl('');
        $repo2->setType('Git');
        $repo2->setPathEncoding('UTF-8');
        $repo2->setLogEncoding('UTF-8');
        $repo2->setExtraInfo('Backend API repository');
        $repo2->setIdentifier('backend-repo');
        $repo2->setIsDefault(true);
        $repo2->setCreatedOn(new \DateTime('2024-01-05 12:00:00'));
        
        $manager->persist($repo2);
        $this->addReference('repo-backend', $repo2);

        // Repository 3: Mobile repository
        $repo3 = new Repository();
        $repo3->setProject($this->getReference('project-mobile', \App\Entity\Project::class));
        $repo3->setUrl('https://github.com/company/ecommerce-mobile.git');
        $repo3->setLogin('');
        $repo3->setPassword('');
        $repo3->setRootUrl('');
        $repo3->setType('Git');
        $repo3->setPathEncoding('UTF-8');
        $repo3->setLogEncoding('UTF-8');
        $repo3->setExtraInfo('React Native mobile application repository');
        $repo3->setIdentifier('mobile-repo');
        $repo3->setIsDefault(true);
        $repo3->setCreatedOn(new \DateTime('2024-02-01 14:30:00'));
        
        $manager->persist($repo3);
        $this->addReference('repo-mobile', $repo3);

        // Repository 4: CRM repository
        $repo4 = new Repository();
        $repo4->setProject($this->getReference('project-crm', \App\Entity\Project::class));
        $repo4->setUrl('https://github.com/company/crm-system.git');
        $repo4->setLogin('');
        $repo4->setPassword('');
        $repo4->setRootUrl('');
        $repo4->setType('Git');
        $repo4->setPathEncoding('UTF-8');
        $repo4->setLogEncoding('UTF-8');
        $repo4->setExtraInfo('Customer relationship management system repository');
        $repo4->setIdentifier('crm-repo');
        $repo4->setIsDefault(true);
        $repo4->setCreatedOn(new \DateTime('2024-01-15 14:00:00'));
        
        $manager->persist($repo4);
        $this->addReference('repo-crm', $repo4);

        // Repository 5: Documentation repository
        $repo5 = new Repository();
        $repo5->setProject($this->getReference('project-docs', \App\Entity\Project::class));
        $repo5->setUrl('https://github.com/company/documentation.git');
        $repo5->setLogin('');
        $repo5->setPassword('');
        $repo5->setRootUrl('');
        $repo5->setType('Git');
        $repo5->setPathEncoding('UTF-8');
        $repo5->setLogEncoding('UTF-8');
        $repo5->setExtraInfo('Project documentation and guides repository');
        $repo5->setIdentifier('docs-repo');
        $repo5->setIsDefault(true);
        $repo5->setCreatedOn(new \DateTime('2024-02-10 17:15:00'));
        
        $manager->persist($repo5);
        $this->addReference('repo-docs', $repo5);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ProjectFixtures::class,
        ];
    }
}
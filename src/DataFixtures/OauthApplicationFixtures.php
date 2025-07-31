<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\OauthApplication;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class OauthApplicationFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Mobile application
        $app1 = new OauthApplication();
        $app1->setName('Redmine Mobile App');
        $app1->setUid('redmine_mobile_' . uniqid());
        $app1->setSecret(hash('sha256', 'mobile_secret_' . time()));
        $app1->setRedirectUri('com.company.redmine://oauth/callback');
        $app1->setScopes('read write');
        $app1->setConfidential(1);
        $app1->setCreatedAt(new \DateTime('2024-01-10 10:00:00'));
        $app1->setUpdatedAt(new \DateTime('2024-01-10 10:00:00'));
        
        $manager->persist($app1);
        $this->addReference('oauth-app-mobile', $app1);

        // Web dashboard application
        $app2 = new OauthApplication();
        $app2->setName('Project Dashboard');
        $app2->setUid('dashboard_app_' . uniqid());
        $app2->setSecret(hash('sha256', 'dashboard_secret_' . time()));
        $app2->setRedirectUri('https://dashboard.company.com/auth/callback');
        $app2->setScopes('read');
        $app2->setConfidential(1);
        $app2->setCreatedAt(new \DateTime('2024-01-15 14:30:00'));
        $app2->setUpdatedAt(new \DateTime('2024-01-15 14:30:00'));
        
        $manager->persist($app2);
        $this->addReference('oauth-app-dashboard', $app2);

        // API integration application
        $app3 = new OauthApplication();
        $app3->setName('Third-party Integration');
        $app3->setUid('integration_' . uniqid());
        $app3->setSecret(hash('sha256', 'integration_secret_' . time()));
        $app3->setRedirectUri('https://external-service.com/redmine/callback');
        $app3->setScopes('read write issues time_entries');
        $app3->setConfidential(1);
        $app3->setCreatedAt(new \DateTime('2024-01-20 09:15:00'));
        $app3->setUpdatedAt(new \DateTime('2024-01-20 09:15:00'));
        
        $manager->persist($app3);
        $this->addReference('oauth-app-integration', $app3);

        // Public application (SPA)
        $app4 = new OauthApplication();
        $app4->setName('Public Web Client');
        $app4->setUid('public_web_' . uniqid());
        $app4->setSecret(''); // Public clients don't have secrets
        $app4->setRedirectUri('https://spa.company.com/auth
https://spa.company.com/silent-refresh');
        $app4->setScopes('read profile');
        $app4->setConfidential(0); // Public application
        $app4->setCreatedAt(new \DateTime('2024-01-25 11:45:00'));
        $app4->setUpdatedAt(new \DateTime('2024-01-25 11:45:00'));
        
        $manager->persist($app4);
        $this->addReference('oauth-app-public', $app4);

        // CI/CD pipeline application
        $app5 = new OauthApplication();
        $app5->setName('CI/CD Pipeline');
        $app5->setUid('cicd_pipeline_' . uniqid());
        $app5->setSecret(hash('sha256', 'cicd_secret_' . time()));
        $app5->setRedirectUri('https://jenkins.company.com/oauth/callback
https://gitlab.company.com/oauth/callback');
        $app5->setScopes('read write issues changesets');
        $app5->setConfidential(1);
        $app5->setCreatedAt(new \DateTime('2024-01-30 16:20:00'));
        $app5->setUpdatedAt(new \DateTime('2024-01-30 16:20:00'));
        
        $manager->persist($app5);
        $this->addReference('oauth-app-cicd', $app5);

        // Time tracking application
        $app6 = new OauthApplication();
        $app6->setName('Time Tracker Pro');
        $app6->setUid('time_tracker_' . uniqid());
        $app6->setSecret(hash('sha256', 'timetracker_secret_' . time()));
        $app6->setRedirectUri('https://timetracker.company.com/redmine/auth');
        $app6->setScopes('read write time_entries');
        $app6->setConfidential(1);
        $app6->setCreatedAt(new \DateTime('2024-02-02 13:10:00'));
        $app6->setUpdatedAt(new \DateTime('2024-02-02 13:10:00'));
        
        $manager->persist($app6);
        $this->addReference('oauth-app-timetracker', $app6);

        $manager->flush();
    }
}
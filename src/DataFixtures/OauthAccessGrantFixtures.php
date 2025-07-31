<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\OauthAccessGrant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class OauthAccessGrantFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Active authorization code for mobile app
        $grant1 = new OauthAccessGrant();
        $grant1->setToken(bin2hex(random_bytes(32))); // Authorization code
        $grant1->setExpiresIn(600); // 10 minutes
        $grant1->setRedirectUri('com.company.redmine://oauth/callback');
        $grant1->setCreatedAt(new \DateTime('2024-02-05 14:25:00'));
        $grant1->setRevokedAt(null); // Still active
        $grant1->setScopes('read write');
        $grant1->setCodeChallenge(null); // PKCE not used for this confidential client
        $grant1->setCodeChallengeMethod(null);
        $grant1->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $grant1->setOauthApplication($this->getReference('oauth-app-mobile', \App\Entity\OauthApplication::class));
        
        $manager->persist($grant1);
        $this->addReference('oauth-grant-mobile-active', $grant1);

        // Used authorization code (should be expired/revoked after token exchange)
        $grant2 = new OauthAccessGrant();
        $grant2->setToken(bin2hex(random_bytes(32)));
        $grant2->setExpiresIn(600);
        $grant2->setRedirectUri('https://dashboard.company.com/auth/callback');
        $grant2->setCreatedAt(new \DateTime('2024-02-05 09:10:00'));
        $grant2->setRevokedAt(new \DateTime('2024-02-05 09:15:00')); // Used for token exchange
        $grant2->setScopes('read');
        $grant2->setCodeChallenge(null);
        $grant2->setCodeChallengeMethod(null);
        $grant2->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $grant2->setOauthApplication($this->getReference('oauth-app-dashboard', \App\Entity\OauthApplication::class));
        
        $manager->persist($grant2);
        $this->addReference('oauth-grant-dashboard-used', $grant2);

        // PKCE authorization code for public client
        $grant3 = new OauthAccessGrant();
        $grant3->setToken(bin2hex(random_bytes(32)));
        $grant3->setExpiresIn(600);
        $grant3->setRedirectUri('https://spa.company.com/auth');
        $grant3->setCreatedAt(new \DateTime('2024-02-05 11:40:00'));
        $grant3->setRevokedAt(null);
        $grant3->setScopes('read profile');
        $grant3->setCodeChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'); // PKCE code challenge
        $grant3->setCodeChallengeMethod('S256'); // SHA256
        $grant3->setUser($this->getReference('user-alee', \App\Entity\User::class));
        $grant3->setOauthApplication($this->getReference('oauth-app-public', \App\Entity\OauthApplication::class));
        
        $manager->persist($grant3);
        $this->addReference('oauth-grant-public-pkce', $grant3);

        // Expired authorization code
        $grant4 = new OauthAccessGrant();
        $grant4->setToken(bin2hex(random_bytes(32)));
        $grant4->setExpiresIn(600);
        $grant4->setRedirectUri('https://external-service.com/redmine/callback');
        $grant4->setCreatedAt(new \DateTime('2024-02-04 10:00:00')); // Created yesterday, expired
        $grant4->setRevokedAt(null); // Not revoked but expired by time
        $grant4->setScopes('read write issues time_entries');
        $grant4->setCodeChallenge(null);
        $grant4->setCodeChallengeMethod(null);
        $grant4->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $grant4->setOauthApplication($this->getReference('oauth-app-integration', \App\Entity\OauthApplication::class));
        
        $manager->persist($grant4);
        $this->addReference('oauth-grant-expired', $grant4);

        // Authorization code for CI/CD pipeline
        $grant5 = new OauthAccessGrant();
        $grant5->setToken(bin2hex(random_bytes(32)));
        $grant5->setExpiresIn(600);
        $grant5->setRedirectUri('https://jenkins.company.com/oauth/callback');
        $grant5->setCreatedAt(new \DateTime('2024-02-04 07:55:00'));
        $grant5->setRevokedAt(new \DateTime('2024-02-04 08:00:00')); // Used for token exchange
        $grant5->setScopes('read write issues changesets');
        $grant5->setCodeChallenge(null);
        $grant5->setCodeChallengeMethod(null);
        $grant5->setUser($this->getReference('user-admin', \App\Entity\User::class));
        $grant5->setOauthApplication($this->getReference('oauth-app-cicd', \App\Entity\OauthApplication::class));
        
        $manager->persist($grant5);
        $this->addReference('oauth-grant-cicd-used', $grant5);

        // Time tracker authorization code with PKCE
        $grant6 = new OauthAccessGrant();
        $grant6->setToken(bin2hex(random_bytes(32)));
        $grant6->setExpiresIn(600);
        $grant6->setRedirectUri('https://timetracker.company.com/redmine/auth');
        $grant6->setCreatedAt(new \DateTime('2024-02-05 07:25:00'));
        $grant6->setRevokedAt(new \DateTime('2024-02-05 07:30:00')); // Used for token exchange
        $grant6->setScopes('read write time_entries');
        $grant6->setCodeChallenge('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'); // Different PKCE challenge
        $grant6->setCodeChallengeMethod('S256');
        $grant6->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        $grant6->setOauthApplication($this->getReference('oauth-app-timetracker', \App\Entity\OauthApplication::class));
        
        $manager->persist($grant6);
        $this->addReference('oauth-grant-timetracker-pkce', $grant6);

        // Fresh authorization code waiting to be exchanged
        $grant7 = new OauthAccessGrant();
        $grant7->setToken(bin2hex(random_bytes(32)));
        $grant7->setExpiresIn(600);
        $grant7->setRedirectUri('https://external-service.com/redmine/callback');
        $grant7->setCreatedAt(new \DateTime('2024-02-05 14:30:00')); // Very recent
        $grant7->setRevokedAt(null); // Not yet used
        $grant7->setScopes('read write issues');
        $grant7->setCodeChallenge(null);
        $grant7->setCodeChallengeMethod(null);
        $grant7->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $grant7->setOauthApplication($this->getReference('oauth-app-integration', \App\Entity\OauthApplication::class));
        
        $manager->persist($grant7);
        $this->addReference('oauth-grant-fresh', $grant7);

        // Authorization code for different redirect URI (multiple URIs in app)
        $grant8 = new OauthAccessGrant();
        $grant8->setToken(bin2hex(random_bytes(32)));
        $grant8->setExpiresIn(600);
        $grant8->setRedirectUri('https://gitlab.company.com/oauth/callback'); // Different URI than grant5
        $grant8->setCreatedAt(new \DateTime('2024-02-05 13:45:00'));
        $grant8->setRevokedAt(null);
        $grant8->setScopes('read write changesets');
        $grant8->setCodeChallenge(null);
        $grant8->setCodeChallengeMethod(null);
        $grant8->setUser($this->getReference('user-admin', \App\Entity\User::class));
        $grant8->setOauthApplication($this->getReference('oauth-app-cicd', \App\Entity\OauthApplication::class));
        
        $manager->persist($grant8);
        $this->addReference('oauth-grant-gitlab', $grant8);

        // Authorization code with limited scope
        $grant9 = new OauthAccessGrant();
        $grant9->setToken(bin2hex(random_bytes(32)));
        $grant9->setExpiresIn(600);
        $grant9->setRedirectUri('https://dashboard.company.com/auth/callback');
        $grant9->setCreatedAt(new \DateTime('2024-02-05 13:10:00'));
        $grant9->setRevokedAt(new \DateTime('2024-02-05 13:15:00')); // Used
        $grant9->setScopes('read'); // Limited scope compared to app capabilities
        $grant9->setCodeChallenge(null);
        $grant9->setCodeChallengeMethod(null);
        $grant9->setUser($this->getReference('user-alee', \App\Entity\User::class));
        $grant9->setOauthApplication($this->getReference('oauth-app-dashboard', \App\Entity\OauthApplication::class));
        
        $manager->persist($grant9);
        $this->addReference('oauth-grant-limited-scope', $grant9);

        // Authorization code for second mobile session
        $grant10 = new OauthAccessGrant();
        $grant10->setToken(bin2hex(random_bytes(32)));
        $grant10->setExpiresIn(600);
        $grant10->setRedirectUri('com.company.redmine://oauth/callback');
        $grant10->setCreatedAt(new \DateTime('2024-02-05 13:55:00'));
        $grant10->setRevokedAt(new \DateTime('2024-02-05 14:00:00')); // Used for second token
        $grant10->setScopes('read'); // Different scope than first mobile session
        $grant10->setCodeChallenge(null);
        $grant10->setCodeChallengeMethod(null);
        $grant10->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $grant10->setOauthApplication($this->getReference('oauth-app-mobile', \App\Entity\OauthApplication::class));
        
        $manager->persist($grant10);
        $this->addReference('oauth-grant-second-mobile', $grant10);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            OauthApplicationFixtures::class,
            UserFixtures::class,
        ];
    }
}
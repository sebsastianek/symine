<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\OauthAccessToken;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class OauthAccessTokenFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Active token for mobile app (John Smith)
        $token1 = new OauthAccessToken();
        $token1->setToken(hash('sha256', 'mobile_token_jsmith_' . time()));
        $token1->setRefreshToken(hash('sha256', 'mobile_refresh_jsmith_' . time()));
        $token1->setExpiresIn(3600); // 1 hour
        $token1->setRevokedAt(null); // Still active
        $token1->setCreatedAt(new \DateTime('2024-02-05 10:30:00'));
        $token1->setScopes('read write');
        $token1->setPreviousRefreshToken('');
        $token1->setOauthApplication($this->getReference('oauth-app-mobile', \App\Entity\OauthApplication::class));
        $token1->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        
        $manager->persist($token1);
        $this->addReference('oauth-token-mobile-jsmith', $token1);

        // Active token for dashboard (Mike Johnson)
        $token2 = new OauthAccessToken();
        $token2->setToken(hash('sha256', 'dashboard_token_mjohnson_' . time()));
        $token2->setRefreshToken(hash('sha256', 'dashboard_refresh_mjohnson_' . time()));
        $token2->setExpiresIn(7200); // 2 hours
        $token2->setRevokedAt(null);
        $token2->setCreatedAt(new \DateTime('2024-02-05 09:15:00'));
        $token2->setScopes('read');
        $token2->setPreviousRefreshToken('');
        $token2->setOauthApplication($this->getReference('oauth-app-dashboard', \App\Entity\OauthApplication::class));
        $token2->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        
        $manager->persist($token2);
        $this->addReference('oauth-token-dashboard-mjohnson', $token2);

        // Revoked token for integration app (Sarah Garcia)
        $token3 = new OauthAccessToken();
        $token3->setToken(hash('sha256', 'integration_token_sgarcia_revoked'));
        $token3->setRefreshToken(hash('sha256', 'integration_refresh_sgarcia_revoked'));
        $token3->setExpiresIn(3600);
        $token3->setRevokedAt(new \DateTime('2024-02-04 16:30:00')); // Revoked
        $token3->setCreatedAt(new \DateTime('2024-02-03 14:20:00'));
        $token3->setScopes('read write issues time_entries');
        $token3->setPreviousRefreshToken('');
        $token3->setOauthApplication($this->getReference('oauth-app-integration', \App\Entity\OauthApplication::class));
        $token3->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        
        $manager->persist($token3);
        $this->addReference('oauth-token-integration-revoked', $token3);

        // Active token for public web client (Alice Lee)
        $token4 = new OauthAccessToken();
        $token4->setToken(hash('sha256', 'public_token_alee_' . time()));
        $token4->setRefreshToken(null); // Public clients might not have refresh tokens
        $token4->setExpiresIn(1800); // 30 minutes (shorter for public clients)
        $token4->setRevokedAt(null);
        $token4->setCreatedAt(new \DateTime('2024-02-05 11:45:00'));
        $token4->setScopes('read profile');
        $token4->setPreviousRefreshToken('');
        $token4->setOauthApplication($this->getReference('oauth-app-public', \App\Entity\OauthApplication::class));
        $token4->setUser($this->getReference('user-alee', \App\Entity\User::class));
        
        $manager->persist($token4);
        $this->addReference('oauth-token-public-alee', $token4);

        // CI/CD pipeline token (Admin user)
        $token5 = new OauthAccessToken();
        $token5->setToken(hash('sha256', 'cicd_token_admin_' . time()));
        $token5->setRefreshToken(hash('sha256', 'cicd_refresh_admin_' . time()));
        $token5->setExpiresIn(86400); // 24 hours (longer for automated systems)
        $token5->setRevokedAt(null);
        $token5->setCreatedAt(new \DateTime('2024-02-04 08:00:00'));
        $token5->setScopes('read write issues changesets');
        $token5->setPreviousRefreshToken('');
        $token5->setOauthApplication($this->getReference('oauth-app-cicd', \App\Entity\OauthApplication::class));
        $token5->setUser($this->getReference('user-admin', \App\Entity\User::class));
        
        $manager->persist($token5);
        $this->addReference('oauth-token-cicd-admin', $token5);

        // Time tracker token (David Brown)
        $token6 = new OauthAccessToken();
        $token6->setToken(hash('sha256', 'timetracker_token_dbrown_' . time()));
        $token6->setRefreshToken(hash('sha256', 'timetracker_refresh_dbrown_' . time()));
        $token6->setExpiresIn(3600);
        $token6->setRevokedAt(null);
        $token6->setCreatedAt(new \DateTime('2024-02-05 07:30:00'));
        $token6->setScopes('read write time_entries');
        $token6->setPreviousRefreshToken('');
        $token6->setOauthApplication($this->getReference('oauth-app-timetracker', \App\Entity\OauthApplication::class));
        $token6->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        
        $manager->persist($token6);
        $this->addReference('oauth-token-timetracker-dbrown', $token6);

        // Expired token (not revoked but expired)
        $token7 = new OauthAccessToken();
        $token7->setToken(hash('sha256', 'expired_token_mjohnson'));
        $token7->setRefreshToken(hash('sha256', 'expired_refresh_mjohnson'));
        $token7->setExpiresIn(3600);
        $token7->setRevokedAt(null); // Not revoked but expired by time
        $token7->setCreatedAt(new \DateTime('2024-02-02 10:00:00')); // Created 3 days ago, expired
        $token7->setScopes('read write');
        $token7->setPreviousRefreshToken('');
        $token7->setOauthApplication($this->getReference('oauth-app-mobile', \App\Entity\OauthApplication::class));
        $token7->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        
        $manager->persist($token7);
        $this->addReference('oauth-token-expired', $token7);

        // Token with refreshed token history
        $token8 = new OauthAccessToken();
        $token8->setToken(hash('sha256', 'refreshed_token_sgarcia_' . time()));
        $token8->setRefreshToken(hash('sha256', 'new_refresh_sgarcia_' . time()));
        $token8->setExpiresIn(3600);
        $token8->setRevokedAt(null);
        $token8->setCreatedAt(new \DateTime('2024-02-05 12:00:00'));
        $token8->setScopes('read write issues time_entries');
        $token8->setPreviousRefreshToken(hash('sha256', 'old_refresh_sgarcia')); // Previous refresh token
        $token8->setOauthApplication($this->getReference('oauth-app-integration', \App\Entity\OauthApplication::class));
        $token8->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        
        $manager->persist($token8);
        $this->addReference('oauth-token-refreshed', $token8);

        // Limited scope token
        $token9 = new OauthAccessToken();
        $token9->setToken(hash('sha256', 'limited_token_alee_' . time()));
        $token9->setRefreshToken(hash('sha256', 'limited_refresh_alee_' . time()));
        $token9->setExpiresIn(1800); // 30 minutes
        $token9->setRevokedAt(null);
        $token9->setCreatedAt(new \DateTime('2024-02-05 13:15:00'));
        $token9->setScopes('read'); // Limited to read-only
        $token9->setPreviousRefreshToken('');
        $token9->setOauthApplication($this->getReference('oauth-app-dashboard', \App\Entity\OauthApplication::class));
        $token9->setUser($this->getReference('user-alee', \App\Entity\User::class));
        
        $manager->persist($token9);
        $this->addReference('oauth-token-limited', $token9);

        // Multiple active tokens for same user/app (different scopes)
        $token10 = new OauthAccessToken();
        $token10->setToken(hash('sha256', 'second_mobile_token_jsmith_' . time()));
        $token10->setRefreshToken(hash('sha256', 'second_mobile_refresh_jsmith_' . time()));
        $token10->setExpiresIn(3600);
        $token10->setRevokedAt(null);
        $token10->setCreatedAt(new \DateTime('2024-02-05 14:00:00'));
        $token10->setScopes('read'); // Different scope than first token
        $token10->setPreviousRefreshToken('');
        $token10->setOauthApplication($this->getReference('oauth-app-mobile', \App\Entity\OauthApplication::class));
        $token10->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        
        $manager->persist($token10);
        $this->addReference('oauth-token-second-mobile', $token10);

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
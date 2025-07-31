<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\UserPreference;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class UserPreferenceFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Preferences for John Smith (Project Manager)
        $pref1 = new UserPreference();
        $pref1->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $pref1->setHideMail(0); // Show email to other users
        $pref1->setTimeZone('America/New_York');
        $pref1->setOthers(json_encode([
            'issues_per_page' => 50,
            'activity_days' => 30,
            'time_format' => '24h',
            'date_format' => '%Y-%m-%d',
            'notification_all_projects' => true,
            'notification_on_assigned' => true,
            'notification_on_watched' => true,
            'notification_on_comments' => true,
            'toolbar_location' => 'top',
            'warn_on_leaving_unsaved' => true,
            'auto_hide_menu' => false,
            'theme' => 'default'
        ]));
        
        $manager->persist($pref1);
        $this->addReference('preference-jsmith', $pref1);

        // Preferences for Mike Johnson (Developer)
        $pref2 = new UserPreference();
        $pref2->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $pref2->setHideMail(1); // Hide email from other users
        $pref2->setTimeZone('America/Los_Angeles');
        $pref2->setOthers(json_encode([
            'issues_per_page' => 25,
            'activity_days' => 14,
            'time_format' => '12h',
            'date_format' => '%m/%d/%Y',
            'notification_all_projects' => false,
            'notification_on_assigned' => true,
            'notification_on_watched' => false,
            'notification_on_comments' => false,
            'toolbar_location' => 'bottom',
            'warn_on_leaving_unsaved' => true,
            'auto_hide_menu' => true,
            'theme' => 'dark'
        ]));
        
        $manager->persist($pref2);
        $this->addReference('preference-mjohnson', $pref2);

        // Preferences for Sarah Garcia (Designer)
        $pref3 = new UserPreference();
        $pref3->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $pref3->setHideMail(0);
        $pref3->setTimeZone('Europe/Madrid');
        $pref3->setOthers(json_encode([
            'issues_per_page' => 15,
            'activity_days' => 7,
            'time_format' => '24h',
            'date_format' => '%d/%m/%Y',
            'notification_all_projects' => true,
            'notification_on_assigned' => true,
            'notification_on_watched' => true,
            'notification_on_comments' => true,
            'toolbar_location' => 'top',
            'warn_on_leaving_unsaved' => false,
            'auto_hide_menu' => false,
            'theme' => 'light'
        ]));
        
        $manager->persist($pref3);
        $this->addReference('preference-sgarcia', $pref3);

        // Preferences for David Brown (Tester)
        $pref4 = new UserPreference();
        $pref4->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        $pref4->setHideMail(1);
        $pref4->setTimeZone('America/Chicago');
        $pref4->setOthers(json_encode([
            'issues_per_page' => 100,
            'activity_days' => 90,
            'time_format' => '12h',
            'date_format' => '%Y-%m-%d',
            'notification_all_projects' => false,
            'notification_on_assigned' => true,
            'notification_on_watched' => true,
            'notification_on_comments' => false,
            'toolbar_location' => 'top',
            'warn_on_leaving_unsaved' => true,
            'auto_hide_menu' => false,
            'theme' => 'default'
        ]));
        
        $manager->persist($pref4);
        $this->addReference('preference-dbrown', $pref4);

        // Preferences for Alice Lee (Client)
        $pref5 = new UserPreference();
        $pref5->setUser($this->getReference('user-alee', \App\Entity\User::class));
        $pref5->setHideMail(0);
        $pref5->setTimeZone('America/New_York');
        $pref5->setOthers(json_encode([
            'issues_per_page' => 10,
            'activity_days' => 7,
            'time_format' => '12h',
            'date_format' => '%m/%d/%Y',
            'notification_all_projects' => true,
            'notification_on_assigned' => true,
            'notification_on_watched' => true,
            'notification_on_comments' => true,
            'toolbar_location' => 'top',
            'warn_on_leaving_unsaved' => false,
            'auto_hide_menu' => true,
            'theme' => 'default'
        ]));
        
        $manager->persist($pref5);
        $this->addReference('preference-alee', $pref5);

        // Preferences for Admin (System Administrator)
        $prefAdmin = new UserPreference();
        $prefAdmin->setUser($this->getReference('user-admin', \App\Entity\User::class));
        $prefAdmin->setHideMail(1);
        $prefAdmin->setTimeZone('UTC');
        $prefAdmin->setOthers(json_encode([
            'issues_per_page' => 100,
            'activity_days' => 365,
            'time_format' => '24h',
            'date_format' => '%Y-%m-%d %H:%M',
            'notification_all_projects' => true,
            'notification_on_assigned' => true,
            'notification_on_watched' => true,
            'notification_on_comments' => false,
            'toolbar_location' => 'top',
            'warn_on_leaving_unsaved' => true,
            'auto_hide_menu' => false,
            'theme' => 'admin'
        ]));
        
        $manager->persist($prefAdmin);
        $this->addReference('preference-admin', $prefAdmin);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}
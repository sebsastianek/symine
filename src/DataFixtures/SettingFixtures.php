<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Setting;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class SettingFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Application name
        $setting1 = new Setting();
        $setting1->setName('app_title');
        $setting1->setValue('Redmine Clone');
        $setting1->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting1);
        $this->addReference('setting-app-title', $setting1);

        // Application subtitle
        $setting2 = new Setting();
        $setting2->setName('app_subtitle');
        $setting2->setValue('Project Management & Issue Tracking');
        $setting2->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting2);
        $this->addReference('setting-app-subtitle', $setting2);

        // Welcome text
        $setting3 = new Setting();
        $setting3->setName('welcome_text');
        $setting3->setValue('Welcome to our project management system. Please login to access your projects and issues.');
        $setting3->setUpdatedOn(new \DateTime('2024-01-15 10:30:00'));
        
        $manager->persist($setting3);
        $this->addReference('setting-welcome-text', $setting3);

        // Default language
        $setting4 = new Setting();
        $setting4->setName('default_language');
        $setting4->setValue('en');
        $setting4->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting4);
        $this->addReference('setting-default-language', $setting4);

        // Issues per page
        $setting5 = new Setting();
        $setting5->setName('per_page_options');
        $setting5->setValue('10,25,50,100');
        $setting5->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting5);
        $this->addReference('setting-per-page-options', $setting5);

        // Maximum file upload size
        $setting6 = new Setting();
        $setting6->setName('attachment_max_size');
        $setting6->setValue('5242880'); // 5MB in bytes
        $setting6->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting6);
        $this->addReference('setting-attachment-max-size', $setting6);

        // Allowed file extensions
        $setting7 = new Setting();
        $setting7->setName('attachment_extensions_allowed');
        $setting7->setValue('txt,pdf,png,jpg,jpeg,gif,doc,docx,xls,xlsx,ppt,pptx,zip,rar,7z,tar,gz');
        $setting7->setUpdatedOn(new \DateTime('2024-02-01 14:20:00'));
        
        $manager->persist($setting7);
        $this->addReference('setting-attachment-extensions', $setting7);

        // Email delivery method
        $setting8 = new Setting();
        $setting8->setName('email_delivery_method');
        $setting8->setValue('smtp');
        $setting8->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting8);
        $this->addReference('setting-email-delivery', $setting8);

        // SMTP settings
        $setting9 = new Setting();
        $setting9->setName('smtp_settings');
        $setting9->setValue(json_encode([
            'address' => 'smtp.company.com',
            'port' => 587,
            'domain' => 'company.com',
            'authentication' => 'plain',
            'user_name' => 'noreply@company.com',
            'password' => '[ENCRYPTED]',
            'enable_starttls_auto' => true
        ]));
        $setting9->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting9);
        $this->addReference('setting-smtp', $setting9);

        // Email sender address
        $setting10 = new Setting();
        $setting10->setName('mail_from');
        $setting10->setValue('noreply@company.com');
        $setting10->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting10);
        $this->addReference('setting-mail-from', $setting10);

        // Host name and path
        $setting11 = new Setting();
        $setting11->setName('host_name');
        $setting11->setValue('redmine.company.com');
        $setting11->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting11);
        $this->addReference('setting-host-name', $setting11);

        // Protocol
        $setting12 = new Setting();
        $setting12->setName('protocol');
        $setting12->setValue('https');
        $setting12->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting12);
        $this->addReference('setting-protocol', $setting12);

        // Text formatting
        $setting13 = new Setting();
        $setting13->setName('text_formatting');
        $setting13->setValue('markdown');
        $setting13->setUpdatedOn(new \DateTime('2024-01-10 16:45:00'));
        
        $manager->persist($setting13);
        $this->addReference('setting-text-formatting', $setting13);

        // Cache formatted text
        $setting14 = new Setting();
        $setting14->setName('cache_formatted_text');
        $setting14->setValue('1');
        $setting14->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting14);
        $this->addReference('setting-cache-formatted-text', $setting14);

        // Default tracker for new projects
        $setting15 = new Setting();
        $setting15->setName('default_tracker_ids');
        $setting15->setValue('1,2,3'); // Bug, Feature, Support tracker IDs
        $setting15->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting15);
        $this->addReference('setting-default-trackers', $setting15);

        // Default issue status
        $setting16 = new Setting();
        $setting16->setName('default_issue_status_id');
        $setting16->setValue('1'); // New status ID
        $setting16->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting16);
        $this->addReference('setting-default-issue-status', $setting16);

        // Default priority
        $setting17 = new Setting();
        $setting17->setName('default_priority_id');
        $setting17->setValue('3'); // Normal priority ID
        $setting17->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting17);
        $this->addReference('setting-default-priority', $setting17);

        // Display subprojects issues
        $setting18 = new Setting();
        $setting18->setName('display_subprojects_issues');
        $setting18->setValue('1');
        $setting18->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting18);
        $this->addReference('setting-display-subprojects', $setting18);

        // Login required
        $setting19 = new Setting();
        $setting19->setName('login_required');
        $setting19->setValue('0');
        $setting19->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting19);
        $this->addReference('setting-login-required', $setting19);

        // Self registration
        $setting20 = new Setting();
        $setting20->setName('self_registration');
        $setting20->setValue('2'); // 0=disabled, 1=enabled, 2=approval required
        $setting20->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting20);
        $this->addReference('setting-self-registration', $setting20);

        // Password minimum length
        $setting21 = new Setting();
        $setting21->setName('password_min_length');
        $setting21->setValue('8');
        $setting21->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting21);
        $this->addReference('setting-password-min-length', $setting21);

        // Lost password
        $setting22 = new Setting();
        $setting22->setName('lost_password');
        $setting22->setValue('1');
        $setting22->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting22);
        $this->addReference('setting-lost-password', $setting22);

        // Maximum attempts
        $setting23 = new Setting();
        $setting23->setName('max_additional_emails');
        $setting23->setValue('5');
        $setting23->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting23);
        $this->addReference('setting-max-additional-emails', $setting23);

        // Activity days back
        $setting24 = new Setting();
        $setting24->setName('activity_days_default');
        $setting24->setValue('30');
        $setting24->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting24);
        $this->addReference('setting-activity-days', $setting24);

        // Feeds enabled
        $setting25 = new Setting();
        $setting25->setName('feeds_enabled');
        $setting25->setValue('1');
        $setting25->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting25);
        $this->addReference('setting-feeds-enabled', $setting25);

        // Gravatar enabled
        $setting26 = new Setting();
        $setting26->setName('gravatar_enabled');
        $setting26->setValue('1');
        $setting26->setUpdatedOn(new \DateTime('2024-02-15 11:30:00'));
        
        $manager->persist($setting26);
        $this->addReference('setting-gravatar-enabled', $setting26);

        // Gravatar default
        $setting27 = new Setting();
        $setting27->setName('gravatar_default');
        $setting27->setValue('mm');
        $setting27->setUpdatedOn(new \DateTime('2024-02-15 11:30:00'));
        
        $manager->persist($setting27);
        $this->addReference('setting-gravatar-default', $setting27);

        // Cross project issue relations
        $setting28 = new Setting();
        $setting28->setName('cross_project_issue_relations');
        $setting28->setValue('1');
        $setting28->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting28);
        $this->addReference('setting-cross-project-relations', $setting28);

        // System timezone
        $setting29 = new Setting();
        $setting29->setName('default_timezone');
        $setting29->setValue('UTC');
        $setting29->setUpdatedOn(new \DateTime('2024-01-01 00:00:00'));
        
        $manager->persist($setting29);
        $this->addReference('setting-default-timezone', $setting29);

        // Issue list default columns
        $setting30 = new Setting();
        $setting30->setName('issue_list_default_columns');
        $setting30->setValue('tracker,status,priority,subject,assigned_to,updated_on');
        $setting30->setUpdatedOn(new \DateTime('2024-01-05 09:15:00'));
        
        $manager->persist($setting30);
        $this->addReference('setting-issue-list-columns', $setting30);

        $manager->flush();
    }
}
<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\SchemaMigration;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class SchemaMigrationFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Rails migration versions - typical Redmine migration history
        $migrations = [
            '20120101000001', // Initial database setup
            '20120615000000', // Create users table
            '20120615000001', // Create projects table
            '20120615000002', // Create issues table
            '20120615000003', // Create trackers table
            '20120615000004', // Create issue_statuses table
            '20120615000005', // Create roles table
            '20120615000006', // Create members table
            '20120615000007', // Create member_roles table
            '20120615000008', // Create versions table
            '20120615000009', // Create time_entries table
            '20120615000010', // Create custom_fields table
            '20120615000011', // Create custom_values table
            '20120615000012', // Create documents table
            '20120615000013', // Create attachments table
            '20120615000014', // Create wikis table
            '20120615000015', // Create wiki_pages table
            '20120615000016', // Create wiki_contents table
            '20120615000017', // Create repositories table
            '20120615000018', // Create changesets table
            '20120615000019', // Create changes table
            '20120615000020', // Create news table
            '20120615000021', // Create comments table
            '20120615000022', // Create journals table
            '20120615000023', // Create journal_details table
            '20120615000024', // Create queries table
            '20120615000025', // Create tokens table
            '20120615000026', // Create watchers table
            '20120615000027', // Create boards table
            '20120615000028', // Create messages table
            '20140101000000', // Add OAuth support
            '20140101000001', // Add OAuth applications
            '20140101000002', // Add OAuth access tokens
            '20140101000003', // Add OAuth access grants
            '20150601000000', // Add email addresses table
            '20160101000000', // Add two-factor authentication
            '20170101000000', // Add import functionality
            '20170101000001', // Add import items table
            '20180101000000', // Add user preferences enhancements
            '20190101000000', // Add workflow enhancements
            '20200101000000', // Add custom field enumerations
            '20210101000000', // Add roles managed roles
            '20220101000000', // Add queries roles
            '20230101000000', // Add changeset parents
            '20240101000000', // Add wiki redirects
            '20240201000000', // Add wiki content versions
            '20240301000000', // Latest schema updates
        ];

        foreach ($migrations as $version) {
            $migration = new SchemaMigration();
            $migration->setVersion($version);
            
            $manager->persist($migration);
            $this->addReference('schema-migration-' . $version, $migration);
        }

        $manager->flush();
    }
}
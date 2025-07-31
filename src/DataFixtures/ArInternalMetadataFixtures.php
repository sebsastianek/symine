<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ArInternalMetadata;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class ArInternalMetadataFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Rails internal metadata for environment
        $metadata1 = new ArInternalMetadata();
        $metadata1->setKey('environment');
        $metadata1->setValue('development');
        $metadata1->setCreatedAt(new \DateTime('2024-01-01 10:00:00'));
        $metadata1->setUpdatedAt(new \DateTime('2024-01-01 10:00:00'));
        
        $manager->persist($metadata1);
        $this->addReference('ar-metadata-environment', $metadata1);

        // Rails internal metadata for schema format
        $metadata2 = new ArInternalMetadata();
        $metadata2->setKey('schema_format');
        $metadata2->setValue('ruby');
        $metadata2->setCreatedAt(new \DateTime('2024-01-01 10:00:00'));
        $metadata2->setUpdatedAt(new \DateTime('2024-01-01 10:00:00'));
        
        $manager->persist($metadata2);
        $this->addReference('ar-metadata-schema-format', $metadata2);

        // Rails internal metadata for application version
        $metadata3 = new ArInternalMetadata();
        $metadata3->setKey('redmine_version');
        $metadata3->setValue('5.1.0');
        $metadata3->setCreatedAt(new \DateTime('2024-01-01 10:00:00'));
        $metadata3->setUpdatedAt(new \DateTime('2024-03-01 14:30:00'));
        
        $manager->persist($metadata3);
        $this->addReference('ar-metadata-redmine-version', $metadata3);

        // Rails internal metadata for plugins
        $metadata4 = new ArInternalMetadata();
        $metadata4->setKey('plugins_migrated');
        $metadata4->setValue('true');
        $metadata4->setCreatedAt(new \DateTime('2024-01-01 10:00:00'));
        $metadata4->setUpdatedAt(new \DateTime('2024-02-15 09:20:00'));
        
        $manager->persist($metadata4);
        $this->addReference('ar-metadata-plugins', $metadata4);

        $manager->flush();
    }
}
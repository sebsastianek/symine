<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Attachment;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class AttachmentFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Attachment 1: Screenshot for login bug
        $attachment1 = new Attachment();
        $attachment1->setContainerId($this->getReference('issue-login-bug', \App\Entity\Issue::class)->getId());
        $attachment1->setContainerType('Issue');
        $attachment1->setFilename('login_validation_error.png');
        $attachment1->setDiskFilename('2024/01/login_validation_error_12345.png');
        $attachment1->setFilesize(145620);
        $attachment1->setContentType('image/png');
        $attachment1->setDigest('a1b2c3d4e5f6789012345678901234567890abcd');
        $attachment1->setDownloads(3);
        $attachment1->setAuthor($this->getReference('user-dbrown', \App\Entity\User::class));
        $attachment1->setCreatedOn(new \DateTime('2024-02-14 11:30:00'));
        $attachment1->setDescription('Screenshot showing validation error on login form');
        
        $manager->persist($attachment1);
        $this->addReference('attachment-login-screenshot', $attachment1);

        // Attachment 2: API specification document
        $attachment2 = new Attachment();
        $attachment2->setContainerId($this->getReference('issue-auth', \App\Entity\Issue::class)->getId());
        $attachment2->setContainerType('Issue');
        $attachment2->setFilename('authentication_api_spec.pdf');
        $attachment2->setDiskFilename('2024/01/authentication_api_spec_67890.pdf');
        $attachment2->setFilesize(892140);
        $attachment2->setContentType('application/pdf');
        $attachment2->setDigest('f1e2d3c4b5a6987654321098765432109876fedc');
        $attachment2->setDownloads(8);
        $attachment2->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $attachment2->setCreatedOn(new \DateTime('2024-01-12 14:45:00'));
        $attachment2->setDescription('Detailed API specification for authentication endpoints');
        
        $manager->persist($attachment2);
        $this->addReference('attachment-api-spec', $attachment2);

        // Attachment 3: Wireframe for frontend layout
        $attachment3 = new Attachment();
        $attachment3->setContainerId($this->getReference('issue-frontend-layout', \App\Entity\Issue::class)->getId());
        $attachment3->setContainerType('Issue');
        $attachment3->setFilename('responsive_layout_wireframe.sketch');
        $attachment3->setDiskFilename('2024/02/responsive_layout_wireframe_13579.sketch');
        $attachment3->setFilesize(2340567);
        $attachment3->setContentType('application/octet-stream');
        $attachment3->setDigest('9876543210abcdef1234567890abcdef12345678');
        $attachment3->setDownloads(5);
        $attachment3->setAuthor($this->getReference('user-sgarcia', \App\Entity\User::class));
        $attachment3->setCreatedOn(new \DateTime('2024-02-02 16:20:00'));
        $attachment3->setDescription('Sketch wireframe for responsive layout design');
        
        $manager->persist($attachment3);
        $this->addReference('attachment-wireframe', $attachment3);

        // Attachment 4: Database schema for mobile app
        $attachment4 = new Attachment();
        $attachment4->setContainerId($this->getReference('issue-mobile-setup', \App\Entity\Issue::class)->getId());
        $attachment4->setContainerType('Issue');
        $attachment4->setFilename('mobile_app_database_schema.sql');
        $attachment4->setDiskFilename('2024/02/mobile_app_database_schema_24680.sql');
        $attachment4->setFilesize(45120);
        $attachment4->setContentType('text/plain');
        $attachment4->setDigest('abcdef1234567890123456789012345678901234');
        $attachment4->setDownloads(12);
        $attachment4->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $attachment4->setCreatedOn(new \DateTime('2024-02-05 10:15:00'));
        $attachment4->setDescription('SQL schema for mobile app local database');
        
        $manager->persist($attachment4);
        $this->addReference('attachment-mobile-schema', $attachment4);

        // Attachment 5: Test results report
        $attachment5 = new Attachment();
        $attachment5->setContainerId($this->getReference('issue-login-bug', \App\Entity\Issue::class)->getId());
        $attachment5->setContainerType('Issue');
        $attachment5->setFilename('login_bug_test_results.xlsx');
        $attachment5->setDiskFilename('2024/02/login_bug_test_results_97531.xlsx');
        $attachment5->setFilesize(67890);
        $attachment5->setContentType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $attachment5->setDigest('1234567890abcdef1234567890abcdef12345678');
        $attachment5->setDownloads(6);
        $attachment5->setAuthor($this->getReference('user-dbrown', \App\Entity\User::class));
        $attachment5->setCreatedOn(new \DateTime('2024-02-16 13:45:00'));
        $attachment5->setDescription('Comprehensive test results for login validation fix');
        
        $manager->persist($attachment5);
        $this->addReference('attachment-test-results', $attachment5);

        // Attachment 6: Security assessment report (private issue)
        $attachment6 = new Attachment();
        $attachment6->setContainerId($this->getReference('issue-security-private', \App\Entity\Issue::class)->getId());
        $attachment6->setContainerType('Issue');
        $attachment6->setFilename('security_vulnerability_assessment.pdf');
        $attachment6->setDiskFilename('2024/02/security_vulnerability_assessment_86420.pdf');
        $attachment6->setFilesize(1234567);
        $attachment6->setContentType('application/pdf');
        $attachment6->setDigest('fedcba0987654321098765432109876543210987');
        $attachment6->setDownloads(2);
        $attachment6->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $attachment6->setCreatedOn(new \DateTime('2024-02-23 14:30:00'));
        $attachment6->setDescription('Confidential security vulnerability assessment report');
        
        $manager->persist($attachment6);
        $this->addReference('attachment-security-report', $attachment6);

        // Attachment 7: CRM mockup attached to project document
        $attachment7 = new Attachment();
        $attachment7->setContainerId($this->getReference('doc-requirements', \App\Entity\Document::class)->getId());
        $attachment7->setContainerType('Document');
        $attachment7->setFilename('crm_ui_mockup.psd');
        $attachment7->setDiskFilename('2024/01/crm_ui_mockup_11223.psd');
        $attachment7->setFilesize(5678901);
        $attachment7->setContentType('application/octet-stream');
        $attachment7->setDigest('56789012345678901234567890123456789012ab');
        $attachment7->setDownloads(7);
        $attachment7->setAuthor($this->getReference('user-sgarcia', \App\Entity\User::class));
        $attachment7->setCreatedOn(new \DateTime('2024-01-18 11:00:00'));
        $attachment7->setDescription('Photoshop mockup for CRM user interface design');
        
        $manager->persist($attachment7);
        $this->addReference('attachment-crm-mockup', $attachment7);

        // Attachment 8: Configuration file for deployment
        $attachment8 = new Attachment();
        $attachment8->setContainerId($this->getReference('issue-support-login', \App\Entity\Issue::class)->getId());
        $attachment8->setContainerType('Issue');
        $attachment8->setFilename('server_config_backup.json');
        $attachment8->setDiskFilename('2024/02/server_config_backup_33445.json');
        $attachment8->setFilesize(12890);
        $attachment8->setContentType('application/json');
        $attachment8->setDigest('ab12cd34ef56789012345678901234567890cdef');
        $attachment8->setDownloads(4);
        $attachment8->setAuthor($this->getReference('user-dbrown', \App\Entity\User::class));
        $attachment8->setCreatedOn(new \DateTime('2024-02-28 15:20:00'));
        $attachment8->setDescription('Server configuration backup for troubleshooting');
        
        $manager->persist($attachment8);
        $this->addReference('attachment-config-backup', $attachment8);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            IssueFixtures::class,
            DocumentFixtures::class,
        ];
    }
}
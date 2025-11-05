<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Changeset;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ChangesetFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Git repository changeset for Backend project
        $changeset1 = new Changeset();
        $changeset1->setRepository($this->getReference('repo-backend', \App\Entity\Repository::class));
        $changeset1->setRevision('a1b2c3d4e5f6789012345678901234567890abcd');
        $changeset1->setCommitter('john.smith@company.com');
        $changeset1->setCommittedOn(new \DateTime('2024-01-15 14:30:00'));
        $changeset1->setComments('Initial product catalog implementation

- Added Product entity with basic properties
- Implemented ProductRepository with search methods
- Created product controller with CRUD operations
- Added unit tests for product functionality

Fixes #123');
        $changeset1->setCommitDate(new \DateTime('2024-01-15'));
        $changeset1->setScmid('a1b2c3d4e5f6789012345678901234567890abcd');
        $changeset1->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        
        $manager->persist($changeset1);
        $this->addReference('changeset-product-catalog', $changeset1);

        // Bug fix changeset
        $changeset2 = new Changeset();
        $changeset2->setRepository($this->getReference('repo-backend', \App\Entity\Repository::class));
        $changeset2->setRevision('b2c3d4e5f6789012345678901234567890abcdef');
        $changeset2->setCommitter('mike.johnson@company.com');
        $changeset2->setCommittedOn(new \DateTime('2024-01-16 09:15:00'));
        $changeset2->setComments('Fix cart calculation bug

The total price calculation was not including tax properly.
This change ensures tax is calculated correctly for all
product types including digital downloads.

Fixes #456');
        $changeset2->setCommitDate(new \DateTime('2024-01-16'));
        $changeset2->setScmid('b2c3d4e5f6789012345678901234567890abcdef');
        $changeset2->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        
        $manager->persist($changeset2);
        $this->addReference('changeset-cart-bugfix', $changeset2);

        // Feature addition changeset
        $changeset3 = new Changeset();
        $changeset3->setRepository($this->getReference('repo-backend', \App\Entity\Repository::class));
        $changeset3->setRevision('c3d4e5f6789012345678901234567890abcdef12');
        $changeset3->setCommitter('sarah.garcia@company.com');
        $changeset3->setCommittedOn(new \DateTime('2024-01-18 16:45:00'));
        $changeset3->setComments('Add email notification system

Implemented comprehensive email notification system:
- Welcome emails for new users
- Order confirmation emails
- Shipping notification emails
- Password reset emails

Uses Symfony Mailer with template engine.

Implements #789');
        $changeset3->setCommitDate(new \DateTime('2024-01-18'));
        $changeset3->setScmid('c3d4e5f6789012345678901234567890abcdef12');
        $changeset3->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        
        $manager->persist($changeset3);
        $this->addReference('changeset-email-notifications', $changeset3);

        // Repository changeset for CRM project
        $changeset4 = new Changeset();
        $changeset4->setRepository($this->getReference('repo-crm', \App\Entity\Repository::class));
        $changeset4->setRevision('1234');
        $changeset4->setCommitter('david.brown');
        $changeset4->setCommittedOn(new \DateTime('2024-01-20 11:20:00'));
        $changeset4->setComments('Update customer import functionality

Modified the customer data import to handle new CSV format
from the external CRM system. Added validation for phone
numbers and email addresses.

Resolves #321');
        $changeset4->setCommitDate(new \DateTime('2024-01-20'));
        $changeset4->setScmid('1234');
        $changeset4->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        
        $manager->persist($changeset4);
        $this->addReference('changeset-customer-import', $changeset4);

        // Documentation update changeset
        $changeset5 = new Changeset();
        $changeset5->setRepository($this->getReference('repo-docs', \App\Entity\Repository::class));
        $changeset5->setRevision('d4e5f6789012345678901234567890abcdef1234');
        $changeset5->setCommitter('alice.lee@company.com');
        $changeset5->setCommittedOn(new \DateTime('2024-01-22 13:30:00'));
        $changeset5->setComments('Update API documentation

- Added new authentication endpoints
- Updated rate limiting documentation
- Fixed examples in user management section
- Added troubleshooting guide

Resolves #654');
        $changeset5->setCommitDate(new \DateTime('2024-01-22'));
        $changeset5->setScmid('d4e5f6789012345678901234567890abcdef1234');
        $changeset5->setUser($this->getReference('user-alee', \App\Entity\User::class));
        
        $manager->persist($changeset5);
        $this->addReference('changeset-api-docs', $changeset5);

        // Mobile app changeset
        $changeset6 = new Changeset();
        $changeset6->setRepository($this->getReference('repo-mobile', \App\Entity\Repository::class));
        $changeset6->setRevision('e5f6789012345678901234567890abcdef12345');
        $changeset6->setCommitter('mike.johnson@company.com');
        $changeset6->setCommittedOn(new \DateTime('2024-01-25 10:45:00'));
        $changeset6->setComments('Implement offline mode for mobile app

Added capability to browse products and manage cart
while offline. Data syncs when connection is restored.

Features:
- Local SQLite storage
- Background sync service
- Conflict resolution for cart items
- Offline indicator in UI

Implements #987');
        $changeset6->setCommitDate(new \DateTime('2024-01-25'));
        $changeset6->setScmid('e5f6789012345678901234567890abcdef12345');
        $changeset6->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        
        $manager->persist($changeset6);
        $this->addReference('changeset-mobile-offline', $changeset6);

        // Security fix changeset
        $changeset7 = new Changeset();
        $changeset7->setRepository($this->getReference('repo-backend', \App\Entity\Repository::class));
        $changeset7->setRevision('f6789012345678901234567890abcdef123456');
        $changeset7->setCommitter('admin@company.com');
        $changeset7->setCommittedOn(new \DateTime('2024-01-28 08:15:00'));
        $changeset7->setComments('Security fix: prevent SQL injection in search

Updated search functionality to use parameterized queries
and added input validation. All user inputs are now properly
sanitized before database operations.

Critical security fix for CVE-2024-0001');
        $changeset7->setCommitDate(new \DateTime('2024-01-28'));
        $changeset7->setScmid('f6789012345678901234567890abcdef123456');
        $changeset7->setUser($this->getReference('user-admin', \App\Entity\User::class));
        
        $manager->persist($changeset7);
        $this->addReference('changeset-security-fix', $changeset7);

        // Performance optimization changeset
        $changeset8 = new Changeset();
        $changeset8->setRepository($this->getReference('repo-backend', \App\Entity\Repository::class));
        $changeset8->setRevision('6789012345678901234567890abcdef1234567');
        $changeset8->setCommitter('sarah.garcia@company.com');
        $changeset8->setCommittedOn(new \DateTime('2024-02-01 15:20:00'));
        $changeset8->setComments('Optimize database queries for product listing

- Added indexes on frequently queried columns
- Implemented query result caching
- Reduced N+1 query problems with eager loading
- Added pagination for large result sets

Page load time reduced from 2.3s to 0.4s.

Resolves #1123');
        $changeset8->setCommitDate(new \DateTime('2024-02-01'));
        $changeset8->setScmid('6789012345678901234567890abcdef1234567');
        $changeset8->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        
        $manager->persist($changeset8);
        $this->addReference('changeset-performance-opt', $changeset8);

        // Merge commit changeset
        $changeset9 = new Changeset();
        $changeset9->setRepository($this->getReference('repo-backend', \App\Entity\Repository::class));
        $changeset9->setRevision('789012345678901234567890abcdef12345678');
        $changeset9->setCommitter('john.smith@company.com');
        $changeset9->setCommittedOn(new \DateTime('2024-02-03 10:00:00'));
        $changeset9->setComments('Merge feature branch into main

Merged feature development branch with new customer features
into the main branch after successful testing and review.

Merge branch feature/customer-dashboard into main');
        $changeset9->setCommitDate(new \DateTime('2024-02-03'));
        $changeset9->setScmid('789012345678901234567890abcdef12345678');
        $changeset9->setUser($this->getReference('user-jsmith', \App\Entity\User::class));

        $manager->persist($changeset9);
        $this->addReference('changeset-merge-feature', $changeset9);

        // Feature branch changeset (parent of merge)
        $changeset10 = new Changeset();
        $changeset10->setRepository($this->getReference('repo-backend', \App\Entity\Repository::class));
        $changeset10->setRevision('89012345678901234567890abcdef123456789');
        $changeset10->setCommitter('sarah.garcia@company.com');
        $changeset10->setCommittedOn(new \DateTime('2024-02-02 14:00:00'));
        $changeset10->setComments('Feature: Customer dashboard implementation');
        $changeset10->setCommitDate(new \DateTime('2024-02-02'));
        $changeset10->setScmid('89012345678901234567890abcdef123456789');
        $changeset10->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));

        $manager->persist($changeset10);
        $this->addReference('changeset-feature-branch', $changeset10);

        // Main branch changeset (parent of merge)
        $changeset11 = new Changeset();
        $changeset11->setRepository($this->getReference('repo-backend', \App\Entity\Repository::class));
        $changeset11->setRevision('9012345678901234567890abcdef1234567890');
        $changeset11->setCommitter('john.smith@company.com');
        $changeset11->setCommittedOn(new \DateTime('2024-02-02 16:00:00'));
        $changeset11->setComments('Update main branch dependencies');
        $changeset11->setCommitDate(new \DateTime('2024-02-02'));
        $changeset11->setScmid('9012345678901234567890abcdef1234567890');
        $changeset11->setUser($this->getReference('user-jsmith', \App\Entity\User::class));

        $manager->persist($changeset11);
        $this->addReference('changeset-main-branch', $changeset11);

        // Hotfix merge changeset
        $changeset12 = new Changeset();
        $changeset12->setRepository($this->getReference('repo-backend', \App\Entity\Repository::class));
        $changeset12->setRevision('012345678901234567890abcdef12345678901');
        $changeset12->setCommitter('admin@company.com');
        $changeset12->setCommittedOn(new \DateTime('2024-01-29 09:00:00'));
        $changeset12->setComments('Merge hotfix/security-fix into main');
        $changeset12->setCommitDate(new \DateTime('2024-01-29'));
        $changeset12->setScmid('012345678901234567890abcdef12345678901');
        $changeset12->setUser($this->getReference('user-admin', \App\Entity\User::class));

        $manager->persist($changeset12);
        $this->addReference('changeset-hotfix-merge', $changeset12);

        // E-commerce cart changeset
        $changeset13 = new Changeset();
        $changeset13->setRepository($this->getReference('repo-backend', \App\Entity\Repository::class));
        $changeset13->setRevision('12345678901234567890abcdef123456789012');
        $changeset13->setCommitter('mike.johnson@company.com');
        $changeset13->setCommittedOn(new \DateTime('2024-01-28 11:00:00'));
        $changeset13->setComments('Improve cart functionality');
        $changeset13->setCommitDate(new \DateTime('2024-01-28'));
        $changeset13->setScmid('12345678901234567890abcdef123456789012');
        $changeset13->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));

        $manager->persist($changeset13);
        $this->addReference('changeset-ecommerce-cart', $changeset13);

        // Release prep changeset
        $changeset14 = new Changeset();
        $changeset14->setRepository($this->getReference('repo-backend', \App\Entity\Repository::class));
        $changeset14->setRevision('23456789012345678901234567890abcdef123');
        $changeset14->setCommitter('john.smith@company.com');
        $changeset14->setCommittedOn(new \DateTime('2024-02-05 10:00:00'));
        $changeset14->setComments('Merge release/v2.0 preparation');
        $changeset14->setCommitDate(new \DateTime('2024-02-05'));
        $changeset14->setScmid('23456789012345678901234567890abcdef123');
        $changeset14->setUser($this->getReference('user-jsmith', \App\Entity\User::class));

        $manager->persist($changeset14);
        $this->addReference('changeset-release-prep', $changeset14);

        // API refactor changeset
        $changeset15 = new Changeset();
        $changeset15->setRepository($this->getReference('repo-backend', \App\Entity\Repository::class));
        $changeset15->setRevision('3456789012345678901234567890abcdef1234');
        $changeset15->setCommitter('sarah.garcia@company.com');
        $changeset15->setCommittedOn(new \DateTime('2024-02-04 13:00:00'));
        $changeset15->setComments('Refactor API endpoints structure');
        $changeset15->setCommitDate(new \DateTime('2024-02-04'));
        $changeset15->setScmid('3456789012345678901234567890abcdef1234');
        $changeset15->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));

        $manager->persist($changeset15);
        $this->addReference('changeset-api-refactor', $changeset15);

        // Performance update changeset
        $changeset16 = new Changeset();
        $changeset16->setRepository($this->getReference('repo-backend', \App\Entity\Repository::class));
        $changeset16->setRevision('456789012345678901234567890abcdef12345');
        $changeset16->setCommitter('mike.johnson@company.com');
        $changeset16->setCommittedOn(new \DateTime('2024-02-04 15:00:00'));
        $changeset16->setComments('Performance improvements for search');
        $changeset16->setCommitDate(new \DateTime('2024-02-04'));
        $changeset16->setScmid('456789012345678901234567890abcdef12345');
        $changeset16->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));

        $manager->persist($changeset16);
        $this->addReference('changeset-performance-update', $changeset16);

        // Documentation update changeset
        $changeset17 = new Changeset();
        $changeset17->setRepository($this->getReference('repo-docs', \App\Entity\Repository::class));
        $changeset17->setRevision('56789012345678901234567890abcdef123456');
        $changeset17->setCommitter('alice.lee@company.com');
        $changeset17->setCommittedOn(new \DateTime('2024-02-10 11:30:00'));
        $changeset17->setComments('Update documentation with latest API changes');
        $changeset17->setCommitDate(new \DateTime('2024-02-10'));
        $changeset17->setScmid('56789012345678901234567890abcdef123456');
        $changeset17->setUser($this->getReference('user-alee', \App\Entity\User::class));

        $manager->persist($changeset17);
        $this->addReference('changeset-docs-update', $changeset17);

        // Test suite changeset
        $changeset18 = new Changeset();
        $changeset18->setRepository($this->getReference('repo-backend', \App\Entity\Repository::class));
        $changeset18->setRevision('6789012345678901234567890abcdef1234567');
        $changeset18->setCommitter('david.brown@company.com');
        $changeset18->setCommittedOn(new \DateTime('2024-02-12 14:15:00'));
        $changeset18->setComments('Add comprehensive test suite for authentication module');
        $changeset18->setCommitDate(new \DateTime('2024-02-12'));
        $changeset18->setScmid('6789012345678901234567890abcdef1234567');
        $changeset18->setUser($this->getReference('user-dbrown', \App\Entity\User::class));

        $manager->persist($changeset18);
        $this->addReference('changeset-test-suite', $changeset18);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            RepositoryFixtures::class,
            UserFixtures::class,
        ];
    }
}
<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Change;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ChangeFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Changes for product catalog implementation changeset
        $change1 = new Change();
        $change1->setChangeset($this->getReference('changeset-product-catalog', \App\Entity\Changeset::class));
        $change1->setAction('A'); // Added
        $change1->setPath('src/Entity/Product.php');
        $change1->setFromPath(null);
        $change1->setFromRevision(null);
        $change1->setRevision('a1b2c3d4e5f6789012345678901234567890abcd');
        $change1->setBranch('main');
        
        $manager->persist($change1);
        $this->addReference('change-product-entity', $change1);

        $change2 = new Change();
        $change2->setChangeset($this->getReference('changeset-product-catalog', \App\Entity\Changeset::class));
        $change2->setAction('A');
        $change2->setPath('src/Repository/ProductRepository.php');
        $change2->setFromPath(null);
        $change2->setFromRevision(null);
        $change2->setRevision('a1b2c3d4e5f6789012345678901234567890abcd');
        $change2->setBranch('main');
        
        $manager->persist($change2);
        $this->addReference('change-product-repository', $change2);

        $change3 = new Change();
        $change3->setChangeset($this->getReference('changeset-product-catalog', \App\Entity\Changeset::class));
        $change3->setAction('A');
        $change3->setPath('src/Controller/ProductController.php');
        $change3->setFromPath(null);
        $change3->setFromRevision(null);
        $change3->setRevision('a1b2c3d4e5f6789012345678901234567890abcd');
        $change3->setBranch('main');
        
        $manager->persist($change3);
        $this->addReference('change-product-controller', $change3);

        $change4 = new Change();
        $change4->setChangeset($this->getReference('changeset-product-catalog', \App\Entity\Changeset::class));
        $change4->setAction('A');
        $change4->setPath('tests/Unit/Entity/ProductTest.php');
        $change4->setFromPath(null);
        $change4->setFromRevision(null);
        $change4->setRevision('a1b2c3d4e5f6789012345678901234567890abcd');
        $change4->setBranch('main');
        
        $manager->persist($change4);
        $this->addReference('change-product-test', $change4);

        // Changes for cart bugfix changeset
        $change5 = new Change();
        $change5->setChangeset($this->getReference('changeset-cart-bugfix', \App\Entity\Changeset::class));
        $change5->setAction('M'); // Modified
        $change5->setPath('src/Service/CartService.php');
        $change5->setFromPath(null);
        $change5->setFromRevision('a1b2c3d4e5f6789012345678901234567890abcd');
        $change5->setRevision('b2c3d4e5f6789012345678901234567890abcdef');
        $change5->setBranch('main');
        
        $manager->persist($change5);
        $this->addReference('change-cart-service', $change5);

        $change6 = new Change();
        $change6->setChangeset($this->getReference('changeset-cart-bugfix', \App\Entity\Changeset::class));
        $change6->setAction('M');
        $change6->setPath('src/Entity/Cart.php');
        $change6->setFromPath(null);
        $change6->setFromRevision('a1b2c3d4e5f6789012345678901234567890abcd');
        $change6->setRevision('b2c3d4e5f6789012345678901234567890abcdef');
        $change6->setBranch('main');
        
        $manager->persist($change6);
        $this->addReference('change-cart-entity', $change6);

        $change7 = new Change();
        $change7->setChangeset($this->getReference('changeset-cart-bugfix', \App\Entity\Changeset::class));
        $change7->setAction('A');
        $change7->setPath('tests/Unit/Service/CartServiceTest.php');
        $change7->setFromPath(null);
        $change7->setFromRevision(null);
        $change7->setRevision('b2c3d4e5f6789012345678901234567890abcdef');
        $change7->setBranch('main');
        
        $manager->persist($change7);
        $this->addReference('change-cart-test', $change7);

        // Changes for email notifications changeset
        $change8 = new Change();
        $change8->setChangeset($this->getReference('changeset-email-notifications', \App\Entity\Changeset::class));
        $change8->setAction('A');
        $change8->setPath('src/Service/EmailNotificationService.php');
        $change8->setFromPath(null);
        $change8->setFromRevision(null);
        $change8->setRevision('c3d4e5f6789012345678901234567890abcdef12');
        $change8->setBranch('feature/email-notifications');
        
        $manager->persist($change8);
        $this->addReference('change-email-service', $change8);

        $change9 = new Change();
        $change9->setChangeset($this->getReference('changeset-email-notifications', \App\Entity\Changeset::class));
        $change9->setAction('A');
        $change9->setPath('templates/email/welcome.html.twig');
        $change9->setFromPath(null);
        $change9->setFromRevision(null);
        $change9->setRevision('c3d4e5f6789012345678901234567890abcdef12');
        $change9->setBranch('feature/email-notifications');
        
        $manager->persist($change9);
        $this->addReference('change-email-template', $change9);

        $change10 = new Change();
        $change10->setChangeset($this->getReference('changeset-email-notifications', \App\Entity\Changeset::class));
        $change10->setAction('A');
        $change10->setPath('templates/email/order_confirmation.html.twig');
        $change10->setFromPath(null);
        $change10->setFromRevision(null);
        $change10->setRevision('c3d4e5f6789012345678901234567890abcdef12');
        $change10->setBranch('feature/email-notifications');
        
        $manager->persist($change10);
        $this->addReference('change-order-email-template', $change10);

        $change11 = new Change();
        $change11->setChangeset($this->getReference('changeset-email-notifications', \App\Entity\Changeset::class));
        $change11->setAction('M');
        $change11->setPath('config/packages/mailer.yaml');
        $change11->setFromPath(null);
        $change11->setFromRevision('b2c3d4e5f6789012345678901234567890abcdef');
        $change11->setRevision('c3d4e5f6789012345678901234567890abcdef12');
        $change11->setBranch('feature/email-notifications');
        
        $manager->persist($change11);
        $this->addReference('change-mailer-config', $change11);

        // Changes for customer import (SVN repository)
        $change12 = new Change();
        $change12->setChangeset($this->getReference('changeset-customer-import', \App\Entity\Changeset::class));
        $change12->setAction('M');
        $change12->setPath('trunk/src/ImportService.php');
        $change12->setFromPath(null);
        $change12->setFromRevision('1233');
        $change12->setRevision('1234');
        $change12->setBranch(null); // SVN doesn't use branches the same way
        
        $manager->persist($change12);
        $this->addReference('change-import-service', $change12);

        $change13 = new Change();
        $change13->setChangeset($this->getReference('changeset-customer-import', \App\Entity\Changeset::class));
        $change13->setAction('A');
        $change13->setPath('trunk/src/Validator/PhoneValidator.php');
        $change13->setFromPath(null);
        $change13->setFromRevision(null);
        $change13->setRevision('1234');
        $change13->setBranch(null);
        
        $manager->persist($change13);
        $this->addReference('change-phone-validator', $change13);

        // Changes for mobile offline mode
        $change14 = new Change();
        $change14->setChangeset($this->getReference('changeset-mobile-offline', \App\Entity\Changeset::class));
        $change14->setAction('A');
        $change14->setPath('src/services/OfflineStorageService.ts');
        $change14->setFromPath(null);
        $change14->setFromRevision(null);
        $change14->setRevision('e5f6789012345678901234567890abcdef12345');
        $change14->setBranch('feature/offline-mode');
        
        $manager->persist($change14);
        $this->addReference('change-offline-storage', $change14);

        $change15 = new Change();
        $change15->setChangeset($this->getReference('changeset-mobile-offline', \App\Entity\Changeset::class));
        $change15->setAction('A');
        $change15->setPath('src/services/SyncService.ts');
        $change15->setFromPath(null);
        $change15->setFromRevision(null);
        $change15->setRevision('e5f6789012345678901234567890abcdef12345');
        $change15->setBranch('feature/offline-mode');
        
        $manager->persist($change15);
        $this->addReference('change-sync-service', $change15);

        $change16 = new Change();
        $change16->setChangeset($this->getReference('changeset-mobile-offline', \App\Entity\Changeset::class));
        $change16->setAction('M');
        $change16->setPath('src/components/ProductList.tsx');
        $change16->setFromPath(null);
        $change16->setFromRevision('d4e5f6789012345678901234567890abcdef1234');
        $change16->setRevision('e5f6789012345678901234567890abcdef12345');
        $change16->setBranch('feature/offline-mode');
        
        $manager->persist($change16);
        $this->addReference('change-product-list', $change16);

        // Changes for security fix
        $change17 = new Change();
        $change17->setChangeset($this->getReference('changeset-security-fix', \App\Entity\Changeset::class));
        $change17->setAction('M');
        $change17->setPath('src/Repository/ProductRepository.php');
        $change17->setFromPath(null);
        $change17->setFromRevision('e5f6789012345678901234567890abcdef12345');
        $change17->setRevision('f6789012345678901234567890abcdef123456');
        $change17->setBranch('hotfix/security-fix');
        
        $manager->persist($change17);
        $this->addReference('change-security-product-repo', $change17);

        $change18 = new Change();
        $change18->setChangeset($this->getReference('changeset-security-fix', \App\Entity\Changeset::class));
        $change18->setAction('M');
        $change18->setPath('src/Service/SearchService.php');
        $change18->setFromPath(null);
        $change18->setFromRevision('e5f6789012345678901234567890abcdef12345');
        $change18->setRevision('f6789012345678901234567890abcdef123456');
        $change18->setBranch('hotfix/security-fix');
        
        $manager->persist($change18);
        $this->addReference('change-security-search', $change18);

        // Changes for performance optimization
        $change19 = new Change();
        $change19->setChangeset($this->getReference('changeset-performance-opt', \App\Entity\Changeset::class));
        $change19->setAction('M');
        $change19->setPath('src/Repository/ProductRepository.php');
        $change19->setFromPath(null);
        $change19->setFromRevision('f6789012345678901234567890abcdef123456');
        $change19->setRevision('6789012345678901234567890abcdef1234567');
        $change19->setBranch('feature/performance-improvements');
        
        $manager->persist($change19);
        $this->addReference('change-perf-product-repo', $change19);

        $change20 = new Change();
        $change20->setChangeset($this->getReference('changeset-performance-opt', \App\Entity\Changeset::class));
        $change20->setAction('A');
        $change20->setPath('migrations/Version20240201_add_product_indexes.php');
        $change20->setFromPath(null);
        $change20->setFromRevision(null);
        $change20->setRevision('6789012345678901234567890abcdef1234567');
        $change20->setBranch('feature/performance-improvements');
        
        $manager->persist($change20);
        $this->addReference('change-perf-migration', $change20);

        $change21 = new Change();
        $change21->setChangeset($this->getReference('changeset-performance-opt', \App\Entity\Changeset::class));
        $change21->setAction('M');
        $change21->setPath('config/packages/doctrine.yaml');
        $change21->setFromPath(null);
        $change21->setFromRevision('f6789012345678901234567890abcdef123456');
        $change21->setRevision('6789012345678901234567890abcdef1234567');
        $change21->setBranch('feature/performance-improvements');
        
        $manager->persist($change21);
        $this->addReference('change-perf-doctrine-config', $change21);

        // File deletion example
        $change22 = new Change();
        $change22->setChangeset($this->getReference('changeset-performance-opt', \App\Entity\Changeset::class));
        $change22->setAction('D'); // Deleted
        $change22->setPath('src/Service/LegacyProductService.php');
        $change22->setFromPath(null);
        $change22->setFromRevision('f6789012345678901234567890abcdef123456');
        $change22->setRevision('6789012345678901234567890abcdef1234567');
        $change22->setBranch('feature/performance-improvements');
        
        $manager->persist($change22);
        $this->addReference('change-perf-delete-legacy', $change22);

        // File rename example
        $change23 = new Change();
        $change23->setChangeset($this->getReference('changeset-api-docs', \App\Entity\Changeset::class));
        $change23->setAction('R'); // Renamed
        $change23->setPath('docs/api/v2/authentication.md');
        $change23->setFromPath('docs/api/authentication.md');
        $change23->setFromRevision('c3d4e5f6789012345678901234567890abcdef12');
        $change23->setRevision('d4e5f6789012345678901234567890abcdef1234');
        $change23->setBranch('main');
        
        $manager->persist($change23);
        $this->addReference('change-docs-rename', $change23);

        $change24 = new Change();
        $change24->setChangeset($this->getReference('changeset-api-docs', \App\Entity\Changeset::class));
        $change24->setAction('A');
        $change24->setPath('docs/api/v2/rate-limiting.md');
        $change24->setFromPath(null);
        $change24->setFromRevision(null);
        $change24->setRevision('d4e5f6789012345678901234567890abcdef1234');
        $change24->setBranch('main');
        
        $manager->persist($change24);
        $this->addReference('change-docs-rate-limiting', $change24);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ChangesetFixtures::class,
        ];
    }
}
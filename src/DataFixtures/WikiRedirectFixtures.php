<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\WikiRedirect;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class WikiRedirectFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Redirect from old "Documentation" to new "Documentation Home"
        $redirect1 = new WikiRedirect();
        $redirect1->setWiki($this->getReference('wiki-docs', \App\Entity\Wiki::class));
        $redirect1->setTitle('Documentation');
        $redirect1->setRedirectsTo('Documentation Home');
        $redirect1->setRedirectsToWiki($this->getReference('wiki-docs', \App\Entity\Wiki::class));
        $redirect1->setCreatedOn(new \DateTime('2024-02-10 17:30:00'));
        
        $manager->persist($redirect1);
        $this->addReference('wiki-redirect-docs', $redirect1);

        // Redirect from old "Docs" to "Documentation Home"
        $redirect2 = new WikiRedirect();
        $redirect2->setWiki($this->getReference('wiki-docs', \App\Entity\Wiki::class));
        $redirect2->setTitle('Docs');
        $redirect2->setRedirectsTo('Documentation Home');
        $redirect2->setRedirectsToWiki($this->getReference('wiki-docs', \App\Entity\Wiki::class));
        $redirect2->setCreatedOn(new \DateTime('2024-02-10 17:30:00'));
        
        $manager->persist($redirect2);
        $this->addReference('wiki-redirect-docs-short', $redirect2);

        // Redirect from "Getting Started" to "Setup Guide"
        $redirect3 = new WikiRedirect();
        $redirect3->setWiki($this->getReference('wiki-ecommerce', \App\Entity\Wiki::class));
        $redirect3->setTitle('Getting Started');
        $redirect3->setRedirectsTo('Setup Guide');
        $redirect3->setRedirectsToWiki($this->getReference('wiki-ecommerce', \App\Entity\Wiki::class));
        $redirect3->setCreatedOn(new \DateTime('2024-01-20 11:00:00'));
        
        $manager->persist($redirect3);
        $this->addReference('wiki-redirect-getting-started', $redirect3);

        // Redirect from "Installation" to "Setup Guide"
        $redirect4 = new WikiRedirect();
        $redirect4->setWiki($this->getReference('wiki-ecommerce', \App\Entity\Wiki::class));
        $redirect4->setTitle('Installation');
        $redirect4->setRedirectsTo('Setup Guide');
        $redirect4->setRedirectsToWiki($this->getReference('wiki-ecommerce', \App\Entity\Wiki::class));
        $redirect4->setCreatedOn(new \DateTime('2024-01-20 11:00:00'));
        
        $manager->persist($redirect4);
        $this->addReference('wiki-redirect-installation', $redirect4);

        // Redirect from "REST API" to "API Documentation"
        $redirect5 = new WikiRedirect();
        $redirect5->setWiki($this->getReference('wiki-ecommerce', \App\Entity\Wiki::class));
        $redirect5->setTitle('REST API');
        $redirect5->setRedirectsTo('API Documentation');
        $redirect5->setRedirectsToWiki($this->getReference('wiki-ecommerce', \App\Entity\Wiki::class));
        $redirect5->setCreatedOn(new \DateTime('2024-01-25 09:15:00'));
        
        $manager->persist($redirect5);
        $this->addReference('wiki-redirect-rest-api', $redirect5);

        // Redirect from "Architecture" to "Technical Specifications"
        $redirect6 = new WikiRedirect();
        $redirect6->setWiki($this->getReference('wiki-docs', \App\Entity\Wiki::class));
        $redirect6->setTitle('Architecture');
        $redirect6->setRedirectsTo('Technical Specifications');
        $redirect6->setRedirectsToWiki($this->getReference('wiki-docs', \App\Entity\Wiki::class));
        $redirect6->setCreatedOn(new \DateTime('2024-02-12 14:20:00'));
        
        $manager->persist($redirect6);
        $this->addReference('wiki-redirect-architecture', $redirect6);

        // Redirect from "System Architecture" to "Technical Specifications"
        $redirect7 = new WikiRedirect();
        $redirect7->setWiki($this->getReference('wiki-docs', \App\Entity\Wiki::class));
        $redirect7->setTitle('System Architecture');
        $redirect7->setRedirectsTo('Technical Specifications');
        $redirect7->setRedirectsToWiki($this->getReference('wiki-docs', \App\Entity\Wiki::class));
        $redirect7->setCreatedOn(new \DateTime('2024-02-12 14:20:00'));
        
        $manager->persist($redirect7);
        $this->addReference('wiki-redirect-system-arch', $redirect7);

        // Redirect from old "Manual" to "User Guide" 
        $redirect8 = new WikiRedirect();
        $redirect8->setWiki($this->getReference('wiki-docs', \App\Entity\Wiki::class));
        $redirect8->setTitle('Manual');
        $redirect8->setRedirectsTo('User Guide');
        $redirect8->setRedirectsToWiki($this->getReference('wiki-docs', \App\Entity\Wiki::class));
        $redirect8->setCreatedOn(new \DateTime('2024-02-15 16:45:00'));
        
        $manager->persist($redirect8);
        $this->addReference('wiki-redirect-manual', $redirect8);

        // Cross-wiki redirect: E-commerce "Documentation" points to Docs wiki
        $redirect9 = new WikiRedirect();
        $redirect9->setWiki($this->getReference('wiki-ecommerce', \App\Entity\Wiki::class));
        $redirect9->setTitle('Documentation');
        $redirect9->setRedirectsTo('Documentation Home');
        $redirect9->setRedirectsToWiki($this->getReference('wiki-docs', \App\Entity\Wiki::class));
        $redirect9->setCreatedOn(new \DateTime('2024-02-18 10:30:00'));
        
        $manager->persist($redirect9);
        $this->addReference('wiki-redirect-cross-wiki', $redirect9);

        // Redirect from "Support" to "User Guide" in docs
        $redirect10 = new WikiRedirect();
        $redirect10->setWiki($this->getReference('wiki-docs', \App\Entity\Wiki::class));
        $redirect10->setTitle('Support');
        $redirect10->setRedirectsTo('User Guide');
        $redirect10->setRedirectsToWiki($this->getReference('wiki-docs', \App\Entity\Wiki::class));
        $redirect10->setCreatedOn(new \DateTime('2024-02-20 13:15:00'));
        
        $manager->persist($redirect10);
        $this->addReference('wiki-redirect-support', $redirect10);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            WikiFixtures::class,
        ];
    }
}
<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ChangesetsIssue;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ChangesetsIssueFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Changeset Issue 1: Cart calculation bug fix linked to issue
        $changesetIssue1 = new ChangesetsIssue();
        $changesetIssue1->setChangeset($this->getReference('changeset-ecommerce-cart', \App\Entity\Changeset::class));
        $changesetIssue1->setIssue($this->getReference('issue-1', \App\Entity\Issue::class));
        
        $manager->persist($changesetIssue1);
        $this->addReference('changesets-issue-cart-bug', $changesetIssue1);

        // Changeset Issue 2: Security vulnerability fix
        $changesetIssue2 = new ChangesetsIssue();
        $changesetIssue2->setChangeset($this->getReference('changeset-security-fix', \App\Entity\Changeset::class));
        $changesetIssue2->setIssue($this->getReference('issue-2', \App\Entity\Issue::class));
        
        $manager->persist($changesetIssue2);
        $this->addReference('changesets-issue-security', $changesetIssue2);

        // Changeset Issue 3: API refactoring related to feature request
        $changesetIssue3 = new ChangesetsIssue();
        $changesetIssue3->setChangeset($this->getReference('changeset-api-refactor', \App\Entity\Changeset::class));
        $changesetIssue3->setIssue($this->getReference('issue-3', \App\Entity\Issue::class));
        
        $manager->persist($changesetIssue3);
        $this->addReference('changesets-issue-api-refactor', $changesetIssue3);

        // Changeset Issue 4: Performance improvements addressing performance issue
        $changesetIssue4 = new ChangesetsIssue();
        $changesetIssue4->setChangeset($this->getReference('changeset-performance-update', \App\Entity\Changeset::class));
        $changesetIssue4->setIssue($this->getReference('issue-4', \App\Entity\Issue::class));
        
        $manager->persist($changesetIssue4);
        $this->addReference('changesets-issue-performance', $changesetIssue4);

        // Changeset Issue 5: Documentation update related to documentation task
        $changesetIssue5 = new ChangesetsIssue();
        $changesetIssue5->setChangeset($this->getReference('changeset-docs-update', \App\Entity\Changeset::class));
        $changesetIssue5->setIssue($this->getReference('issue-5', \App\Entity\Issue::class));
        
        $manager->persist($changesetIssue5);
        $this->addReference('changesets-issue-docs', $changesetIssue5);

        // Changeset Issue 6: Feature branch development linked to feature
        $changesetIssue6 = new ChangesetsIssue();
        $changesetIssue6->setChangeset($this->getReference('changeset-feature-branch', \App\Entity\Changeset::class));
        $changesetIssue6->setIssue($this->getReference('issue-6', \App\Entity\Issue::class));
        
        $manager->persist($changesetIssue6);
        $this->addReference('changesets-issue-feature', $changesetIssue6);

        // Changeset Issue 7: Test suite enhancement addressing testing task
        $changesetIssue7 = new ChangesetsIssue();
        $changesetIssue7->setChangeset($this->getReference('changeset-test-suite', \App\Entity\Changeset::class));
        $changesetIssue7->setIssue($this->getReference('issue-7', \App\Entity\Issue::class));
        
        $manager->persist($changesetIssue7);
        $this->addReference('changesets-issue-testing', $changesetIssue7);

        // Changeset Issue 8: Release preparation addressing multiple issues
        $changesetIssue8 = new ChangesetsIssue();
        $changesetIssue8->setChangeset($this->getReference('changeset-release-prep', \App\Entity\Changeset::class));
        $changesetIssue8->setIssue($this->getReference('issue-8', \App\Entity\Issue::class));
        
        $manager->persist($changesetIssue8);
        $this->addReference('changesets-issue-release-1', $changesetIssue8);

        // Changeset Issue 9: Release preparation addressing another issue
        $changesetIssue9 = new ChangesetsIssue();
        $changesetIssue9->setChangeset($this->getReference('changeset-release-prep', \App\Entity\Changeset::class));
        $changesetIssue9->setIssue($this->getReference('issue-9', \App\Entity\Issue::class));
        
        $manager->persist($changesetIssue9);
        $this->addReference('changesets-issue-release-2', $changesetIssue9);

        // Changeset Issue 10: Main branch update fixing critical issue
        $changesetIssue10 = new ChangesetsIssue();
        $changesetIssue10->setChangeset($this->getReference('changeset-main-branch', \App\Entity\Changeset::class));
        $changesetIssue10->setIssue($this->getReference('issue-10', \App\Entity\Issue::class));
        
        $manager->persist($changesetIssue10);
        $this->addReference('changesets-issue-critical', $changesetIssue10);

        // Changeset Issue 11: Merge feature addressing multiple related issues
        $changesetIssue11 = new ChangesetsIssue();
        $changesetIssue11->setChangeset($this->getReference('changeset-merge-feature', \App\Entity\Changeset::class));
        $changesetIssue11->setIssue($this->getReference('issue-11', \App\Entity\Issue::class));
        
        $manager->persist($changesetIssue11);
        $this->addReference('changesets-issue-merge-1', $changesetIssue11);

        // Changeset Issue 12: Merge feature addressing second related issue
        $changesetIssue12 = new ChangesetsIssue();
        $changesetIssue12->setChangeset($this->getReference('changeset-merge-feature', \App\Entity\Changeset::class));
        $changesetIssue12->setIssue($this->getReference('issue-12', \App\Entity\Issue::class));
        
        $manager->persist($changesetIssue12);
        $this->addReference('changesets-issue-merge-2', $changesetIssue12);

        // Changeset Issue 13: Hotfix merge resolving urgent issue
        $changesetIssue13 = new ChangesetsIssue();
        $changesetIssue13->setChangeset($this->getReference('changeset-hotfix-merge', \App\Entity\Changeset::class));
        $changesetIssue13->setIssue($this->getReference('issue-13', \App\Entity\Issue::class));
        
        $manager->persist($changesetIssue13);
        $this->addReference('changesets-issue-hotfix', $changesetIssue13);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ChangesetFixtures::class,
            IssueFixtures::class,
        ];
    }
}
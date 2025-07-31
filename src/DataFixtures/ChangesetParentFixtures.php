<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ChangesetParent;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ChangesetParentFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Changeset Parent 1: Merge commit with two parents
        // changeset-merge-feature is a merge commit with changeset-feature-branch and changeset-main-branch as parents
        $parent1 = new ChangesetParent();
        $parent1->setChangeset($this->getReference('changeset-merge-feature', \App\Entity\Changeset::class));
        $parent1->setParent($this->getReference('changeset-feature-branch', \App\Entity\Changeset::class));
        
        $manager->persist($parent1);
        $this->addReference('changeset-parent-merge-1', $parent1);

        // Changeset Parent 2: Second parent of the same merge commit
        $parent2 = new ChangesetParent();
        $parent2->setChangeset($this->getReference('changeset-merge-feature', \App\Entity\Changeset::class));
        $parent2->setParent($this->getReference('changeset-main-branch', \App\Entity\Changeset::class));
        
        $manager->persist($parent2);
        $this->addReference('changeset-parent-merge-2', $parent2);

        // Changeset Parent 3: Another merge commit scenario
        // changeset-hotfix-merge is a merge of hotfix into main
        $parent3 = new ChangesetParent();
        $parent3->setChangeset($this->getReference('changeset-hotfix-merge', \App\Entity\Changeset::class));
        $parent3->setParent($this->getReference('changeset-security-fix', \App\Entity\Changeset::class));
        
        $manager->persist($parent3);
        $this->addReference('changeset-parent-hotfix-1', $parent3);

        // Changeset Parent 4: Second parent of hotfix merge
        $parent4 = new ChangesetParent();
        $parent4->setChangeset($this->getReference('changeset-hotfix-merge', \App\Entity\Changeset::class));
        $parent4->setParent($this->getReference('changeset-ecommerce-cart', \App\Entity\Changeset::class));
        
        $manager->persist($parent4);
        $this->addReference('changeset-parent-hotfix-2', $parent4);

        // Changeset Parent 5: Complex merge with multiple branches
        // changeset-release-prep is a merge commit bringing together multiple features
        $parent5 = new ChangesetParent();
        $parent5->setChangeset($this->getReference('changeset-release-prep', \App\Entity\Changeset::class));
        $parent5->setParent($this->getReference('changeset-api-refactor', \App\Entity\Changeset::class));
        
        $manager->persist($parent5);
        $this->addReference('changeset-parent-release-1', $parent5);

        // Changeset Parent 6: Second parent of release preparation merge
        $parent6 = new ChangesetParent();
        $parent6->setChangeset($this->getReference('changeset-release-prep', \App\Entity\Changeset::class));
        $parent6->setParent($this->getReference('changeset-performance-update', \App\Entity\Changeset::class));
        
        $manager->persist($parent6);
        $this->addReference('changeset-parent-release-2', $parent6);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ChangesetFixtures::class,
        ];
    }
}
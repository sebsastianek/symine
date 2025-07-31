<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\IssueRelation;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class IssueRelationFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Relation 1: Mobile setup blocks mobile parent issue
        $relation1 = new IssueRelation();
        $relation1->setIssueFrom($this->getReference('issue-mobile-setup', \App\Entity\Issue::class));
        $relation1->setIssueTo($this->getReference('issue-mobile-parent', \App\Entity\Issue::class));
        $relation1->setRelationType('blocks');
        $relation1->setDelay(null);
        
        $manager->persist($relation1);
        $this->addReference('relation-mobile-setup-blocks-parent', $relation1);

        // Relation 2: Authentication relates to security assessment
        $relation2 = new IssueRelation();
        $relation2->setIssueFrom($this->getReference('issue-auth', \App\Entity\Issue::class));
        $relation2->setIssueTo($this->getReference('issue-security-private', \App\Entity\Issue::class));
        $relation2->setRelationType('relates');
        $relation2->setDelay(null);
        
        $manager->persist($relation2);
        $this->addReference('relation-auth-relates-security', $relation2);

        // Relation 3: Frontend layout blocked by authentication
        $relation3 = new IssueRelation();
        $relation3->setIssueFrom($this->getReference('issue-auth', \App\Entity\Issue::class));
        $relation3->setIssueTo($this->getReference('issue-frontend-layout', \App\Entity\Issue::class));
        $relation3->setRelationType('blocks');
        $relation3->setDelay(null);
        
        $manager->persist($relation3);
        $this->addReference('relation-auth-blocks-frontend', $relation3);

        // Relation 4: Login bug duplicates support login issue
        $relation4 = new IssueRelation();
        $relation4->setIssueFrom($this->getReference('issue-login-bug', \App\Entity\Issue::class));
        $relation4->setIssueTo($this->getReference('issue-support-login', \App\Entity\Issue::class));
        $relation4->setRelationType('duplicates');
        $relation4->setDelay(null);
        
        $manager->persist($relation4);
        $this->addReference('relation-login-bug-duplicates-support', $relation4);

        // Relation 5: API docs follows backend authentication
        $relation5 = new IssueRelation();
        $relation5->setIssueFrom($this->getReference('issue-auth', \App\Entity\Issue::class));
        $relation5->setIssueTo($this->getReference('issue-api-docs', \App\Entity\Issue::class));
        $relation5->setRelationType('follows');
        $relation5->setDelay(3); // 3 days delay
        
        $manager->persist($relation5);
        $this->addReference('relation-auth-follows-docs', $relation5);

        // Relation 6: CRM contacts relates to API docs
        $relation6 = new IssueRelation();
        $relation6->setIssueFrom($this->getReference('issue-crm-contacts', \App\Entity\Issue::class));
        $relation6->setIssueTo($this->getReference('issue-api-docs', \App\Entity\Issue::class));
        $relation6->setRelationType('relates');
        $relation6->setDelay(null);
        
        $manager->persist($relation6);
        $this->addReference('relation-crm-relates-docs', $relation6);

        // Relation 7: Mobile setup precedes other mobile features (reverse of blocks)
        $relation7 = new IssueRelation();
        $relation7->setIssueFrom($this->getReference('issue-mobile-parent', \App\Entity\Issue::class));
        $relation7->setIssueTo($this->getReference('issue-mobile-setup', \App\Entity\Issue::class));
        $relation7->setRelationType('precedes');
        $relation7->setDelay(null);
        
        $manager->persist($relation7);
        $this->addReference('relation-mobile-parent-precedes-setup', $relation7);

        // Relation 8: Payment gateway (on hold) relates to security
        $relation8 = new IssueRelation();
        $relation8->setIssueFrom($this->getReference('issue-payment-gateway', \App\Entity\Issue::class));
        $relation8->setIssueTo($this->getReference('issue-security-private', \App\Entity\Issue::class));
        $relation8->setRelationType('relates');
        $relation8->setDelay(null);
        
        $manager->persist($relation8);
        $this->addReference('relation-payment-relates-security', $relation8);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            IssueFixtures::class,
        ];
    }
}
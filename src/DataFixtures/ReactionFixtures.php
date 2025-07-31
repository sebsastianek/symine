<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Reaction;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ReactionFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Get some issue IDs for reactions (we'll use hardcoded IDs since it's polymorphic)
        $issueIds = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $commentIds = [1, 2, 3, 4, 5];
        
        // Common emoji reactions
        $emojiTypes = ['👍', '👎', '😄', '🎉', '😕', '❤️', '🚀', '👀'];
        
        // Issue reactions - users reacting to issues
        $reaction1 = new Reaction();
        $reaction1->setReactableType('Issue');
        $reaction1->setReactableId($issueIds[0]);
        $reaction1->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $reaction1->setReactionType('👍');
        $reaction1->setCreatedAt(new \DateTime('2024-01-15 10:30:00'));
        $reaction1->setUpdatedAt(new \DateTime('2024-01-15 10:30:00'));
        
        $manager->persist($reaction1);
        $this->addReference('reaction-issue-1-thumbs-up', $reaction1);

        $reaction2 = new Reaction();
        $reaction2->setReactableType('Issue');
        $reaction2->setReactableId($issueIds[0]);
        $reaction2->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $reaction2->setReactionType('🎉');
        $reaction2->setCreatedAt(new \DateTime('2024-01-15 11:15:00'));
        $reaction2->setUpdatedAt(new \DateTime('2024-01-15 11:15:00'));
        
        $manager->persist($reaction2);
        $this->addReference('reaction-issue-1-party', $reaction2);

        $reaction3 = new Reaction();
        $reaction3->setReactableType('Issue');
        $reaction3->setReactableId($issueIds[1]);
        $reaction3->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        $reaction3->setReactionType('😕');
        $reaction3->setCreatedAt(new \DateTime('2024-01-16 09:20:00'));
        $reaction3->setUpdatedAt(new \DateTime('2024-01-16 09:20:00'));
        
        $manager->persist($reaction3);
        $this->addReference('reaction-issue-2-confused', $reaction3);

        $reaction4 = new Reaction();
        $reaction4->setReactableType('Issue');
        $reaction4->setReactableId($issueIds[2]);
        $reaction4->setUser($this->getReference('user-alee', \App\Entity\User::class));
        $reaction4->setReactionType('❤️');
        $reaction4->setCreatedAt(new \DateTime('2024-01-17 14:45:00'));
        $reaction4->setUpdatedAt(new \DateTime('2024-01-17 14:45:00'));
        
        $manager->persist($reaction4);
        $this->addReference('reaction-issue-3-heart', $reaction4);

        $reaction5 = new Reaction();
        $reaction5->setReactableType('Issue');
        $reaction5->setReactableId($issueIds[2]);
        $reaction5->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $reaction5->setReactionType('🚀');
        $reaction5->setCreatedAt(new \DateTime('2024-01-17 15:30:00'));
        $reaction5->setUpdatedAt(new \DateTime('2024-01-17 15:30:00'));
        
        $manager->persist($reaction5);
        $this->addReference('reaction-issue-3-rocket', $reaction5);

        // Comment reactions - users reacting to comments
        $reaction6 = new Reaction();
        $reaction6->setReactableType('Comment');
        $reaction6->setReactableId($commentIds[0]);
        $reaction6->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $reaction6->setReactionType('👍');
        $reaction6->setCreatedAt(new \DateTime('2024-01-18 08:15:00'));
        $reaction6->setUpdatedAt(new \DateTime('2024-01-18 08:15:00'));
        
        $manager->persist($reaction6);
        $this->addReference('reaction-comment-1-thumbs-up', $reaction6);

        $reaction7 = new Reaction();
        $reaction7->setReactableType('Comment');
        $reaction7->setReactableId($commentIds[0]);
        $reaction7->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $reaction7->setReactionType('😄');
        $reaction7->setCreatedAt(new \DateTime('2024-01-18 08:30:00'));
        $reaction7->setUpdatedAt(new \DateTime('2024-01-18 08:30:00'));
        
        $manager->persist($reaction7);
        $this->addReference('reaction-comment-1-laugh', $reaction7);

        $reaction8 = new Reaction();
        $reaction8->setReactableType('Comment');
        $reaction8->setReactableId($commentIds[1]);
        $reaction8->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        $reaction8->setReactionType('👀');
        $reaction8->setCreatedAt(new \DateTime('2024-01-19 12:45:00'));
        $reaction8->setUpdatedAt(new \DateTime('2024-01-19 12:45:00'));
        
        $manager->persist($reaction8);
        $this->addReference('reaction-comment-2-eyes', $reaction8);

        // News reactions - users reacting to news posts
        $reaction9 = new Reaction();
        $reaction9->setReactableType('News');
        $reaction9->setReactableId(1); // First news post
        $reaction9->setUser($this->getReference('user-alee', \App\Entity\User::class));
        $reaction9->setReactionType('🎉');
        $reaction9->setCreatedAt(new \DateTime('2024-01-20 16:20:00'));
        $reaction9->setUpdatedAt(new \DateTime('2024-01-20 16:20:00'));
        
        $manager->persist($reaction9);
        $this->addReference('reaction-news-1-party', $reaction9);

        $reaction10 = new Reaction();
        $reaction10->setReactableType('News');
        $reaction10->setReactableId(1);
        $reaction10->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $reaction10->setReactionType('👍');
        $reaction10->setCreatedAt(new \DateTime('2024-01-20 17:10:00'));
        $reaction10->setUpdatedAt(new \DateTime('2024-01-20 17:10:00'));
        
        $manager->persist($reaction10);
        $this->addReference('reaction-news-1-thumbs-up', $reaction10);

        // Message reactions - users reacting to forum messages
        $reaction11 = new Reaction();
        $reaction11->setReactableType('Message');
        $reaction11->setReactableId(1); // First message
        $reaction11->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $reaction11->setReactionType('❤️');
        $reaction11->setCreatedAt(new \DateTime('2024-01-22 09:30:00'));
        $reaction11->setUpdatedAt(new \DateTime('2024-01-22 09:30:00'));
        
        $manager->persist($reaction11);
        $this->addReference('reaction-message-1-heart', $reaction11);

        $reaction12 = new Reaction();
        $reaction12->setReactableType('Message');
        $reaction12->setReactableId(2); // Second message
        $reaction12->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $reaction12->setReactionType('🚀');
        $reaction12->setCreatedAt(new \DateTime('2024-01-22 14:15:00'));
        $reaction12->setUpdatedAt(new \DateTime('2024-01-22 14:15:00'));
        
        $manager->persist($reaction12);
        $this->addReference('reaction-message-2-rocket', $reaction12);

        // Multiple reactions from different users on the same item
        $reaction13 = new Reaction();
        $reaction13->setReactableType('Issue');
        $reaction13->setReactableId($issueIds[3]);
        $reaction13->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        $reaction13->setReactionType('👍');
        $reaction13->setCreatedAt(new \DateTime('2024-01-23 10:00:00'));
        $reaction13->setUpdatedAt(new \DateTime('2024-01-23 10:00:00'));
        
        $manager->persist($reaction13);
        $this->addReference('reaction-issue-4-thumbs-up-1', $reaction13);

        $reaction14 = new Reaction();
        $reaction14->setReactableType('Issue');
        $reaction14->setReactableId($issueIds[3]);
        $reaction14->setUser($this->getReference('user-alee', \App\Entity\User::class));
        $reaction14->setReactionType('👍');
        $reaction14->setCreatedAt(new \DateTime('2024-01-23 10:15:00'));
        $reaction14->setUpdatedAt(new \DateTime('2024-01-23 10:15:00'));
        
        $manager->persist($reaction14);
        $this->addReference('reaction-issue-4-thumbs-up-2', $reaction14);

        $reaction15 = new Reaction();
        $reaction15->setReactableType('Issue');
        $reaction15->setReactableId($issueIds[3]);
        $reaction15->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $reaction15->setReactionType('👍');
        $reaction15->setCreatedAt(new \DateTime('2024-01-23 11:30:00'));
        $reaction15->setUpdatedAt(new \DateTime('2024-01-23 11:30:00'));
        
        $manager->persist($reaction15);
        $this->addReference('reaction-issue-4-thumbs-up-3', $reaction15);

        // Negative reaction example
        $reaction16 = new Reaction();
        $reaction16->setReactableType('Issue');
        $reaction16->setReactableId($issueIds[4]);
        $reaction16->setUser($this->getReference('user-sgarcia', \App\Entity\User::class));
        $reaction16->setReactionType('👎');
        $reaction16->setCreatedAt(new \DateTime('2024-01-24 13:20:00'));
        $reaction16->setUpdatedAt(new \DateTime('2024-01-24 13:20:00'));
        
        $manager->persist($reaction16);
        $this->addReference('reaction-issue-5-thumbs-down', $reaction16);

        // Wiki page reaction
        $reaction17 = new Reaction();
        $reaction17->setReactableType('WikiPage');
        $reaction17->setReactableId(1); // First wiki page
        $reaction17->setUser($this->getReference('user-jsmith', \App\Entity\User::class));
        $reaction17->setReactionType('👀');
        $reaction17->setCreatedAt(new \DateTime('2024-01-25 15:45:00'));
        $reaction17->setUpdatedAt(new \DateTime('2024-01-25 15:45:00'));
        
        $manager->persist($reaction17);
        $this->addReference('reaction-wiki-1-eyes', $reaction17);

        // Document reaction
        $reaction18 = new Reaction();
        $reaction18->setReactableType('Document');
        $reaction18->setReactableId(1); // First document
        $reaction18->setUser($this->getReference('user-dbrown', \App\Entity\User::class));
        $reaction18->setReactionType('👍');
        $reaction18->setCreatedAt(new \DateTime('2024-01-26 11:30:00'));
        $reaction18->setUpdatedAt(new \DateTime('2024-01-26 11:30:00'));
        
        $manager->persist($reaction18);
        $this->addReference('reaction-document-1-thumbs-up', $reaction18);

        // Mixed reactions on a popular issue
        $reaction19 = new Reaction();
        $reaction19->setReactableType('Issue');
        $reaction19->setReactableId($issueIds[5]);
        $reaction19->setUser($this->getReference('user-alee', \App\Entity\User::class));
        $reaction19->setReactionType('🎉');
        $reaction19->setCreatedAt(new \DateTime('2024-01-27 09:15:00'));
        $reaction19->setUpdatedAt(new \DateTime('2024-01-27 09:15:00'));
        
        $manager->persist($reaction19);
        $this->addReference('reaction-issue-6-party', $reaction19);

        $reaction20 = new Reaction();
        $reaction20->setReactableType('Issue');
        $reaction20->setReactableId($issueIds[5]);
        $reaction20->setUser($this->getReference('user-mjohnson', \App\Entity\User::class));
        $reaction20->setReactionType('❤️');
        $reaction20->setCreatedAt(new \DateTime('2024-01-27 10:20:00'));
        $reaction20->setUpdatedAt(new \DateTime('2024-01-27 10:20:00'));
        
        $manager->persist($reaction20);
        $this->addReference('reaction-issue-6-heart', $reaction20);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            IssueFixtures::class,
            CommentFixtures::class,
            NewsFixtures::class,
            BoardFixtures::class,
        ];
    }
}
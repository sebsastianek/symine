<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Comment;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class CommentFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Comment 1: Update on authentication issue
        $comment1 = new Comment();
        $comment1->setCommentedType('Issue');
        $comment1->setCommentedId($this->getReference('issue-auth', \App\Entity\Issue::class)->getId());
        $comment1->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $comment1->setContent('Started implementation of JWT-based authentication. Basic structure is in place and working with test cases.');
        $comment1->setCreatedOn(new \DateTime('2024-01-12 15:30:00'));
        $comment1->setUpdatedOn(new \DateTime('2024-01-12 15:30:00'));
        
        $manager->persist($comment1);
        $this->addReference('comment-auth-progress', $comment1);

        // Comment 2: Response to authentication update
        $comment2 = new Comment();
        $comment2->setCommentedType('Issue');
        $comment2->setCommentedId($this->getReference('issue-auth', \App\Entity\Issue::class)->getId());
        $comment2->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $comment2->setContent('Great progress! Please make sure to include proper error handling for token expiration and refresh scenarios.');
        $comment2->setCreatedOn(new \DateTime('2024-01-13 09:15:00'));
        $comment2->setUpdatedOn(new \DateTime('2024-01-13 09:15:00'));
        
        $manager->persist($comment2);
        $this->addReference('comment-auth-feedback', $comment2);

        // Comment 3: Login bug report details
        $comment3 = new Comment();
        $comment3->setCommentedType('Issue');
        $comment3->setCommentedId($this->getReference('issue-login-bug', \App\Entity\Issue::class)->getId());
        $comment3->setAuthor($this->getReference('user-dbrown', \App\Entity\User::class));
        $comment3->setContent('Reproduced the issue on Chrome 120 and Firefox 121. The validation seems to fail when users enter spaces before or after their email address. Attached screenshot showing the error.');
        $comment3->setCreatedOn(new \DateTime('2024-02-14 11:45:00'));
        $comment3->setUpdatedOn(new \DateTime('2024-02-14 11:45:00'));
        
        $manager->persist($comment3);
        $this->addReference('comment-login-bug-details', $comment3);

        // Comment 4: Frontend layout design discussion
        $comment4 = new Comment();
        $comment4->setCommentedType('Issue');
        $comment4->setCommentedId($this->getReference('issue-frontend-layout', \App\Entity\Issue::class)->getId());
        $comment4->setAuthor($this->getReference('user-sgarcia', \App\Entity\User::class));
        $comment4->setContent('Completed the initial wireframes. The responsive design uses CSS Grid for main layout and Flexbox for components. Mobile-first approach ensures good performance on all devices.');
        $comment4->setCreatedOn(new \DateTime('2024-02-03 14:20:00'));
        $comment4->setUpdatedOn(new \DateTime('2024-02-03 14:20:00'));
        
        $manager->persist($comment4);
        $this->addReference('comment-frontend-design', $comment4);

        // Comment 5: Mobile development question
        $comment5 = new Comment();
        $comment5->setCommentedType('Issue');
        $comment5->setCommentedId($this->getReference('issue-mobile-setup', \App\Entity\Issue::class)->getId());
        $comment5->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $comment5->setContent('Project structure is complete. Should we proceed with implementing push notifications in this sprint or defer to next release?');
        $comment5->setCreatedOn(new \DateTime('2024-02-08 16:45:00'));
        $comment5->setUpdatedOn(new \DateTime('2024-02-08 16:45:00'));
        
        $manager->persist($comment5);
        $this->addReference('comment-mobile-question', $comment5);

        // Comment 6: Mobile development answer
        $comment6 = new Comment();
        $comment6->setCommentedType('Issue');
        $comment6->setCommentedId($this->getReference('issue-mobile-setup', \App\Entity\Issue::class)->getId());
        $comment6->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $comment6->setContent('Let\'s defer push notifications to the next release. Focus on core functionality and user authentication integration first.');
        $comment6->setCreatedOn(new \DateTime('2024-02-09 10:30:00'));
        $comment6->setUpdatedOn(new \DateTime('2024-02-09 10:30:00'));
        
        $manager->persist($comment6);
        $this->addReference('comment-mobile-answer', $comment6);

        // Comment 7: CRM contacts specification
        $comment7 = new Comment();
        $comment7->setCommentedType('Issue');
        $comment7->setCommentedId($this->getReference('issue-crm-contacts', \App\Entity\Issue::class)->getId());
        $comment7->setAuthor($this->getReference('user-alee', \App\Entity\User::class));
        $comment7->setContent('Please ensure the contact management system supports custom fields and tags for categorizing contacts. We need to track lead source and follow-up dates.');
        $comment7->setCreatedOn(new \DateTime('2024-02-26 13:30:00'));
        $comment7->setUpdatedOn(new \DateTime('2024-02-26 13:30:00'));
        
        $manager->persist($comment7);
        $this->addReference('comment-crm-requirements', $comment7);

        // Comment 8: API documentation progress
        $comment8 = new Comment();
        $comment8->setCommentedType('Issue');
        $comment8->setCommentedId($this->getReference('issue-api-docs', \App\Entity\Issue::class)->getId());
        $comment8->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $comment8->setContent('Documentation is 70% complete. All authentication endpoints are documented with examples. Working on user management and project endpoints next.');
        $comment8->setCreatedOn(new \DateTime('2024-03-01 11:20:00'));
        $comment8->setUpdatedOn(new \DateTime('2024-03-01 11:20:00'));
        
        $manager->persist($comment8);
        $this->addReference('comment-api-docs-progress', $comment8);

        // Comment 9: Support login investigation
        $comment9 = new Comment();
        $comment9->setCommentedType('Issue');
        $comment9->setCommentedId($this->getReference('issue-support-login', \App\Entity\Issue::class)->getId());
        $comment9->setAuthor($this->getReference('user-dbrown', \App\Entity\User::class));
        $comment9->setContent('Checked the user account - it\'s active and not locked. The issue seems related to browser cache. Provided customer with cache clearing instructions.');
        $comment9->setCreatedOn(new \DateTime('2024-02-28 16:30:00'));
        $comment9->setUpdatedOn(new \DateTime('2024-02-28 16:30:00'));
        
        $manager->persist($comment9);
        $this->addReference('comment-support-investigation', $comment9);

        // Comment 10: Security assessment note
        $comment10 = new Comment();
        $comment10->setCommentedType('Issue');
        $comment10->setCommentedId($this->getReference('issue-security-private', \App\Entity\Issue::class)->getId());
        $comment10->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $comment10->setContent('Preliminary assessment completed. Found 2 medium-priority vulnerabilities. Detailed report is attached. Will schedule follow-up meeting to discuss remediation.');
        $comment10->setCreatedOn(new \DateTime('2024-02-24 17:00:00'));
        $comment10->setUpdatedOn(new \DateTime('2024-02-24 17:00:00'));
        
        $manager->persist($comment10);
        $this->addReference('comment-security-assessment', $comment10);

        // Comment 11: Payment gateway status
        $comment11 = new Comment();
        $comment11->setCommentedType('Issue');
        $comment11->setCommentedId($this->getReference('issue-payment-gateway', \App\Entity\Issue::class)->getId());
        $comment11->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $comment11->setContent('Still waiting for Stripe API access approval. In the meantime, I\'ve created mock interfaces and test implementations to unblock other development work.');
        $comment11->setCreatedOn(new \DateTime('2024-03-10 14:15:00'));
        $comment11->setUpdatedOn(new \DateTime('2024-03-10 14:15:00'));
        
        $manager->persist($comment11);
        $this->addReference('comment-payment-status', $comment11);

        // Comment 12: News comment
        $comment12 = new Comment();
        $comment12->setCommentedType('News');
        $comment12->setCommentedId($this->getReference('news-backend-release', \App\Entity\News::class)->getId());
        $comment12->setAuthor($this->getReference('user-sgarcia', \App\Entity\User::class));
        $comment12->setContent('Excellent work on the API release! The documentation is very helpful for frontend integration.');
        $comment12->setCreatedOn(new \DateTime('2024-04-01 10:30:00'));
        $comment12->setUpdatedOn(new \DateTime('2024-04-01 10:30:00'));
        
        $manager->persist($comment12);
        $this->addReference('comment-news-feedback', $comment12);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            IssueFixtures::class,
            NewsFixtures::class,
        ];
    }
}
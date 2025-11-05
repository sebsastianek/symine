<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Message;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class MessageFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Message 1: Development Discussion - Main thread
        $message1 = new Message();
        $message1->setBoard($this->getReference('board-development', \App\Entity\Board::class));
        $message1->setParent(null);
        $message1->setSubject('Best practices for API design');
        $message1->setContent('Hi everyone,

I\'d like to start a discussion about API design best practices for our new microservices architecture. What are your thoughts on the following topics:

1. RESTful URL structure
2. Error handling and status codes
3. Authentication and authorization
4. Rate limiting strategies
5. API versioning approaches

Looking forward to your insights!

Best regards,
John');
        $message1->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $message1->setRepliesCount(3);
        $message1->setCreatedOn(new \DateTime('2024-01-15 09:30:00'));
        $message1->setUpdatedOn(new \DateTime('2024-01-16 14:20:00'));
        $message1->setLocked(0);
        $message1->setSticky(1); // Sticky post
        
        $manager->persist($message1);
        $this->addReference('message-api-design', $message1);

        // Message 2: Reply to API design discussion
        $message2 = new Message();
        $message2->setBoard($this->getReference('board-development', \App\Entity\Board::class));
        $message2->setParent($message1);
        $message2->setSubject('Re: Best practices for API design');
        $message2->setContent('Great topic, John!

For RESTful URL structure, I recommend:
- Use nouns instead of verbs in URLs
- Keep URLs consistent and predictable
- Use HTTP methods properly (GET, POST, PUT, DELETE)
- Implement proper pagination with limit/offset parameters

For error handling:
- Always return appropriate HTTP status codes
- Include error details in response body
- Use consistent error response format
- Log errors for debugging

Mike');
        $message2->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $message2->setRepliesCount(0);
        $message2->setCreatedOn(new \DateTime('2024-01-15 11:45:00'));
        $message2->setUpdatedOn(new \DateTime('2024-01-15 11:45:00'));
        $message2->setLocked(0);
        $message2->setSticky(0);
        
        $manager->persist($message2);
        $this->addReference('message-api-reply-1', $message2);

        // Update parent message last reply
        $message1->setLastReply($message2);

        // Message 3: Another reply to API design
        $message3 = new Message();
        $message3->setBoard($this->getReference('board-development', \App\Entity\Board::class));
        $message3->setParent($message1);
        $message3->setSubject('Re: Best practices for API design');
        $message3->setContent('Adding to Mike\'s points:

For authentication, I suggest:
- Use JWT tokens for stateless authentication
- Implement refresh token mechanism
- Use HTTPS for all API endpoints
- Consider OAuth 2.0 for third-party integrations

For rate limiting:
- Implement sliding window or token bucket algorithms
- Return rate limit headers (X-RateLimit-Limit, X-RateLimit-Remaining)
- Use different limits for different user tiers
- Provide clear error messages when limits are exceeded

Sarah');
        $message3->setAuthor($this->getReference('user-sgarcia', \App\Entity\User::class));
        $message3->setRepliesCount(0);
        $message3->setCreatedOn(new \DateTime('2024-01-16 14:20:00'));
        $message3->setUpdatedOn(new \DateTime('2024-01-16 14:20:00'));
        $message3->setLocked(0);
        $message3->setSticky(0);
        
        $manager->persist($message3);
        $this->addReference('message-api-reply-2', $message3);

        // Update parent message last reply again
        $message1->setLastReply($message3);

        // Message 4: New thread in General Discussion
        $message4 = new Message();
        $message4->setBoard($this->getReference('board-general', \App\Entity\Board::class));
        $message4->setParent(null);
        $message4->setSubject('Welcome new team members!');
        $message4->setContent('Hi everyone,

I wanted to welcome our new team members who joined us this month:
- Alice Lee (Frontend Developer)
- David Brown (QA Engineer)

Please join me in welcoming them to the team. Feel free to introduce yourselves and share what you\'re working on.

Welcome aboard!
Admin');
        $message4->setAuthor($this->getReference('user-admin', \App\Entity\User::class));
        $message4->setRepliesCount(2);
        $message4->setCreatedOn(new \DateTime('2024-02-01 10:00:00'));
        $message4->setUpdatedOn(new \DateTime('2024-02-01 16:30:00'));
        $message4->setLocked(0);
        $message4->setSticky(0);
        
        $manager->persist($message4);
        $this->addReference('message-welcome-2', $message4);

        // Message 5: Reply to welcome message from Alice
        $message5 = new Message();
        $message5->setBoard($this->getReference('board-general', \App\Entity\Board::class));
        $message5->setParent($message4);
        $message5->setSubject('Re: Welcome new team members!');
        $message5->setContent('Thank you for the warm welcome!

I\'m excited to be part of the team. I\'ll be working primarily on the React frontend for the e-commerce platform. I have 5 years of experience with React, TypeScript, and modern frontend development.

Looking forward to collaborating with everyone!

Alice');
        $message5->setAuthor($this->getReference('user-alee', \App\Entity\User::class));
        $message5->setRepliesCount(0);
        $message5->setCreatedOn(new \DateTime('2024-02-01 14:30:00'));
        $message5->setUpdatedOn(new \DateTime('2024-02-01 14:30:00'));
        $message5->setLocked(0);
        $message5->setSticky(0);
        
        $manager->persist($message5);
        $this->addReference('message-welcome-reply-alice', $message5);

        // Message 6: Reply to welcome message from David
        $message6 = new Message();
        $message6->setBoard($this->getReference('board-general', \App\Entity\Board::class));
        $message6->setParent($message4);
        $message6->setSubject('Re: Welcome new team members!');
        $message6->setContent('Thanks everyone!

Great to be here. I\'ll be focusing on QA and testing automation. I\'m particularly interested in setting up comprehensive test suites and improving our CI/CD pipeline.

I bring experience with Selenium, Jest, PHPUnit, and various testing frameworks. Happy to share knowledge and learn from the team.

David');
        $message6->setAuthor($this->getReference('user-dbrown', \App\Entity\User::class));
        $message6->setRepliesCount(0);
        $message6->setCreatedOn(new \DateTime('2024-02-01 16:30:00'));
        $message6->setUpdatedOn(new \DateTime('2024-02-01 16:30:00'));
        $message6->setLocked(0);
        $message6->setSticky(0);
        
        $manager->persist($message6);
        $this->addReference('message-welcome-reply-david', $message6);

        // Update welcome message last reply
        $message4->setLastReply($message6);

        // Message 7: Support request in Support board
        $message7 = new Message();
        $message7->setBoard($this->getReference('board-support', \App\Entity\Board::class));
        $message7->setParent(null);
        $message7->setSubject('Database connection issues in staging');
        $message7->setContent('Hi team,

We\'re experiencing intermittent database connection issues in our staging environment. The application works fine for a few hours, then starts throwing connection timeout errors.

Error details:
- MySQL connection timeout after 30 seconds
- Occurs during peak usage simulation
- Local development environment works fine
- Production environment is stable

Has anyone seen similar issues? Any suggestions for debugging?

Thanks,
Mike');
        $message7->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $message7->setRepliesCount(1);
        $message7->setCreatedOn(new \DateTime('2024-02-02 09:15:00'));
        $message7->setUpdatedOn(new \DateTime('2024-02-02 11:30:00'));
        $message7->setLocked(0);
        $message7->setSticky(0);
        
        $manager->persist($message7);
        $this->addReference('message-db-issue', $message7);

        // Message 8: Reply to database issue
        $message8 = new Message();
        $message8->setBoard($this->getReference('board-support', \App\Entity\Board::class));
        $message8->setParent($message7);
        $message8->setSubject('Re: Database connection issues in staging');
        $message8->setContent('Hi Mike,

I\'ve seen this before. A few things to check:

1. Connection pool settings - make sure you have enough connections configured
2. Check for connection leaks - ensure all connections are properly closed
3. Monitor slow queries that might be holding connections too long
4. Review the MySQL configuration (max_connections, wait_timeout)

I can help you analyze the database logs if needed. Let\'s schedule a call to debug this together.

Sarah');
        $message8->setAuthor($this->getReference('user-sgarcia', \App\Entity\User::class));
        $message8->setRepliesCount(0);
        $message8->setCreatedOn(new \DateTime('2024-02-02 11:30:00'));
        $message8->setUpdatedOn(new \DateTime('2024-02-02 11:30:00'));
        $message8->setLocked(0);
        $message8->setSticky(0);
        
        $manager->persist($message8);
        $this->addReference('message-db-issue-reply', $message8);

        // Update database issue message last reply
        $message7->setLastReply($message8);

        // Message 9: Announcement in Announcements board
        $message9 = new Message();
        $message9->setBoard($this->getReference('board-general', \App\Entity\Board::class));
        $message9->setParent(null);
        $message9->setSubject('System maintenance scheduled for this weekend');
        $message9->setContent('Dear team,

We have scheduled system maintenance for this weekend:

**Date:** Saturday, February 3rd, 2024
**Time:** 2:00 AM - 6:00 AM UTC
**Duration:** Approximately 4 hours

**What will be affected:**
- Staging and development environments will be unavailable
- Production environment will remain operational
- VPN access may be intermittently disrupted

**What we\'re doing:**
- Database server updates and security patches
- Application server maintenance
- Network infrastructure improvements
- Backup system verification

**Action required:**
- Save your work before 2:00 AM UTC
- Plan your weekend work accordingly
- Production deployments are frozen until Sunday

If you have any urgent issues during the maintenance window, please contact the on-call engineer.

Thank you for your cooperation.

IT Admin');
        $message9->setAuthor($this->getReference('user-admin', \App\Entity\User::class));
        $message9->setRepliesCount(0);
        $message9->setCreatedOn(new \DateTime('2024-02-01 17:00:00'));
        $message9->setUpdatedOn(new \DateTime('2024-02-01 17:00:00'));
        $message9->setLocked(1); // Locked - no replies allowed
        $message9->setSticky(1); // Sticky announcement
        
        $manager->persist($message9);
        $this->addReference('message-maintenance', $message9);

        // Message 10: Code review discussion
        $message10 = new Message();
        $message10->setBoard($this->getReference('board-development', \App\Entity\Board::class));
        $message10->setParent(null);
        $message10->setSubject('Code review guidelines and checklist');
        $message10->setContent('Team,

I\'ve created a code review checklist to standardize our review process. Please review and provide feedback:

**Code Quality:**
- [ ] Code follows PSR-12 coding standards
- [ ] Functions are small and focused
- [ ] Variable and function names are descriptive
- [ ] No code duplication
- [ ] Proper error handling

**Security:**
- [ ] Input validation and sanitization
- [ ] No hardcoded credentials
- [ ] Proper authentication/authorization
- [ ] SQL injection prevention
- [ ] XSS protection

**Testing:**
- [ ] Unit tests cover new functionality
- [ ] Integration tests where appropriate
- [ ] All tests pass
- [ ] Edge cases covered

**Documentation:**
- [ ] Code is well-commented
- [ ] API documentation updated
- [ ] README updated if needed

Should we add this to our development wiki?

John');
        $message10->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $message10->setRepliesCount(0);
        $message10->setCreatedOn(new \DateTime('2024-02-03 13:45:00'));
        $message10->setUpdatedOn(new \DateTime('2024-02-03 13:45:00'));
        $message10->setLocked(0);
        $message10->setSticky(0);
        
        $manager->persist($message10);
        $this->addReference('message-code-review', $message10);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            BoardFixtures::class,
            UserFixtures::class,
        ];
    }
}
<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Board;
use App\Entity\Message;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class BoardFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Board 1: General discussion for E-Commerce
        $board1 = new Board();
        $board1->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $board1->setName('General Discussion');
        $board1->setDescription('General discussion about the e-commerce platform project');
        $board1->setPosition(1);
        $board1->setTopicsCount(0);
        $board1->setMessagesCount(0);
        $board1->setParent(null);
        
        $manager->persist($board1);
        $this->addReference('board-general', $board1);

        // Board 2: Development board
        $board2 = new Board();
        $board2->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $board2->setName('Development');
        $board2->setDescription('Technical discussions and development-related topics');
        $board2->setPosition(2);
        $board2->setTopicsCount(0);
        $board2->setMessagesCount(0);
        $board2->setParent(null);
        
        $manager->persist($board2);
        $this->addReference('board-development', $board2);

        // Board 3: Support board
        $board3 = new Board();
        $board3->setProject($this->getReference('project-ecommerce', \App\Entity\Project::class));
        $board3->setName('Support');
        $board3->setDescription('User support and help topics');
        $board3->setPosition(3);
        $board3->setTopicsCount(0);
        $board3->setMessagesCount(0);
        $board3->setParent(null);
        
        $manager->persist($board3);
        $this->addReference('board-support', $board3);

        // Message 1: Welcome message
        $message1 = new Message();
        $message1->setBoard($board1);
        $message1->setParent(null);
        $message1->setSubject('Welcome to the E-Commerce Platform Project');
        $message1->setContent('Welcome everyone to our e-commerce platform project discussion board. Please use this space to share ideas, ask questions, and collaborate effectively.');
        $message1->setAuthor($this->getReference('user-jsmith', \App\Entity\User::class));
        $message1->setRepliesCount(2);
        $message1->setCreatedOn(new \DateTime('2024-01-02 12:00:00'));
        $message1->setUpdatedOn(new \DateTime('2024-01-03 10:30:00'));
        $message1->setLocked(0);
        $message1->setSticky(1);
        
        $manager->persist($message1);
        $this->addReference('message-welcome', $message1);
        $board1->setLastMessage($message1);

        // Message 2: Reply to welcome
        $message2 = new Message();
        $message2->setBoard($board1);
        $message2->setParent($message1);
        $message2->setSubject('Re: Welcome to the E-Commerce Platform Project');
        $message2->setContent('Thank you for setting up this board! Looking forward to working with everyone on this project.');
        $message2->setAuthor($this->getReference('user-mjohnson', \App\Entity\User::class));
        $message2->setRepliesCount(0);
        $message2->setCreatedOn(new \DateTime('2024-01-03 09:15:00'));
        $message2->setUpdatedOn(new \DateTime('2024-01-03 09:15:00'));
        $message2->setLocked(0);
        $message2->setSticky(0);
        
        $manager->persist($message2);
        $message1->setLastReply($message2);

        // Message 3: Technical discussion
        $message3 = new Message();
        $message3->setBoard($board2);
        $message3->setParent(null);
        $message3->setSubject('Technology Stack Discussion');
        $message3->setContent('Let\'s discuss the technology stack for our project. I propose using React for frontend, Node.js for backend, and PostgreSQL for database. What are your thoughts?');
        $message3->setAuthor($this->getReference('user-sgarcia', \App\Entity\User::class));
        $message3->setRepliesCount(1);
        $message3->setCreatedOn(new \DateTime('2024-01-05 14:20:00'));
        $message3->setUpdatedOn(new \DateTime('2024-01-06 11:45:00'));
        $message3->setLocked(0);
        $message3->setSticky(0);
        
        $manager->persist($message3);
        $this->addReference('message-tech-stack', $message3);
        $board2->setLastMessage($message3);

        // Message 4: Support question
        $message4 = new Message();
        $message4->setBoard($board3);
        $message4->setParent(null);
        $message4->setSubject('How to access development environment?');
        $message4->setContent('I\'m new to the team and need help accessing the development environment. Can someone provide the setup instructions?');
        $message4->setAuthor($this->getReference('user-dbrown', \App\Entity\User::class));
        $message4->setRepliesCount(0);
        $message4->setCreatedOn(new \DateTime('2024-02-12 16:30:00'));
        $message4->setUpdatedOn(new \DateTime('2024-02-12 16:30:00'));
        $message4->setLocked(0);
        $message4->setSticky(0);
        
        $manager->persist($message4);
        $this->addReference('message-support', $message4);
        $board3->setLastMessage($message4);

        // Update board counters
        $board1->setTopicsCount(1);
        $board1->setMessagesCount(2);
        $board2->setTopicsCount(1);
        $board2->setMessagesCount(1);
        $board3->setTopicsCount(1);
        $board3->setMessagesCount(1);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            ProjectFixtures::class,
        ];
    }
}
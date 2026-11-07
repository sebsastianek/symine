<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'messages')]
#[ORM\Index(name: 'messages_board_id', columns: ['board_id'])]
#[ORM\Index(name: 'messages_parent_id', columns: ['parent_id'])]
#[ORM\Index(name: 'index_messages_on_last_reply_id', columns: ['last_reply_id'])]
#[ORM\Index(name: 'index_messages_on_author_id', columns: ['author_id'])]
#[ORM\Index(name: 'index_messages_on_created_on', columns: ['created_on'])]
class Message
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Board::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Board $board;

    #[ORM\ManyToOne(targetEntity: Message::class)]
    public ?Message $parent = null;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $subject = '';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $content = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    public ?User $author = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $repliesCount = 0;

    #[ORM\ManyToOne(targetEntity: Message::class)]
    public ?Message $lastReply = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdOn;

    #[ORM\Column]
    public \DateTimeImmutable $updatedOn;

    #[ORM\Column(nullable: true, options: ['default' => false])]
    public ?bool $locked = false;

    #[ORM\Column(nullable: true, options: ['default' => 0])]
    public ?int $sticky = 0;

    public function __construct()
    {
        $this->createdOn = $this->updatedOn = new \DateTimeImmutable();
    }

    public function isSticky(): bool
    {
        return 1 === $this->sticky;
    }
}

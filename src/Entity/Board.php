<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'boards')]
#[ORM\Index(name: 'boards_project_id', columns: ['project_id'])]
#[ORM\Index(name: 'index_boards_on_last_message_id', columns: ['last_message_id'])]
class Board implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Project $project;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $name = '';

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $description = null;

    #[ORM\Column(nullable: true)]
    public ?int $position = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $topicsCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    public int $messagesCount = 0;

    #[ORM\ManyToOne(targetEntity: Message::class)]
    public ?Message $lastMessage = null;

    #[ORM\ManyToOne(targetEntity: Board::class)]
    public ?Board $parent = null;

    public function __toString(): string
    {
        return $this->name;
    }
}

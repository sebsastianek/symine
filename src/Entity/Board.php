<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\BoardRepository;

/**
 * Board.
 * Table: boards
 */
#[ORM\Entity(repositoryClass: BoardRepository::class)]
#[ORM\Table(name: 'boards')]
class Board
{
    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->messages = new ArrayCollection();
    }
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property project
     */
    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'boards')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false)]
    private Project $project;

    /**
     * Property name
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $name = '';

    /**
     * Property description
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $description = NULL;

    /**
     * Property position
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $position = NULL;

    /**
     * Property topicsCount
     */
    #[ORM\Column(type: 'integer', options: ['default' => '0'])]
    private int $topicsCount = 0;

    /**
     * Property messagesCount
     */
    #[ORM\Column(type: 'integer', options: ['default' => '0'])]
    private int $messagesCount = 0;

    /**
     * Property lastMessage
     */
    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(name: 'last_message_id', referencedColumnName: 'id', nullable: true)]
    private ?Message $lastMessage = NULL;

    /**
     * Property parent
     */
    #[ORM\ManyToOne(targetEntity: Board::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true)]
    private ?Board $parent = NULL;

    /**
     * Property children
     */
    #[ORM\OneToMany(targetEntity: Board::class, mappedBy: 'parent')]
    private Collection $children;

    /**
     * Property messages
     */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'board')]
    private Collection $messages;

    /**
     * Getter for id
     */
    public function getId(): int    {
        return $this->id;
    }

    /**
     * Setter for id
     */
    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Getter for project
     */
    public function getProject(): Project
    {
        return $this->project;
    }

    /**
     * Setter for project
     */
    public function setProject(Project $project): static
    {
        $this->project = $project;
        return $this;
    }

    /**
     * Getter for name
     */
    public function getName(): string    {
        return $this->name;
    }

    /**
     * Setter for name
     */
    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Getter for description
     */
    public function getDescription(): ?string    {
        return $this->description;
    }

    /**
     * Setter for description
     */
    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Getter for position
     */
    public function getPosition(): ?int    {
        return $this->position;
    }

    /**
     * Setter for position
     */
    public function setPosition(?int $position): static
    {
        $this->position = $position;
        return $this;
    }

    /**
     * Getter for topicsCount
     */
    public function getTopicsCount(): int    {
        return $this->topicsCount;
    }

    /**
     * Setter for topicsCount
     */
    public function setTopicsCount(int $topicsCount): static
    {
        $this->topicsCount = $topicsCount;
        return $this;
    }

    /**
     * Getter for messagesCount
     */
    public function getMessagesCount(): int    {
        return $this->messagesCount;
    }

    /**
     * Setter for messagesCount
     */
    public function setMessagesCount(int $messagesCount): static
    {
        $this->messagesCount = $messagesCount;
        return $this;
    }

    /**
     * Getter for lastMessage
     */
    public function getLastMessage(): ?Message
    {
        return $this->lastMessage;
    }

    /**
     * Setter for lastMessage
     */
    public function setLastMessage(?Message $lastMessage): static
    {
        $this->lastMessage = $lastMessage;
        return $this;
    }

    /**
     * Getter for parent
     */
    public function getParent(): ?Board
    {
        return $this->parent;
    }

    /**
     * Setter for parent
     */
    public function setParent(?Board $parent): static
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * Getter for children
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    /**
     * Add child board
     */
    public function addChild(Board $child): static
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }
        return $this;
    }

    /**
     * Remove child board
     */
    public function removeChild(Board $child): static
    {
        if ($this->children->removeElement($child)) {
            if ($child->getParent() === $this) {
                $child->setParent(null);
            }
        }
        return $this;
    }

    /**
     * Getter for messages
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    /**
     * Add message
     */
    public function addMessage(Message $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setBoard($this);
        }
        return $this;
    }

    /**
     * Remove message
     */
    public function removeMessage(Message $message): static
    {
        if ($this->messages->removeElement($message)) {
            if ($message->getBoard() === $this) {
                $message->setBoard(null);
            }
        }
        return $this;
    }

}

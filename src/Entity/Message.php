<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\MessageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

/**
 * Message.
 * Table: messages
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'messages')]
class Message
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property board
     */
    #[ORM\ManyToOne(targetEntity: Board::class)]
    #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', nullable: false)]
    private Board $board;

    /**
     * Property parent
     */
    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true)]
    private ?Message $parent = null;

    /**
     * Property subject
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $subject = '';

    /**
     * Property content
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $content = NULL;

    /**
     * Property author
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true)]
    private ?User $author = null;

    /**
     * Property repliesCount
     */
    #[ORM\Column(type: 'integer', options: ['default' => '0'])]
    private int $repliesCount = 0;

    /**
     * Property lastReply
     */
    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(name: 'last_reply_id', referencedColumnName: 'id', nullable: true)]
    private ?Message $lastReply = null;

    /**
     * Property children
     */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: Message::class)]
    private Collection $children;

    /**
     * Property createdOn
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdOn;

    /**
     * Property updatedOn
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedOn;

    /**
     * Property locked
     */
    #[ORM\Column(type: 'boolean', nullable: true, options: ['default' => '0'])]
    private ?int $locked = 0;

    /**
     * Property sticky
     */
    #[ORM\Column(type: 'integer', nullable: true, options: ['default' => '0'])]
    private ?int $sticky = 0;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }

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
     * Getter for board
     */
    public function getBoard(): Board    {
        return $this->board;
    }

    /**
     * Setter for board
     */
    public function setBoard(Board $board): static
    {
        $this->board = $board;
        return $this;
    }

    /**
     * Getter for parent
     */
    public function getParent(): ?Message    {
        return $this->parent;
    }

    /**
     * Setter for parent
     */
    public function setParent(?Message $parent): static
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * Getter for children
     */
    public function getChildren(): Collection    {
        return $this->children;
    }

    /**
     * Add child
     */
    public function addChild(Message $child): static
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }
        return $this;
    }

    /**
     * Remove child
     */
    public function removeChild(Message $child): static
    {
        if ($this->children->removeElement($child)) {
            if ($child->getParent() === $this) {
                $child->setParent(null);
            }
        }
        return $this;
    }

    /**
     * Getter for subject
     */
    public function getSubject(): string    {
        return $this->subject;
    }

    /**
     * Setter for subject
     */
    public function setSubject(string $subject): static
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Getter for content
     */
    public function getContent(): ?string    {
        return $this->content;
    }

    /**
     * Setter for content
     */
    public function setContent(?string $content): static
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Getter for author
     */
    public function getAuthor(): ?User    {
        return $this->author;
    }

    /**
     * Setter for author
     */
    public function setAuthor(?User $author): static
    {
        $this->author = $author;
        return $this;
    }

    /**
     * Getter for repliesCount
     */
    public function getRepliesCount(): int    {
        return $this->repliesCount;
    }

    /**
     * Setter for repliesCount
     */
    public function setRepliesCount(int $repliesCount): static
    {
        $this->repliesCount = $repliesCount;
        return $this;
    }

    /**
     * Getter for lastReply
     */
    public function getLastReply(): ?Message    {
        return $this->lastReply;
    }

    /**
     * Setter for lastReply
     */
    public function setLastReply(?Message $lastReply): static
    {
        $this->lastReply = $lastReply;
        return $this;
    }

    /**
     * Getter for createdOn
     */
    public function getCreatedOn(): \DateTimeInterface    {
        return $this->createdOn;
    }

    /**
     * Setter for createdOn
     */
    public function setCreatedOn(\DateTimeInterface $createdOn): static
    {
        $this->createdOn = $createdOn;
        return $this;
    }

    /**
     * Getter for updatedOn
     */
    public function getUpdatedOn(): \DateTimeInterface    {
        return $this->updatedOn;
    }

    /**
     * Setter for updatedOn
     */
    public function setUpdatedOn(\DateTimeInterface $updatedOn): static
    {
        $this->updatedOn = $updatedOn;
        return $this;
    }

    /**
     * Getter for locked
     */
    public function getLocked(): ?int    {
        return $this->locked;
    }

    /**
     * Setter for locked
     */
    public function setLocked(?int $locked): static
    {
        $this->locked = $locked;
        return $this;
    }

    /**
     * Getter for sticky
     */
    public function getSticky(): ?int    {
        return $this->sticky;
    }

    /**
     * Setter for sticky
     */
    public function setSticky(?int $sticky): static
    {
        $this->sticky = $sticky;
        return $this;
    }

}

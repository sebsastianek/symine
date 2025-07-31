<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\CommentRepository;

/**
 * Comment.
 * Table: comments
 */
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comments')]
class Comment
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property commentedType
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => ''])]
    private string $commentedType = '';

    /**
     * Property commentedId
     */
    #[ORM\Column(type: 'integer', options: ['default' => '0'])]
    private int $commentedId = 0;

    /**
     * Property author
     */
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false)]
    private User $author;

    /**
     * Property content
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $content = NULL;

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
     * Getter for commentedType
     */
    public function getCommentedType(): string    {
        return $this->commentedType;
    }

    /**
     * Setter for commentedType
     */
    public function setCommentedType(string $commentedType): static
    {
        $this->commentedType = $commentedType;
        return $this;
    }

    /**
     * Getter for commentedId
     */
    public function getCommentedId(): int    {
        return $this->commentedId;
    }

    /**
     * Setter for commentedId
     */
    public function setCommentedId(int $commentedId): static
    {
        $this->commentedId = $commentedId;
        return $this;
    }

    /**
     * Getter for author
     */
    public function getAuthor(): User
    {
        return $this->author;
    }

    /**
     * Setter for author
     */
    public function setAuthor(User $author): static
    {
        $this->author = $author;
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

}

<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\NewsRepository;

/**
 * New.
 * Table: news
 */
#[ORM\Entity(repositoryClass: NewsRepository::class)]
#[ORM\Table(name: 'news')]
class News
{
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
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: true)]
    private ?Project $project = null;

    /**
     * Property title
     */
    #[ORM\Column(type: 'string', length: 60, options: ['default' => ''])]
    private string $title = '';

    /**
     * Property summary
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    private ?string $summary = '';

    /**
     * Property description
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $description = NULL;

    /**
     * Property author
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false)]
    private User $author;

    /**
     * Property createdOn
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $createdOn = NULL;

    /**
     * Property commentsCount
     */
    #[ORM\Column(type: 'integer', options: ['default' => '0'])]
    private int $commentsCount = 0;

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
    public function getProject(): ?Project
    {
        return $this->project;
    }

    /**
     * Setter for project
     */
    public function setProject(?Project $project): static
    {
        $this->project = $project;
        return $this;
    }

    /**
     * Getter for title
     */
    public function getTitle(): string    {
        return $this->title;
    }

    /**
     * Setter for title
     */
    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Getter for summary
     */
    public function getSummary(): ?string    {
        return $this->summary;
    }

    /**
     * Setter for summary
     */
    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;
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
     * Getter for createdOn
     */
    public function getCreatedOn(): ?\DateTimeInterface    {
        return $this->createdOn;
    }

    /**
     * Setter for createdOn
     */
    public function setCreatedOn(?\DateTimeInterface $createdOn): static
    {
        $this->createdOn = $createdOn;
        return $this;
    }

    /**
     * Getter for commentsCount
     */
    public function getCommentsCount(): int    {
        return $this->commentsCount;
    }

    /**
     * Setter for commentsCount
     */
    public function setCommentsCount(int $commentsCount): static
    {
        $this->commentsCount = $commentsCount;
        return $this;
    }

}

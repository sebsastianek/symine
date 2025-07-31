<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\WikiContentRepository;

/**
 * WikiContent.
 * Table: wiki_contents
 */
#[ORM\Entity(repositoryClass: WikiContentRepository::class)]
#[ORM\Table(name: 'wiki_contents')]
class WikiContent
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property page
     */
    #[ORM\ManyToOne(targetEntity: WikiPage::class)]
    #[ORM\JoinColumn(name: 'page_id', referencedColumnName: 'id', nullable: false)]
    private WikiPage $page;

    /**
     * Property author
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true)]
    private ?User $author = null;

    /**
     * Property text
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $text = NULL;

    /**
     * Property comments
     */
    #[ORM\Column(type: 'string', length: 1024, nullable: true, options: ['default' => ''])]
    private ?string $comments = '';

    /**
     * Property updatedOn
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedOn;

    /**
     * Property version
     */
    #[ORM\Column(type: 'integer')]
    private int $version;

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
     * Getter for page
     */
    public function getPage(): WikiPage
    {
        return $this->page;
    }

    /**
     * Setter for page
     */
    public function setPage(WikiPage $page): static
    {
        $this->page = $page;
        return $this;
    }

    /**
     * Getter for author
     */
    public function getAuthor(): ?User
    {
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
     * Getter for text
     */
    public function getText(): ?string    {
        return $this->text;
    }

    /**
     * Setter for text
     */
    public function setText(?string $text): static
    {
        $this->text = $text;
        return $this;
    }

    /**
     * Getter for comments
     */
    public function getComments(): ?string    {
        return $this->comments;
    }

    /**
     * Setter for comments
     */
    public function setComments(?string $comments): static
    {
        $this->comments = $comments;
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
     * Getter for version
     */
    public function getVersion(): int    {
        return $this->version;
    }

    /**
     * Setter for version
     */
    public function setVersion(int $version): static
    {
        $this->version = $version;
        return $this;
    }

}

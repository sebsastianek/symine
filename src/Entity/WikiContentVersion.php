<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\WikiContentVersionRepository;

/**
 * WikiContentVersion.
 * Table: wiki_content_versions
 */
#[ORM\Entity(repositoryClass: WikiContentVersionRepository::class)]
#[ORM\Table(name: 'wiki_content_versions')]
class WikiContentVersion
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property wikiContent
     */
    #[ORM\ManyToOne(targetEntity: WikiContent::class)]
    #[ORM\JoinColumn(name: 'wiki_content_id', referencedColumnName: 'id', nullable: false)]
    private WikiContent $wikiContent;

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
     * Property data
     */
    #[ORM\Column(type: 'blob', nullable: true)]
    private ?string $data = NULL;

    /**
     * Property compression
     */
    #[ORM\Column(type: 'string', length: 6, nullable: true, options: ['default' => ''])]
    private ?string $compression = '';

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
     * Getter for wikiContent
     */
    public function getWikiContent(): WikiContent
    {
        return $this->wikiContent;
    }

    /**
     * Setter for wikiContent
     */
    public function setWikiContent(WikiContent $wikiContent): static
    {
        $this->wikiContent = $wikiContent;
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
     * Getter for data
     */
    public function getData(): ?string    {
        return $this->data;
    }

    /**
     * Setter for data
     */
    public function setData(?string $data): static
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Getter for compression
     */
    public function getCompression(): ?string    {
        return $this->compression;
    }

    /**
     * Setter for compression
     */
    public function setCompression(?string $compression): static
    {
        $this->compression = $compression;
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

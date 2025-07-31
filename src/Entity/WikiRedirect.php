<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\WikiRedirectRepository;

/**
 * WikiRedirect.
 * Table: wiki_redirects
 */
#[ORM\Entity(repositoryClass: WikiRedirectRepository::class)]
#[ORM\Table(name: 'wiki_redirects')]
class WikiRedirect
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property wiki
     */
    #[ORM\ManyToOne(targetEntity: Wiki::class)]
    #[ORM\JoinColumn(name: 'wiki_id', referencedColumnName: 'id', nullable: false)]
    private Wiki $wiki;

    /**
     * Property title
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $title = NULL;

    /**
     * Property redirectsTo
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $redirectsTo = NULL;

    /**
     * Property createdOn
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdOn;

    /**
     * Property redirectsToWiki
     */
    #[ORM\ManyToOne(targetEntity: Wiki::class)]
    #[ORM\JoinColumn(name: 'redirects_to_wiki_id', referencedColumnName: 'id', nullable: false)]
    private Wiki $redirectsToWiki;

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
     * Getter for wiki
     */
    public function getWiki(): Wiki
    {
        return $this->wiki;
    }

    /**
     * Setter for wiki
     */
    public function setWiki(Wiki $wiki): static
    {
        $this->wiki = $wiki;
        return $this;
    }

    /**
     * Getter for title
     */
    public function getTitle(): ?string    {
        return $this->title;
    }

    /**
     * Setter for title
     */
    public function setTitle(?string $title): static
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Getter for redirectsTo
     */
    public function getRedirectsTo(): ?string    {
        return $this->redirectsTo;
    }

    /**
     * Setter for redirectsTo
     */
    public function setRedirectsTo(?string $redirectsTo): static
    {
        $this->redirectsTo = $redirectsTo;
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
     * Getter for redirectsToWiki
     */
    public function getRedirectsToWiki(): Wiki
    {
        return $this->redirectsToWiki;
    }

    /**
     * Setter for redirectsToWiki
     */
    public function setRedirectsToWiki(Wiki $redirectsToWiki): static
    {
        $this->redirectsToWiki = $redirectsToWiki;
        return $this;
    }

}

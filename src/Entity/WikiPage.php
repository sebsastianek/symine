<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\WikiPageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

/**
 * WikiPage.
 * Table: wiki_pages
 */
#[ORM\Entity(repositoryClass: WikiPageRepository::class)]
#[ORM\Table(name: 'wiki_pages')]
class WikiPage
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
    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    /**
     * Property createdOn
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdOn;

    /**
     * Property protected
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $protected = false;

    /**
     * Property parent
     */
    #[ORM\ManyToOne(targetEntity: WikiPage::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true)]
    private ?WikiPage $parent = null;

    /**
     * Property children
     */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: WikiPage::class)]
    private Collection $children;

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
     * Getter for wiki
     */
    public function getWiki(): Wiki    {
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
     * Getter for protected
     */
    public function getProtected(): bool
    {
        return $this->protected;
    }

    /**
     * Setter for protected
     */
    public function setProtected(bool $protected): static
    {
        $this->protected = $protected;
        return $this;
    }

    /**
     * Getter for parent
     */
    public function getParent(): ?WikiPage    {
        return $this->parent;
    }

    /**
     * Setter for parent
     */
    public function setParent(?WikiPage $parent): static
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
    public function addChild(WikiPage $child): static
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
    public function removeChild(WikiPage $child): static
    {
        if ($this->children->removeElement($child)) {
            if ($child->getParent() === $this) {
                $child->setParent(null);
            }
        }
        return $this;
    }

}

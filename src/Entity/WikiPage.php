<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wiki_pages')]
#[ORM\Index(name: 'wiki_pages_wiki_id_title', columns: ['wiki_id', 'title'])]
#[ORM\Index(name: 'index_wiki_pages_on_wiki_id', columns: ['wiki_id'])]
#[ORM\Index(name: 'index_wiki_pages_on_parent_id', columns: ['parent_id'])]
class WikiPage
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Wiki::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Wiki $wiki;

    #[ORM\Column(length: 255)]
    public string $title = '';

    #[ORM\Column]
    public \DateTimeImmutable $createdOn;

    #[ORM\Column(options: ['default' => false])]
    public bool $protected = false;

    #[ORM\ManyToOne(targetEntity: WikiPage::class, inversedBy: 'children')]
    public ?WikiPage $parent = null;

    /** @var Collection<int, WikiPage> */
    #[ORM\OneToMany(targetEntity: WikiPage::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['title' => 'ASC'])]
    public Collection $children;

    #[ORM\OneToOne(targetEntity: WikiContent::class, mappedBy: 'page', cascade: ['persist', 'remove'])]
    public ?WikiContent $content = null;

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
        $this->children = new ArrayCollection();
    }

    public function prettyTitle(): string
    {
        return str_replace('_', ' ', $this->title);
    }
}

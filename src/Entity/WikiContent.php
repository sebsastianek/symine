<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wiki_contents')]
#[ORM\Index(name: 'wiki_contents_page_id', columns: ['page_id'])]
#[ORM\Index(name: 'index_wiki_contents_on_author_id', columns: ['author_id'])]
class WikiContent
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\OneToOne(targetEntity: WikiPage::class, inversedBy: 'content')]
    #[ORM\JoinColumn(nullable: false)]
    public WikiPage $page;

    #[ORM\ManyToOne(targetEntity: User::class)]
    public ?User $author = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $text = null;

    #[ORM\Column(length: 1024, nullable: true, options: ['default' => ''])]
    public ?string $comments = '';

    #[ORM\Column]
    public \DateTimeImmutable $updatedOn;

    #[ORM\Column]
    public int $version = 1;

    public function __construct()
    {
        $this->updatedOn = new \DateTimeImmutable();
    }
}

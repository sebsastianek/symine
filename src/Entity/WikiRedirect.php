<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wiki_redirects')]
#[ORM\Index(name: 'wiki_redirects_wiki_id_title', columns: ['wiki_id', 'title'])]
#[ORM\Index(name: 'index_wiki_redirects_on_wiki_id', columns: ['wiki_id'])]
class WikiRedirect
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public int $wikiId = 0;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $redirectsTo = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdOn;

    #[ORM\Column]
    public int $redirectsToWikiId = 0;

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
    }
}

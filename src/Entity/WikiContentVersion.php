<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wiki_content_versions')]
#[ORM\Index(name: 'wiki_content_versions_wcid', columns: ['wiki_content_id'])]
#[ORM\Index(name: 'index_wiki_content_versions_on_updated_on', columns: ['updated_on'])]
class WikiContentVersion
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public int $wikiContentId = 0;

    #[ORM\Column]
    public int $pageId = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    public ?User $author = null;

    /** Raw text, possibly gzip compressed (compression = "gzip") like Redmine */
    #[ORM\Column(type: 'blob', nullable: true)]
    public mixed $data = null;

    #[ORM\Column(length: 6, nullable: true, options: ['default' => ''])]
    public ?string $compression = '';

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

    public function getText(): string
    {
        $data = \is_resource($this->data) ? stream_get_contents($this->data) : (string) $this->data;
        if ('gzip' === $this->compression) {
            $decoded = @gzinflate(substr($data, 10, -8)) ?: @gzdecode($data) ?: @gzuncompress($data);

            return false === $decoded ? '' : $decoded;
        }

        return $data;
    }

    public function setText(string $text): void
    {
        $this->data = $text;
        $this->compression = '';
    }
}

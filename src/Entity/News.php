<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'news')]
#[ORM\Index(name: 'news_project_id', columns: ['project_id'])]
#[ORM\Index(name: 'index_news_on_author_id', columns: ['author_id'])]
#[ORM\Index(name: 'index_news_on_created_on', columns: ['created_on'])]
class News
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    public ?Project $project = null;

    #[ORM\Column(length: 60, options: ['default' => ''])]
    public string $title = '';

    #[ORM\Column(length: 255, nullable: true, options: ['default' => ''])]
    public ?string $summary = '';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public User $author;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $createdOn = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $commentsCount = 0;

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
    }
}

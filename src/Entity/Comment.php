<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Polymorphic comment (commented_type: News) */
#[ORM\Entity]
#[ORM\Table(name: 'comments')]
#[ORM\Index(name: 'index_comments_on_commented_id_and_commented_type', columns: ['commented_id', 'commented_type'])]
#[ORM\Index(name: 'index_comments_on_author_id', columns: ['author_id'])]
class Comment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 30, options: ['default' => ''])]
    public string $commentedType = 'News';

    #[ORM\Column(options: ['default' => 0])]
    public int $commentedId = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public User $author;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $content = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdOn;

    #[ORM\Column]
    public \DateTimeImmutable $updatedOn;

    public function __construct()
    {
        $this->createdOn = $this->updatedOn = new \DateTimeImmutable();
    }
}

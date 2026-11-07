<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Polymorphic "thumbs up" reaction (reactable_type: Issue, Journal, Message, News, Comment) */
#[ORM\Entity]
#[ORM\Table(name: 'reactions')]
#[ORM\Index(name: 'index_reactions_on_reactable', columns: ['reactable_type', 'reactable_id'])]
#[ORM\Index(name: 'index_reactions_on_user_id', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'index_reactions_on_reactable_type_and_reactable_id_and_user_id', columns: ['reactable_type', 'reactable_id', 'user_id'])]
class Reaction
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $reactableType = '';

    #[ORM\Column]
    public int $reactableId = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public User $user;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }
}

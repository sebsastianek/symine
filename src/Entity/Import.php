<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** CSV imports (type: IssueImport, TimeEntryImport, UserImport) */
#[ORM\Entity]
#[ORM\Table(name: 'imports')]
class Import
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $type = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public User $user;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $filename = null;

    #[ORM\Column(type: 'redmine_serialized', nullable: true)]
    public mixed $settings = null;

    #[ORM\Column(nullable: true)]
    public ?int $totalItems = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $finished = false;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->filename = bin2hex(random_bytes(16));
    }
}

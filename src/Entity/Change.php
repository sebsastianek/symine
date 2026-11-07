<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'changes')]
#[ORM\Index(name: 'changesets_changeset_id', columns: ['changeset_id'])]
class Change
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Changeset::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Changeset $changeset;

    #[ORM\Column(length: 1, options: ['default' => ''])]
    public string $action = '';

    #[ORM\Column(type: 'text')]
    public string $path = '';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $fromPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $fromRevision = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $revision = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $branch = null;
}

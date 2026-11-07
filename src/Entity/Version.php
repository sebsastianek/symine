<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\VersionRepository::class)]
#[ORM\Table(name: 'versions')]
#[ORM\Index(name: 'versions_project_id', columns: ['project_id'])]
#[ORM\Index(name: 'index_versions_on_sharing', columns: ['sharing'])]
#[ORM\HasLifecycleCallbacks]
class Version implements \Stringable
{
    public const array STATUSES = ['open', 'locked', 'closed'];
    public const array SHARINGS = ['none', 'descendants', 'hierarchy', 'tree', 'system'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false)]
    public Project $project;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(length: 255, nullable: true, options: ['default' => ''])]
    public ?string $description = '';

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?\DateTimeImmutable $effectiveDate = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $createdOn = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $updatedOn = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $wikiPageTitle = null;

    #[ORM\Column(length: 255, nullable: true, options: ['default' => 'open'])]
    public ?string $status = 'open';

    #[ORM\Column(length: 255, options: ['default' => 'none'])]
    public string $sharing = 'none';

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdOn ??= $now;
        $this->updatedOn = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedOn = new \DateTimeImmutable();
    }

    public function isOpen(): bool
    {
        return 'open' === $this->status;
    }

    public function isClosed(): bool
    {
        return 'closed' === $this->status;
    }

    public function isShared(): bool
    {
        return 'none' !== $this->sharing;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->effectiveDate;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }

    /** Name prefixed by project name when shared with another project */
    public function displayName(?Project $context = null): string
    {
        if (null !== $context && $context->id !== $this->project->id) {
            return $this->project->name.' - '.$this->name;
        }

        return (string) $this->name;
    }
}

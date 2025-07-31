<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\ChangeRepository;

/**
 * Change.
 * Table: changes
 */
#[ORM\Entity(repositoryClass: ChangeRepository::class)]
#[ORM\Table(name: 'changes')]
class Change
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property changeset
     */
    #[ORM\ManyToOne(targetEntity: Changeset::class)]
    #[ORM\JoinColumn(name: 'changeset_id', referencedColumnName: 'id', nullable: false)]
    private Changeset $changeset;

    /**
     * Property action
     */
    #[ORM\Column(type: 'string', length: 1, options: ['default' => ''])]
    private string $action = '';

    /**
     * Property path
     */
    #[ORM\Column(type: 'text', length: 65535)]
    private string $path;

    /**
     * Property fromPath
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $fromPath = NULL;

    /**
     * Property fromRevision
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $fromRevision = NULL;

    /**
     * Property revision
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $revision = NULL;

    /**
     * Property branch
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $branch = NULL;

    /**
     * Getter for id
     */
    public function getId(): int    {
        return $this->id;
    }

    /**
     * Setter for id
     */
    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Getter for changeset
     */
    public function getChangeset(): Changeset
    {
        return $this->changeset;
    }

    /**
     * Setter for changeset
     */
    public function setChangeset(Changeset $changeset): static
    {
        $this->changeset = $changeset;
        return $this;
    }

    /**
     * Getter for action
     */
    public function getAction(): string    {
        return $this->action;
    }

    /**
     * Setter for action
     */
    public function setAction(string $action): static
    {
        $this->action = $action;
        return $this;
    }

    /**
     * Getter for path
     */
    public function getPath(): string    {
        return $this->path;
    }

    /**
     * Setter for path
     */
    public function setPath(string $path): static
    {
        $this->path = $path;
        return $this;
    }

    /**
     * Getter for fromPath
     */
    public function getFromPath(): ?string    {
        return $this->fromPath;
    }

    /**
     * Setter for fromPath
     */
    public function setFromPath(?string $fromPath): static
    {
        $this->fromPath = $fromPath;
        return $this;
    }

    /**
     * Getter for fromRevision
     */
    public function getFromRevision(): ?string    {
        return $this->fromRevision;
    }

    /**
     * Setter for fromRevision
     */
    public function setFromRevision(?string $fromRevision): static
    {
        $this->fromRevision = $fromRevision;
        return $this;
    }

    /**
     * Getter for revision
     */
    public function getRevision(): ?string    {
        return $this->revision;
    }

    /**
     * Setter for revision
     */
    public function setRevision(?string $revision): static
    {
        $this->revision = $revision;
        return $this;
    }

    /**
     * Getter for branch
     */
    public function getBranch(): ?string    {
        return $this->branch;
    }

    /**
     * Setter for branch
     */
    public function setBranch(?string $branch): static
    {
        $this->branch = $branch;
        return $this;
    }

}

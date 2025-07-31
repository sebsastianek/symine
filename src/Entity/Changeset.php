<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\ChangesetRepository;

/**
 * Changeset.
 * Table: changesets
 */
#[ORM\Entity(repositoryClass: ChangesetRepository::class)]
#[ORM\Table(name: 'changesets')]
class Changeset
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property repository
     */
    #[ORM\ManyToOne(targetEntity: Repository::class, inversedBy: 'changesets')]
    #[ORM\JoinColumn(name: 'repository_id', referencedColumnName: 'id', nullable: false)]
    private Repository $repository;

    /**
     * Property revision
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $revision;

    /**
     * Property committer
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $committer = NULL;

    /**
     * Property committedOn
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $committedOn;

    /**
     * Property comments
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comments = NULL;

    /**
     * Property commitDate
     */
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $commitDate = NULL;

    /**
     * Property scmid
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $scmid = NULL;

    /**
     * Property user
     */
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'changesets')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
    private ?User $user = NULL;

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
     * Getter for repository
     */
    public function getRepository(): Repository
    {
        return $this->repository;
    }

    /**
     * Setter for repository
     */
    public function setRepository(Repository $repository): static
    {
        $this->repository = $repository;
        return $this;
    }

    /**
     * Getter for revision
     */
    public function getRevision(): string    {
        return $this->revision;
    }

    /**
     * Setter for revision
     */
    public function setRevision(string $revision): static
    {
        $this->revision = $revision;
        return $this;
    }

    /**
     * Getter for committer
     */
    public function getCommitter(): ?string    {
        return $this->committer;
    }

    /**
     * Setter for committer
     */
    public function setCommitter(?string $committer): static
    {
        $this->committer = $committer;
        return $this;
    }

    /**
     * Getter for committedOn
     */
    public function getCommittedOn(): \DateTimeInterface    {
        return $this->committedOn;
    }

    /**
     * Setter for committedOn
     */
    public function setCommittedOn(\DateTimeInterface $committedOn): static
    {
        $this->committedOn = $committedOn;
        return $this;
    }

    /**
     * Getter for comments
     */
    public function getComments(): ?string    {
        return $this->comments;
    }

    /**
     * Setter for comments
     */
    public function setComments(?string $comments): static
    {
        $this->comments = $comments;
        return $this;
    }

    /**
     * Getter for commitDate
     */
    public function getCommitDate(): ?\DateTimeInterface    {
        return $this->commitDate;
    }

    /**
     * Setter for commitDate
     */
    public function setCommitDate(?\DateTimeInterface $commitDate): static
    {
        $this->commitDate = $commitDate;
        return $this;
    }

    /**
     * Getter for scmid
     */
    public function getScmid(): ?string    {
        return $this->scmid;
    }

    /**
     * Setter for scmid
     */
    public function setScmid(?string $scmid): static
    {
        $this->scmid = $scmid;
        return $this;
    }

    /**
     * Getter for user
     */
    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * Setter for user
     */
    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

}

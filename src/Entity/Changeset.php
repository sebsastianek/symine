<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'changesets')]
#[ORM\UniqueConstraint(name: 'changesets_repos_rev', columns: ['repository_id', 'revision'])]
#[ORM\Index(name: 'index_changesets_on_user_id', columns: ['user_id'])]
#[ORM\Index(name: 'index_changesets_on_repository_id', columns: ['repository_id'])]
#[ORM\Index(name: 'index_changesets_on_committed_on', columns: ['committed_on'])]
#[ORM\Index(name: 'changesets_repos_scmid', columns: ['repository_id', 'scmid'])]
class Changeset
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Repository::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Repository $repository;

    #[ORM\Column(length: 255)]
    public string $revision = '';

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $committer = null;

    #[ORM\Column]
    public \DateTimeImmutable $committedOn;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $comments = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?\DateTimeImmutable $commitDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $scmid = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    public ?User $user = null;

    /** @var Collection<int, Issue> */
    #[ORM\ManyToMany(targetEntity: Issue::class)]
    #[ORM\JoinTable(name: 'changesets_issues')]
    public Collection $issues;

    /** @var Collection<int, Changeset> */
    #[ORM\ManyToMany(targetEntity: Changeset::class)]
    #[ORM\JoinTable(name: 'changeset_parents')]
    #[ORM\JoinColumn(name: 'changeset_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'parent_id', referencedColumnName: 'id')]
    public Collection $parents;

    public function __construct()
    {
        $this->issues = new ArrayCollection();
        $this->parents = new ArrayCollection();
        $this->committedOn = new \DateTimeImmutable();
    }
}

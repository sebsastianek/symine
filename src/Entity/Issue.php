<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\IssueRepository::class)]
#[ORM\Table(name: 'issues')]
#[ORM\Index(name: 'issues_project_id', columns: ['project_id'])]
#[ORM\Index(name: 'index_issues_on_status_id', columns: ['status_id'])]
#[ORM\Index(name: 'index_issues_on_category_id', columns: ['category_id'])]
#[ORM\Index(name: 'index_issues_on_assigned_to_id', columns: ['assigned_to_id'])]
#[ORM\Index(name: 'index_issues_on_fixed_version_id', columns: ['fixed_version_id'])]
#[ORM\Index(name: 'index_issues_on_tracker_id', columns: ['tracker_id'])]
#[ORM\Index(name: 'index_issues_on_priority_id', columns: ['priority_id'])]
#[ORM\Index(name: 'index_issues_on_author_id', columns: ['author_id'])]
#[ORM\Index(name: 'index_issues_on_created_on', columns: ['created_on'])]
#[ORM\Index(name: 'index_issues_on_root_id_and_lft_and_rgt', columns: ['root_id', 'lft', 'rgt'])]
#[ORM\Index(name: 'index_issues_on_parent_id', columns: ['parent_id'])]
class Issue implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tracker::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Tracker $tracker;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Project $project;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $subject = '';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?\DateTimeImmutable $dueDate = null;

    #[ORM\ManyToOne(targetEntity: IssueCategory::class)]
    public ?IssueCategory $category = null;

    #[ORM\ManyToOne(targetEntity: IssueStatus::class)]
    #[ORM\JoinColumn(nullable: false)]
    public IssueStatus $status;

    #[ORM\ManyToOne(targetEntity: Principal::class)]
    public ?Principal $assignedTo = null;

    #[ORM\ManyToOne(targetEntity: IssuePriority::class)]
    #[ORM\JoinColumn(nullable: false)]
    public IssuePriority $priority;

    #[ORM\ManyToOne(targetEntity: Version::class)]
    public ?Version $fixedVersion = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public User $author;

    #[ORM\Version, ORM\Column(type: 'integer', options: ['default' => 0])]
    public int $lockVersion = 0;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $createdOn = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $updatedOn = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $doneRatio = 0;

    #[ORM\Column(type: 'float', nullable: true)]
    public ?float $estimatedHours = null;

    #[ORM\ManyToOne(targetEntity: Issue::class, inversedBy: 'children')]
    public ?Issue $parent = null;

    /** @var Collection<int, Issue> */
    #[ORM\OneToMany(targetEntity: Issue::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $children;

    #[ORM\Column(nullable: true)]
    public ?int $rootId = null;

    #[ORM\Column(nullable: true)]
    public ?int $lft = null;

    #[ORM\Column(nullable: true)]
    public ?int $rgt = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $isPrivate = false;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $closedOn = null;

    /** @var Collection<int, Journal> */
    #[ORM\OneToMany(targetEntity: Journal::class, mappedBy: 'issue', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdOn' => 'ASC', 'id' => 'ASC'])]
    public Collection $journals;

    /** @var Collection<int, IssueRelation> */
    #[ORM\OneToMany(targetEntity: IssueRelation::class, mappedBy: 'issueFrom', cascade: ['remove'])]
    public Collection $relationsFrom;

    /** @var Collection<int, IssueRelation> */
    #[ORM\OneToMany(targetEntity: IssueRelation::class, mappedBy: 'issueTo', cascade: ['remove'])]
    public Collection $relationsTo;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->journals = new ArrayCollection();
        $this->relationsFrom = new ArrayCollection();
        $this->relationsTo = new ArrayCollection();
    }

    public function isNew(): bool
    {
        return null === $this->id;
    }

    public function isClosed(): bool
    {
        return isset($this->status) && $this->status->isClosed;
    }

    public function isLeaf(): bool
    {
        return 0 === $this->children->count();
    }

    public function isOverdue(?\DateTimeImmutable $today = null): bool
    {
        $today ??= new \DateTimeImmutable('today');

        return null !== $this->dueDate && $this->dueDate < $today && !$this->isClosed();
    }

    public function isBehindSchedule(?\DateTimeImmutable $today = null): bool
    {
        if (null === $this->startDate || null === $this->dueDate) {
            return false;
        }
        $today ??= new \DateTimeImmutable('today');
        $days = (int) $this->startDate->diff($this->dueDate)->format('%r%a') + 1;
        $doneDate = $this->startDate->modify('+'.(int) floor($days * $this->doneRatio / 100).' days');

        return $doneDate <= $today;
    }

    /** @return list<IssueRelation> */
    public function getRelations(): array
    {
        return [...$this->relationsFrom->toArray(), ...$this->relationsTo->toArray()];
    }

    /** @return list<Issue> */
    public function getAncestors(): array
    {
        $out = [];
        $p = $this->parent;
        $seen = [spl_object_id($this) => true];
        // guard against cycles created in memory by an invalid parent assignment (validated later)
        while (null !== $p && !isset($seen[spl_object_id($p)])) {
            $seen[spl_object_id($p)] = true;
            array_unshift($out, $p);
            $p = $p->parent;
        }

        return $out;
    }

    /** @return list<Issue> */
    public function getDescendants(): array
    {
        $out = [];
        foreach ($this->children as $child) {
            $out[] = $child;
            array_push($out, ...$child->getDescendants());
        }

        return $out;
    }

    public function isDescendantOf(Issue $other): bool
    {
        foreach ($this->getAncestors() as $a) {
            if ($a->id === $other->id) {
                return true;
            }
        }

        return false;
    }

    /** Is this issue blocked by an open issue ("blocks" relation)? */
    public function isBlocked(): bool
    {
        foreach ($this->relationsTo as $rel) {
            if (IssueRelation::TYPE_BLOCKS === $rel->relationType && !$rel->issueFrom->isClosed()) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return \sprintf('%s #%d: %s', isset($this->tracker) ? $this->tracker->name : '', $this->id ?? 0, $this->subject);
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\ProjectRepository::class)]
#[ORM\Table(name: 'projects')]
#[ORM\Index(name: 'index_projects_on_lft', columns: ['lft'])]
#[ORM\Index(name: 'index_projects_on_rgt', columns: ['rgt'])]
#[ORM\UniqueConstraint(name: 'index_projects_on_identifier', columns: ['identifier'])]
#[ORM\HasLifecycleCallbacks]
class Project implements \Stringable
{
    public const int STATUS_ACTIVE = 1;
    public const int STATUS_CLOSED = 5;
    public const int STATUS_ARCHIVED = 9;
    public const int STATUS_SCHEDULED_FOR_DELETION = 10;

    public const int IDENTIFIER_MAX_LENGTH = 100;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;

    #[ORM\Column(length: 255, nullable: true, options: ['default' => ''])]
    public ?string $homepage = '';

    #[ORM\Column(options: ['default' => true])]
    public bool $isPublic = true;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'children')]
    public ?Project $parent = null;

    /** @var Collection<int, Project> */
    #[ORM\OneToMany(targetEntity: Project::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    public Collection $children;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $createdOn = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $updatedOn = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $identifier = null;

    #[ORM\Column(options: ['default' => 1])]
    public int $status = self::STATUS_ACTIVE;

    #[ORM\Column(nullable: true)]
    public ?int $lft = null;

    #[ORM\Column(nullable: true)]
    public ?int $rgt = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $inheritMembers = false;

    #[ORM\ManyToOne(targetEntity: Version::class)]
    public ?Version $defaultVersion = null;

    #[ORM\ManyToOne(targetEntity: Principal::class)]
    public ?Principal $defaultAssignedTo = null;

    #[ORM\Column(nullable: true)]
    public ?int $defaultIssueQueryId = null;

    /** @var Collection<int, Tracker> */
    #[ORM\ManyToMany(targetEntity: Tracker::class, inversedBy: 'projects')]
    #[ORM\JoinTable(name: 'projects_trackers')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    public Collection $trackers;

    /** @var Collection<int, EnabledModule> */
    #[ORM\OneToMany(targetEntity: EnabledModule::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $enabledModules;

    /** @var Collection<int, Member> */
    #[ORM\OneToMany(targetEntity: Member::class, mappedBy: 'project', cascade: ['remove'])]
    public Collection $memberships;

    /** @var Collection<int, IssueCategory> */
    #[ORM\OneToMany(targetEntity: IssueCategory::class, mappedBy: 'project', cascade: ['remove'])]
    #[ORM\OrderBy(['name' => 'ASC'])]
    public Collection $issueCategories;

    /** @var Collection<int, Version> */
    #[ORM\OneToMany(targetEntity: Version::class, mappedBy: 'project', cascade: ['remove'])]
    public Collection $versions;

    /** @var Collection<int, IssueCustomField> */
    #[ORM\ManyToMany(targetEntity: IssueCustomField::class, inversedBy: 'projects')]
    #[ORM\JoinTable(name: 'custom_fields_projects')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'custom_field_id', referencedColumnName: 'id')]
    public Collection $issueCustomFields;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->trackers = new ArrayCollection();
        $this->enabledModules = new ArrayCollection();
        $this->memberships = new ArrayCollection();
        $this->issueCategories = new ArrayCollection();
        $this->versions = new ArrayCollection();
        $this->issueCustomFields = new ArrayCollection();
    }

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

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    public function isArchived(): bool
    {
        return self::STATUS_ARCHIVED === $this->status || self::STATUS_SCHEDULED_FOR_DELETION === $this->status;
    }

    public function isClosed(): bool
    {
        return self::STATUS_CLOSED === $this->status;
    }

    /** Project can be modified (not closed/archived) */
    public function isWritable(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    /** @return list<string> */
    public function getEnabledModuleNames(): array
    {
        return array_values(array_map(static fn (EnabledModule $m) => $m->name, $this->enabledModules->toArray()));
    }

    public function isModuleEnabled(string $module): bool
    {
        return \in_array($module, $this->getEnabledModuleNames(), true);
    }

    /** @param list<string> $names */
    public function setEnabledModuleNames(array $names): void
    {
        foreach ($this->enabledModules->toArray() as $m) {
            if (!\in_array($m->name, $names, true)) {
                $this->enabledModules->removeElement($m);
            }
        }
        $current = $this->getEnabledModuleNames();
        foreach (array_unique($names) as $name) {
            if (!\in_array($name, $current, true)) {
                $m = new EnabledModule();
                $m->project = $this;
                $m->name = $name;
                $this->enabledModules->add($m);
            }
        }
    }

    /** @return list<Project> ancestors, root first */
    public function getAncestors(): array
    {
        $out = [];
        $p = $this->parent;
        while (null !== $p) {
            array_unshift($out, $p);
            $p = $p->parent;
        }

        return $out;
    }

    public function getRoot(): Project
    {
        $p = $this;
        while (null !== $p->parent) {
            $p = $p->parent;
        }

        return $p;
    }

    public function isDescendantOf(Project $other): bool
    {
        foreach ($this->getAncestors() as $a) {
            if ($a->id === $other->id) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Project> */
    public function getSelfAndDescendants(): array
    {
        $out = [$this];
        foreach ($this->children as $child) {
            array_push($out, ...$child->getSelfAndDescendants());
        }

        return $out;
    }

    public function hasTracker(Tracker $tracker): bool
    {
        foreach ($this->trackers as $t) {
            if ($t->id === $tracker->id) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}

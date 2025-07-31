<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use App\Repository\TimeEntryRepository;

/**
 * TimeEntry.
 * Table: time_entries
 */
#[ORM\Entity(repositoryClass: TimeEntryRepository::class)]
#[ORM\Table(name: 'time_entries')]
class TimeEntry
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property project
     */
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false)]
    private Project $project;

    /**
     * Property author
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true)]
    private ?User $author = null;

    /**
     * Property user
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    /**
     * Property issue
     */
    #[ORM\ManyToOne(targetEntity: Issue::class)]
    #[ORM\JoinColumn(name: 'issue_id', referencedColumnName: 'id', nullable: true)]
    private ?Issue $issue = null;

    /**
     * Property hours
     */
    #[ORM\Column(type: 'float')]
    private float $hours;

    /**
     * Property comments
     */
    #[ORM\Column(type: 'string', length: 1024, nullable: true)]
    private ?string $comments = NULL;

    /**
     * Property activity
     */
    #[ORM\ManyToOne(targetEntity: Enumeration::class)]
    #[ORM\JoinColumn(name: 'activity_id', referencedColumnName: 'id', nullable: false)]
    private Enumeration $activity;

    /**
     * Property spentOn
     */
    #[ORM\Column(type: 'date')]
    private \DateTimeInterface $spentOn;

    /**
     * Property tyear
     */
    #[ORM\Column(type: 'integer')]
    private int $tyear;

    /**
     * Property tmonth
     */
    #[ORM\Column(type: 'integer')]
    private int $tmonth;

    /**
     * Property tweek
     */
    #[ORM\Column(type: 'integer')]
    private int $tweek;

    /**
     * Property createdOn
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdOn;

    /**
     * Property updatedOn
     */
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedOn;

    /**
     * Getter for id
     */
    public function getId(): int
    {
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
     * Getter for project
     */
    public function getProject(): Project
    {
        return $this->project;
    }

    /**
     * Setter for project
     */
    public function setProject(Project $project): static
    {
        $this->project = $project;
        return $this;
    }

    /**
     * Getter for author
     */
    public function getAuthor(): ?User
    {
        return $this->author;
    }

    /**
     * Setter for author
     */
    public function setAuthor(?User $author): static
    {
        $this->author = $author;
        return $this;
    }

    /**
     * Getter for user
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * Setter for user
     */
    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Getter for issue
     */
    public function getIssue(): ?Issue
    {
        return $this->issue;
    }

    /**
     * Setter for issue
     */
    public function setIssue(?Issue $issue): static
    {
        $this->issue = $issue;
        return $this;
    }

    /**
     * Getter for hours
     */
    public function getHours(): float
    {
        return $this->hours;
    }

    /**
     * Setter for hours
     */
    public function setHours(float $hours): static
    {
        $this->hours = $hours;
        return $this;
    }

    /**
     * Getter for comments
     */
    public function getComments(): ?string
    {
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
     * Getter for activity
     */
    public function getActivity(): Enumeration
    {
        return $this->activity;
    }

    /**
     * Setter for activity
     */
    public function setActivity(Enumeration $activity): static
    {
        $this->activity = $activity;
        return $this;
    }

    /**
     * Getter for spentOn
     */
    public function getSpentOn(): \DateTimeInterface
    {
        return $this->spentOn;
    }

    /**
     * Setter for spentOn
     */
    public function setSpentOn(\DateTimeInterface $spentOn): static
    {
        $this->spentOn = $spentOn;
        return $this;
    }

    /**
     * Getter for tyear
     */
    public function getTyear(): int
    {
        return $this->tyear;
    }

    /**
     * Setter for tyear
     */
    public function setTyear(int $tyear): static
    {
        $this->tyear = $tyear;
        return $this;
    }

    /**
     * Getter for tmonth
     */
    public function getTmonth(): int
    {
        return $this->tmonth;
    }

    /**
     * Setter for tmonth
     */
    public function setTmonth(int $tmonth): static
    {
        $this->tmonth = $tmonth;
        return $this;
    }

    /**
     * Getter for tweek
     */
    public function getTweek(): int
    {
        return $this->tweek;
    }

    /**
     * Setter for tweek
     */
    public function setTweek(int $tweek): static
    {
        $this->tweek = $tweek;
        return $this;
    }

    /**
     * Getter for createdOn
     */
    public function getCreatedOn(): \DateTimeInterface
    {
        return $this->createdOn;
    }

    /**
     * Setter for createdOn
     */
    public function setCreatedOn(\DateTimeInterface $createdOn): static
    {
        $this->createdOn = $createdOn;
        return $this;
    }

    /**
     * Getter for updatedOn
     */
    public function getUpdatedOn(): \DateTimeInterface
    {
        return $this->updatedOn;
    }

    /**
     * Setter for updatedOn
     */
    public function setUpdatedOn(\DateTimeInterface $updatedOn): static
    {
        $this->updatedOn = $updatedOn;
        return $this;
    }
}

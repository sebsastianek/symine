<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\TimeEntryRepository::class)]
#[ORM\Table(name: 'time_entries')]
#[ORM\Index(name: 'time_entries_project_id', columns: ['project_id'])]
#[ORM\Index(name: 'time_entries_issue_id', columns: ['issue_id'])]
#[ORM\Index(name: 'index_time_entries_on_activity_id', columns: ['activity_id'])]
#[ORM\Index(name: 'index_time_entries_on_user_id', columns: ['user_id'])]
#[ORM\Index(name: 'index_time_entries_on_created_on', columns: ['created_on'])]
#[ORM\HasLifecycleCallbacks]
class TimeEntry
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Project $project;

    /** The user the time is logged for */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public User $user;

    #[ORM\ManyToOne(targetEntity: Issue::class)]
    public ?Issue $issue = null;

    #[ORM\Column(type: 'float')]
    public float $hours = 0.0;

    #[ORM\Column(length: 1024, nullable: true)]
    public ?string $comments = null;

    #[ORM\ManyToOne(targetEntity: TimeEntryActivity::class)]
    #[ORM\JoinColumn(nullable: false)]
    public TimeEntryActivity $activity;

    #[ORM\Column(type: 'date_immutable')]
    public \DateTimeImmutable $spentOn;

    #[ORM\Column]
    public int $tyear = 0;

    #[ORM\Column]
    public int $tmonth = 0;

    #[ORM\Column]
    public int $tweek = 0;

    #[ORM\Column]
    public \DateTimeImmutable $createdOn;

    #[ORM\Column]
    public \DateTimeImmutable $updatedOn;

    /** The user who logged the time */
    #[ORM\ManyToOne(targetEntity: User::class)]
    public ?User $author = null;

    public function __construct()
    {
        $this->spentOn = new \DateTimeImmutable('today');
        $this->createdOn = $this->updatedOn = new \DateTimeImmutable();
    }

    public function setSpentOn(\DateTimeImmutable $date): void
    {
        $this->spentOn = $date;
        $this->tyear = (int) $date->format('Y');
        $this->tmonth = (int) $date->format('n');
        $this->tweek = (int) $date->format('W');
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function beforeSave(): void
    {
        $this->setSpentOn($this->spentOn);
        $this->updatedOn = new \DateTimeImmutable();
    }
}

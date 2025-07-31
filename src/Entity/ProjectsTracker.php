<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\ProjectsTrackerRepository;

/**
 * ProjectsTracker.
 * Table: projects_trackers
 */
#[ORM\Entity(repositoryClass: ProjectsTrackerRepository::class)]
#[ORM\Table(name: 'projects_trackers')]
class ProjectsTracker
{
    /**
     * Property project
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false)]
    private Project $project;

    /**
     * Property tracker
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Tracker::class)]
    #[ORM\JoinColumn(name: 'tracker_id', referencedColumnName: 'id', nullable: false)]
    private Tracker $tracker;

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
     * Getter for tracker
     */
    public function getTracker(): Tracker
    {
        return $this->tracker;
    }

    /**
     * Setter for tracker
     */
    public function setTracker(Tracker $tracker): static
    {
        $this->tracker = $tracker;
        return $this;
    }

}

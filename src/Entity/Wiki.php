<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\WikiRepository;

/**
 * Wiki.
 * Table: wikis
 */
#[ORM\Entity(repositoryClass: WikiRepository::class)]
#[ORM\Table(name: 'wikis')]
class Wiki
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
     * Property startPage
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $startPage;

    /**
     * Property status
     */
    #[ORM\Column(type: 'integer', options: ['default' => '1'])]
    private int $status = 1;

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
     * Getter for startPage
     */
    public function getStartPage(): string    {
        return $this->startPage;
    }

    /**
     * Setter for startPage
     */
    public function setStartPage(string $startPage): static
    {
        $this->startPage = $startPage;
        return $this;
    }

    /**
     * Getter for status
     */
    public function getStatus(): int    {
        return $this->status;
    }

    /**
     * Setter for status
     */
    public function setStatus(int $status): static
    {
        $this->status = $status;
        return $this;
    }

}

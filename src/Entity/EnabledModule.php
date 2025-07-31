<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\EnabledModuleRepository;

/**
 * EnabledModule.
 * Table: enabled_modules
 */
#[ORM\Entity(repositoryClass: EnabledModuleRepository::class)]
#[ORM\Table(name: 'enabled_modules')]
class EnabledModule
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
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: true)]
    private ?Project $project = null;

    /**
     * Property name
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

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
    public function getProject(): ?Project
    {
        return $this->project;
    }

    /**
     * Setter for project
     */
    public function setProject(?Project $project): static
    {
        $this->project = $project;
        return $this;
    }

    /**
     * Getter for name
     */
    public function getName(): string    {
        return $this->name;
    }

    /**
     * Setter for name
     */
    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

}

<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\DocumentRepository;

/**
 * Document.
 * Table: documents
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'documents')]
class Document
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
     * Property category
     */
    #[ORM\ManyToOne(targetEntity: Enumeration::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: false)]
    private Enumeration $category;

    /**
     * Property title
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $title = '';

    /**
     * Property description
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $description = NULL;

    /**
     * Property createdOn
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $createdOn = NULL;

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
     * Getter for category
     */
    public function getCategory(): Enumeration
    {
        return $this->category;
    }

    /**
     * Setter for category
     */
    public function setCategory(Enumeration $category): static
    {
        $this->category = $category;
        return $this;
    }

    /**
     * Getter for title
     */
    public function getTitle(): string    {
        return $this->title;
    }

    /**
     * Setter for title
     */
    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Getter for description
     */
    public function getDescription(): ?string    {
        return $this->description;
    }

    /**
     * Setter for description
     */
    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Getter for createdOn
     */
    public function getCreatedOn(): ?\DateTimeInterface    {
        return $this->createdOn;
    }

    /**
     * Setter for createdOn
     */
    public function setCreatedOn(?\DateTimeInterface $createdOn): static
    {
        $this->createdOn = $createdOn;
        return $this;
    }

}

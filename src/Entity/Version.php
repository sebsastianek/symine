<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\VersionRepository;

/**
 * Version.
 * Table: versions
 */
#[ORM\Entity(repositoryClass: VersionRepository::class)]
#[ORM\Table(name: 'versions')]
class Version
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
     * Property name
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $name = '';

    /**
     * Property description
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    private ?string $description = '';

    /**
     * Property effectiveDate
     */
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $effectiveDate = NULL;

    /**
     * Property createdOn
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $createdOn = NULL;

    /**
     * Property updatedOn
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedOn = NULL;

    /**
     * Property wikiPageTitle
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $wikiPageTitle = NULL;

    /**
     * Property status
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true, options: ['default' => 'open'])]
    private ?string $status = 'open';

    /**
     * Property sharing
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => 'none'])]
    private string $sharing = 'none';

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
     * Getter for effectiveDate
     */
    public function getEffectiveDate(): ?\DateTimeInterface    {
        return $this->effectiveDate;
    }

    /**
     * Setter for effectiveDate
     */
    public function setEffectiveDate(?\DateTimeInterface $effectiveDate): static
    {
        $this->effectiveDate = $effectiveDate;
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

    /**
     * Getter for updatedOn
     */
    public function getUpdatedOn(): ?\DateTimeInterface    {
        return $this->updatedOn;
    }

    /**
     * Setter for updatedOn
     */
    public function setUpdatedOn(?\DateTimeInterface $updatedOn): static
    {
        $this->updatedOn = $updatedOn;
        return $this;
    }

    /**
     * Getter for wikiPageTitle
     */
    public function getWikiPageTitle(): ?string    {
        return $this->wikiPageTitle;
    }

    /**
     * Setter for wikiPageTitle
     */
    public function setWikiPageTitle(?string $wikiPageTitle): static
    {
        $this->wikiPageTitle = $wikiPageTitle;
        return $this;
    }

    /**
     * Getter for status
     */
    public function getStatus(): ?string    {
        return $this->status;
    }

    /**
     * Setter for status
     */
    public function setStatus(?string $status): static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Getter for sharing
     */
    public function getSharing(): string    {
        return $this->sharing;
    }

    /**
     * Setter for sharing
     */
    public function setSharing(string $sharing): static
    {
        $this->sharing = $sharing;
        return $this;
    }

}

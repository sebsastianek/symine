<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\RepositoryRepository;

/**
 * Repository.
 * Table: repositories
 */
#[ORM\Entity(repositoryClass: RepositoryRepository::class)]
#[ORM\Table(name: 'repositories')]
class Repository
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
     * Property url
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $url = '';

    /**
     * Property login
     */
    #[ORM\Column(type: 'string', length: 60, nullable: true, options: ['default' => ''])]
    private ?string $login = '';

    /**
     * Property password
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    private ?string $password = '';

    /**
     * Property rootUrl
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true, options: ['default' => ''])]
    private ?string $rootUrl = '';

    /**
     * Property type
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $type = NULL;

    /**
     * Property pathEncoding
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $pathEncoding = NULL;

    /**
     * Property logEncoding
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $logEncoding = NULL;

    /**
     * Property extraInfo
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $extraInfo = NULL;

    /**
     * Property identifier
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $identifier = NULL;

    /**
     * Property isDefault
     */
    #[ORM\Column(type: 'boolean', nullable: true, options: ['default' => false])]
    private ?bool $isDefault = false;

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
    public function getProject(): Project    {
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
     * Getter for url
     */
    public function getUrl(): string    {
        return $this->url;
    }

    /**
     * Setter for url
     */
    public function setUrl(string $url): static
    {
        $this->url = $url;
        return $this;
    }

    /**
     * Getter for login
     */
    public function getLogin(): ?string    {
        return $this->login;
    }

    /**
     * Setter for login
     */
    public function setLogin(?string $login): static
    {
        $this->login = $login;
        return $this;
    }

    /**
     * Getter for password
     */
    public function getPassword(): ?string    {
        return $this->password;
    }

    /**
     * Setter for password
     */
    public function setPassword(?string $password): static
    {
        $this->password = $password;
        return $this;
    }

    /**
     * Getter for rootUrl
     */
    public function getRootUrl(): ?string    {
        return $this->rootUrl;
    }

    /**
     * Setter for rootUrl
     */
    public function setRootUrl(?string $rootUrl): static
    {
        $this->rootUrl = $rootUrl;
        return $this;
    }

    /**
     * Getter for type
     */
    public function getType(): ?string    {
        return $this->type;
    }

    /**
     * Setter for type
     */
    public function setType(?string $type): static
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Getter for pathEncoding
     */
    public function getPathEncoding(): ?string    {
        return $this->pathEncoding;
    }

    /**
     * Setter for pathEncoding
     */
    public function setPathEncoding(?string $pathEncoding): static
    {
        $this->pathEncoding = $pathEncoding;
        return $this;
    }

    /**
     * Getter for logEncoding
     */
    public function getLogEncoding(): ?string    {
        return $this->logEncoding;
    }

    /**
     * Setter for logEncoding
     */
    public function setLogEncoding(?string $logEncoding): static
    {
        $this->logEncoding = $logEncoding;
        return $this;
    }

    /**
     * Getter for extraInfo
     */
    public function getExtraInfo(): ?string    {
        return $this->extraInfo;
    }

    /**
     * Setter for extraInfo
     */
    public function setExtraInfo(?string $extraInfo): static
    {
        $this->extraInfo = $extraInfo;
        return $this;
    }

    /**
     * Getter for identifier
     */
    public function getIdentifier(): ?string    {
        return $this->identifier;
    }

    /**
     * Setter for identifier
     */
    public function setIdentifier(?string $identifier): static
    {
        $this->identifier = $identifier;
        return $this;
    }

    /**
     * Getter for isDefault
     */
    public function getIsDefault(): ?bool
    {
        return $this->isDefault;
    }

    /**
     * Setter for isDefault
     */
    public function setIsDefault(?bool $isDefault): static
    {
        $this->isDefault = $isDefault;
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

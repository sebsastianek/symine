<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** SCM repository (type: Repository::Git, Repository::Subversion, ...) */
#[ORM\Entity]
#[ORM\Table(name: 'repositories')]
#[ORM\Index(name: 'index_repositories_on_project_id', columns: ['project_id'])]
class Repository
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Project $project;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $url = '';

    #[ORM\Column(length: 60, nullable: true, options: ['default' => ''])]
    public ?string $login = '';

    #[ORM\Column(length: 255, nullable: true, options: ['default' => ''])]
    public ?string $password = '';

    #[ORM\Column(length: 255, nullable: true, options: ['default' => ''])]
    public ?string $rootUrl = '';

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $type = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $pathEncoding = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $logEncoding = null;

    #[ORM\Column(type: 'redmine_serialized', nullable: true)]
    public mixed $extraInfo = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $identifier = null;

    #[ORM\Column(nullable: true, options: ['default' => false])]
    public ?bool $isDefault = false;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $createdOn = null;

    public function scmName(): string
    {
        return str_replace('Repository::', '', (string) $this->type);
    }
}

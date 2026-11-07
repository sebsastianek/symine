<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'issue_categories')]
#[ORM\Index(name: 'issue_categories_project_id', columns: ['project_id'])]
#[ORM\Index(name: 'index_issue_categories_on_assigned_to_id', columns: ['assigned_to_id'])]
class IssueCategory implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'issueCategories')]
    #[ORM\JoinColumn(nullable: false)]
    public Project $project;

    #[ORM\Column(length: 60, options: ['default' => ''])]
    public string $name = '';

    #[ORM\ManyToOne(targetEntity: Principal::class)]
    public ?Principal $assignedTo = null;

    public function __toString(): string
    {
        return $this->name;
    }
}

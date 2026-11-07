<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\IssueStatusRepository::class)]
#[ORM\Table(name: 'issue_statuses')]
#[ORM\Index(name: 'index_issue_statuses_on_position', columns: ['position'])]
#[ORM\Index(name: 'index_issue_statuses_on_is_closed', columns: ['is_closed'])]
class IssueStatus implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 30, options: ['default' => ''])]
    public string $name = '';

    #[ORM\Column(options: ['default' => false])]
    public bool $isClosed = false;

    #[ORM\Column(nullable: true)]
    public ?int $position = null;

    #[ORM\Column(nullable: true)]
    public ?int $defaultDoneRatio = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $description = null;

    public function __toString(): string
    {
        return $this->name;
    }
}

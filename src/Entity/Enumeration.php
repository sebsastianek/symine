<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Redmine "enumerations" table: IssuePriority, TimeEntryActivity, DocumentCategory (STI). */
#[ORM\Entity(repositoryClass: \App\Repository\EnumerationRepository::class)]
#[ORM\Table(name: 'enumerations')]
#[ORM\Index(name: 'index_enumerations_on_project_id', columns: ['project_id'])]
#[ORM\Index(name: 'index_enumerations_on_id_and_type', columns: ['id', 'type'])]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[ORM\DiscriminatorMap([
    'IssuePriority' => IssuePriority::class,
    'TimeEntryActivity' => TimeEntryActivity::class,
    'DocumentCategory' => DocumentCategory::class,
])]
abstract class Enumeration implements \Stringable
{
    public const array TYPES = [
        'IssuePriority' => IssuePriority::class,
        'TimeEntryActivity' => TimeEntryActivity::class,
        'DocumentCategory' => DocumentCategory::class,
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 30, options: ['default' => ''])]
    public string $name = '';

    #[ORM\Column(nullable: true)]
    public ?int $position = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $isDefault = false;

    #[ORM\Column(options: ['default' => true])]
    public bool $active = true;

    /** Project specific override (time entry activities) */
    #[ORM\ManyToOne(targetEntity: Project::class)]
    public ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: Enumeration::class)]
    public ?Enumeration $parent = null;

    #[ORM\Column(length: 30, nullable: true)]
    public ?string $positionName = null;

    abstract public static function typeName(): string;

    public function __toString(): string
    {
        return $this->name;
    }
}

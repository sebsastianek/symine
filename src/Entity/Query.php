<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Saved queries (STI on type). Filters/columns/sort are stored in the same structure as Redmine. */
#[ORM\Entity(repositoryClass: \App\Repository\QueryRepository::class)]
#[ORM\Table(name: 'queries')]
#[ORM\Index(name: 'index_queries_on_project_id', columns: ['project_id'])]
#[ORM\Index(name: 'index_queries_on_user_id', columns: ['user_id'])]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[ORM\DiscriminatorMap([
    'IssueQuery' => IssueQuery::class,
    'ProjectQuery' => ProjectQuery::class,
    'TimeEntryQuery' => TimeEntryQuery::class,
    'UserQuery' => UserQuery::class,
    'ProjectAdminQuery' => ProjectAdminQuery::class,
])]
abstract class Query implements \Stringable
{
    public const int VISIBILITY_PRIVATE = 0;
    public const int VISIBILITY_ROLES = 1;
    public const int VISIBILITY_PUBLIC = 2;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    public ?Project $project = null;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $name = '';

    /** {field: {operator: "=", values: [...]}} */
    #[ORM\Column(type: 'redmine_serialized', nullable: true)]
    public mixed $filters = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $userId = 0;

    /** @var list<string>|null */
    #[ORM\Column(type: 'redmine_serialized', nullable: true)]
    public mixed $columnNames = null;

    /** [[field, asc|desc], ...] */
    #[ORM\Column(type: 'redmine_serialized', nullable: true)]
    public mixed $sortCriteria = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $groupBy = null;

    #[ORM\Column(nullable: true, options: ['default' => 0])]
    public ?int $visibility = self::VISIBILITY_PRIVATE;

    /** totalable_names, display_type, draw_relations, draw_progress_line, ... */
    #[ORM\Column(type: 'redmine_serialized', nullable: true)]
    public mixed $options = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $description = null;

    /** @var Collection<int, Role> */
    #[ORM\ManyToMany(targetEntity: Role::class)]
    #[ORM\JoinTable(name: 'queries_roles')]
    #[ORM\JoinColumn(name: 'query_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'role_id', referencedColumnName: 'id')]
    public Collection $roles;

    public function __construct()
    {
        $this->roles = new ArrayCollection();
    }

    abstract public static function typeName(): string;

    public function getOption(string $key, mixed $default = null): mixed
    {
        return \is_array($this->options) ? ($this->options[$key] ?? $default) : $default;
    }

    public function setOption(string $key, mixed $value): void
    {
        $o = \is_array($this->options) ? $this->options : [];
        $o[$key] = $value;
        $this->options = $o;
    }

    public function isPublic(): bool
    {
        return self::VISIBILITY_PUBLIC === $this->visibility;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}

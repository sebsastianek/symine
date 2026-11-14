<?php

declare(strict_types=1);

namespace Symine\Kanban\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Kanban board: which projects it shows, how issue statuses map to its columns and how cards look.
 *
 * Projects, the owner and roles are kept as ids, like Redmine's polymorphic columns: the plugin adds no foreign
 * keys to the core schema, and a board survives the deletion of what it points to.
 */
#[ORM\Entity]
#[ORM\Table(name: 'kanban_boards')]
class Board
{
    public const int VISIBILITY_PRIVATE = 0;
    public const int VISIBILITY_ROLES = 1;
    public const int VISIBILITY_PUBLIC = 2;

    public const array SWIMLANES = ['none', 'project', 'assignee', 'version', 'tracker', 'priority', 'category', 'parent'];
    /** "badges": the counts of comments, files and subtasks; "cover": the first picture attached to the issue */
    public const array CARD_FIELDS = ['project', 'tracker', 'priority', 'assignee', 'version', 'category', 'due_date', 'done_ratio', 'estimated_hours', 'updated_on', 'badges', 'cover'];
    public const array COLOR_BY = ['none', 'priority', 'tracker', 'project'];
    public const array SORTS = ['manual', 'priority', 'due_date', 'updated_on'];

    public const array DEFAULT_OPTIONS = [
        'swimlane' => 'none',
        'card_fields' => ['tracker', 'priority', 'assignee', 'due_date', 'done_ratio', 'badges', 'cover'],
        'color_by' => 'priority',
        'sort' => 'manual',
        // closed issues stay on the board for that many days (0: for ever)
        'closed_days' => 14,
        // cards shown per column before "Show more"
        'card_limit' => 100,
        // seconds between two checks for changes made by others (0: never)
        'refresh' => 60,
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;

    /** The project the board belongs to (its menu, its permissions); null for a board above the projects */
    #[ORM\Column(nullable: true)]
    public ?int $projectId = null;

    #[ORM\Column]
    public int $userId = 0;

    #[ORM\Column]
    public int $visibility = self::VISIBILITY_PRIVATE;

    /** @var list<int> roles that see the board when the visibility is VISIBILITY_ROLES */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $roleIds = [];

    /** @var list<int> the projects whose issues are on the board; empty: the project of the board */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $projectIds = [];

    #[ORM\Column]
    public bool $includeSubprojects = false;

    /** @var list<int> only these trackers; empty: all */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $trackerIds = [];

    /** @var list<int> statuses deliberately left off the board */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $hiddenStatusIds = [];

    /** @var array<string, mixed> see DEFAULT_OPTIONS */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $options = [];

    #[ORM\Column]
    public int $position = 1;

    #[ORM\Column]
    public \DateTimeImmutable $createdOn;

    #[ORM\Column]
    public \DateTimeImmutable $updatedOn;

    /** @var Collection<int, BoardColumn> */
    #[ORM\OneToMany(targetEntity: BoardColumn::class, mappedBy: 'board', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    public Collection $columns;

    public function __construct()
    {
        $this->columns = new ArrayCollection();
        $this->createdOn = $this->updatedOn = new \DateTimeImmutable();
    }

    public function option(string $name): mixed
    {
        return ($this->options ?? [])[$name] ?? self::DEFAULT_OPTIONS[$name] ?? null;
    }

    /** @return array<string, mixed> every option with its value */
    public function allOptions(): array
    {
        return array_intersect_key(($this->options ?? []) + self::DEFAULT_OPTIONS, self::DEFAULT_OPTIONS);
    }

    public function isPrivate(): bool
    {
        return self::VISIBILITY_PRIVATE === $this->visibility;
    }

    /** @return list<int> */
    public function hiddenStatusIds(): array
    {
        return array_values(array_map('intval', $this->hiddenStatusIds ?? []));
    }

    /** @return array<int, BoardColumn> status id => the column showing it (the first one when a status is in several) */
    public function columnsByStatus(): array
    {
        $map = [];
        foreach ($this->columns as $column) {
            foreach ($column->statusIds() as $statusId) {
                $map[$statusId] ??= $column;
            }
        }

        return $map;
    }

    public function column(int $id): ?BoardColumn
    {
        foreach ($this->columns as $column) {
            if ($column->id === $id) {
                return $column;
            }
        }

        return null;
    }

    public function touch(): void
    {
        $this->updatedOn = new \DateTimeImmutable();
    }
}

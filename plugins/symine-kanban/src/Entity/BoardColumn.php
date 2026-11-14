<?php

declare(strict_types=1);

namespace Symine\Kanban\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A column of a board: the issue statuses it shows, in order of preference when a card is dropped on it */
#[ORM\Entity]
#[ORM\Table(name: 'kanban_columns')]
class BoardColumn
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Board::class, inversedBy: 'columns')]
    #[ORM\JoinColumn(nullable: false)]
    public Board $board;

    #[ORM\Column(length: 255)]
    public string $name = '';

    #[ORM\Column]
    public int $position = 1;

    /** Work-in-progress limit: the column is flagged when it holds more cards; null: no limit */
    #[ORM\Column(nullable: true)]
    public ?int $wipLimit = null;

    /** @var list<int> */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $statusIds = [];

    /** @return list<int> */
    public function statusIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->statusIds ?? [])));
    }
}

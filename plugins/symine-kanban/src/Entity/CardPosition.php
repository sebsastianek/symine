<?php

declare(strict_types=1);

namespace Symine\Kanban\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Where somebody put a card on a board: cards are ordered by position, then the ones never moved, oldest first */
#[ORM\Entity]
#[ORM\Table(name: 'kanban_card_positions')]
#[ORM\UniqueConstraint(name: 'index_kanban_card_positions_on_board_and_issue', columns: ['board_id', 'issue_id'])]
class CardPosition
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public int $boardId = 0;

    #[ORM\Column]
    public int $issueId = 0;

    #[ORM\Column]
    public int $position = 0;
}

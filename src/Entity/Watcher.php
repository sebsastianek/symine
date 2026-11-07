<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Polymorphic watcher (watchable_type: Issue, WikiPage, Wiki, Board, Message, News) */
#[ORM\Entity]
#[ORM\Table(name: 'watchers')]
#[ORM\Index(name: 'watchers_user_id_type', columns: ['user_id', 'watchable_type'])]
#[ORM\Index(name: 'index_watchers_on_user_id', columns: ['user_id'])]
#[ORM\Index(name: 'index_watchers_on_watchable_id_and_watchable_type', columns: ['watchable_id', 'watchable_type'])]
class Watcher
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $watchableType = '';

    #[ORM\Column(options: ['default' => 0])]
    public int $watchableId = 0;

    #[ORM\ManyToOne(targetEntity: Principal::class)]
    public ?Principal $user = null;
}

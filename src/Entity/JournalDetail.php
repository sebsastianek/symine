<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** property: attr | cf | attachment | relation */
#[ORM\Entity]
#[ORM\Table(name: 'journal_details')]
#[ORM\Index(name: 'journal_details_journal_id', columns: ['journal_id'])]
class JournalDetail
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Journal::class, inversedBy: 'details')]
    #[ORM\JoinColumn(nullable: false)]
    public Journal $journal;

    #[ORM\Column(length: 30, options: ['default' => ''])]
    public string $property = '';

    #[ORM\Column(length: 30, options: ['default' => ''])]
    public string $propKey = '';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $oldValue = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $value = null;
}

<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'import_items')]
#[ORM\Index(name: 'index_import_items_on_import_id_and_unique_id', columns: ['import_id', 'unique_id'])]
class ImportItem
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Import::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Import $import;

    #[ORM\Column]
    public int $position = 0;

    #[ORM\Column(nullable: true)]
    public ?int $objId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $message = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $uniqueId = null;
}

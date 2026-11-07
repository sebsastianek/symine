<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'settings')]
#[ORM\Index(name: 'index_settings_on_name', columns: ['name'])]
class Setting
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $name = '';

    #[ORM\Column(type: 'redmine_serialized', nullable: true)]
    public mixed $value = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $updatedOn = null;
}

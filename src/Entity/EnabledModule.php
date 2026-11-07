<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'enabled_modules')]
#[ORM\Index(name: 'enabled_modules_project_id', columns: ['project_id'])]
class EnabledModule
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'enabledModules')]
    public ?Project $project = null;

    #[ORM\Column(length: 255)]
    public string $name = '';
}

<?php

declare(strict_types=1);

namespace Symine\Hello\Entity;

use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/** A greeting posted in a project (table created by the plugin migration) */
#[ORM\Entity]
#[ORM\Table(name: 'hello_greetings')]
class Greeting
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Project $project;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public User $author;

    #[ORM\Column(length: 255)]
    public string $message = '';

    #[ORM\Column]
    public \DateTimeImmutable $createdOn;

    public function __construct()
    {
        $this->createdOn = new \DateTimeImmutable();
    }
}

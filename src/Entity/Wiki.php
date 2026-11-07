<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wikis')]
#[ORM\Index(name: 'wikis_project_id', columns: ['project_id'])]
class Wiki
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Project $project;

    #[ORM\Column(length: 255)]
    public string $startPage = 'Wiki';

    #[ORM\Column(options: ['default' => 1])]
    public int $status = 1;

    /** Same title normalisation as Redmine's Wiki.titleize */
    public static function titleize(string $title): string
    {
        $title = trim($title);
        $title = preg_replace('/[\s_]+/u', '_', $title) ?? $title;
        $title = str_replace([',', '.', '/', '?', ';', '|', ':'], '', $title);
        if ('' !== $title) {
            $title = mb_strtoupper(mb_substr($title, 0, 1)).mb_substr($title, 1);
        }

        return $title;
    }
}

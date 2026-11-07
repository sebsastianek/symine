<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'projects_webhooks')]
#[ORM\Index(name: 'index_projects_webhooks_on_project_id', columns: ['project_id'])]
#[ORM\Index(name: 'index_projects_webhooks_on_webhook_id', columns: ['webhook_id'])]
class ProjectWebhook
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Project $project;

    #[ORM\ManyToOne(targetEntity: Webhook::class, inversedBy: 'projectLinks')]
    #[ORM\JoinColumn(nullable: false)]
    public Webhook $webhook;
}

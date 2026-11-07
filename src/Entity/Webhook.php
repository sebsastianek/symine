<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'webhooks')]
#[ORM\Index(name: 'index_webhooks_on_user_id', columns: ['user_id'])]
#[ORM\Index(name: 'index_webhooks_on_active', columns: ['active'])]
class Webhook
{
    public const array EVENTS = ['issue.created', 'issue.updated', 'issue.deleted', 'wiki_page.created', 'wiki_page.updated', 'wiki_page.deleted', 'time_entry.created', 'time_entry.updated', 'time_entry.deleted', 'news.created', 'news.updated', 'news.deleted', 'version.created', 'version.updated', 'version.deleted'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 2000)]
    public string $url = '';

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $secret = null;

    #[ORM\Column(type: 'redmine_serialized', nullable: true)]
    public mixed $events = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public User $user;

    #[ORM\Column(options: ['default' => false])]
    public bool $active = false;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ProjectWebhook> */
    #[ORM\OneToMany(targetEntity: ProjectWebhook::class, mappedBy: 'webhook', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $projectLinks;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->projectLinks = new ArrayCollection();
    }

    /** @return list<string> */
    public function getEvents(): array
    {
        return \is_array($this->events) ? array_values($this->events) : [];
    }
}

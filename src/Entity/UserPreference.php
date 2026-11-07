<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_preferences')]
#[ORM\Index(name: 'index_user_preferences_on_user_id', columns: ['user_id'])]
class UserPreference
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'preference')]
    #[ORM\JoinColumn(nullable: false)]
    public User $user;

    /** Redmine "others" hash: comments_sorting, no_self_notified, my_page_layout, textarea_font, ... */
    #[ORM\Column(type: 'redmine_serialized', nullable: true)]
    public mixed $others = null;

    #[ORM\Column(nullable: true, options: ['default' => true])]
    public ?bool $hideMail = true;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $timeZone = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return \is_array($this->others) ? ($this->others[$key] ?? $default) : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $others = \is_array($this->others) ? $this->others : [];
        $others[$key] = $value;
        $this->others = $others;
    }

    public function commentsSorting(): string
    {
        return (string) $this->get('comments_sorting', 'asc');
    }

    public function noSelfNotified(): bool
    {
        return \in_array($this->get('no_self_notified', true), [true, '1', 1], true);
    }
}

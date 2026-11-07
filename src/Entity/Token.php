<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Tokens for api keys, feeds, autologin, password recovery, registration, email changes. */
#[ORM\Entity(repositoryClass: \App\Repository\TokenRepository::class)]
#[ORM\Table(name: 'tokens')]
#[ORM\Index(name: 'index_tokens_on_user_id', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'tokens_value', columns: ['value'])]
class Token
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public User $user;

    #[ORM\Column(length: 30, options: ['default' => ''])]
    public string $action = '';

    #[ORM\Column(length: 40, options: ['default' => ''])]
    public string $value = '';

    #[ORM\Column]
    public \DateTimeImmutable $createdOn;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $updatedOn = null;

    public function __construct(?User $user = null, string $action = '')
    {
        if ($user) {
            $this->user = $user;
        }
        $this->action = $action;
        $this->value = bin2hex(random_bytes(20));
        $this->createdOn = $this->updatedOn = new \DateTimeImmutable();
    }
}

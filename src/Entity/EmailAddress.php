<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'email_addresses')]
#[ORM\Index(name: 'index_email_addresses_on_user_id', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class EmailAddress
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'emailAddresses')]
    #[ORM\JoinColumn(nullable: false)]
    public User $user;

    #[ORM\Column(length: 255)]
    public string $address = '';

    #[ORM\Column(options: ['default' => false])]
    public bool $isDefault = false;

    #[ORM\Column(options: ['default' => true])]
    public bool $notify = true;

    #[ORM\Column]
    public \DateTimeImmutable $createdOn;

    #[ORM\Column]
    public \DateTimeImmutable $updatedOn;

    public function __construct()
    {
        $this->createdOn = $this->updatedOn = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedOn = new \DateTimeImmutable();
    }
}

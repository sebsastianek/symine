<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PrincipalRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the Redmine "users" table (single table inheritance on the "type" column).
 */
#[ORM\Entity(repositoryClass: PrincipalRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\Index(name: 'index_users_on_id_and_type', columns: ['id', 'type'])]
#[ORM\Index(name: 'index_users_on_type', columns: ['type'])]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[ORM\DiscriminatorMap([
    'User' => User::class,
    'AnonymousUser' => AnonymousUser::class,
    'Group' => Group::class,
    'GroupAnonymous' => GroupAnonymous::class,
    'GroupNonMember' => GroupNonMember::class,
])]
#[ORM\HasLifecycleCallbacks]
abstract class Principal implements \Stringable
{
    public const int STATUS_ANONYMOUS = 0;
    public const int STATUS_ACTIVE = 1;
    public const int STATUS_REGISTERED = 2;
    public const int STATUS_LOCKED = 3;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $login = '';

    /** Legacy Redmine hashes are SHA1 (40 chars); Symine re-hashes to bcrypt/argon on login. */
    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $hashedPassword = '';

    #[ORM\Column(length: 30, options: ['default' => ''])]
    public string $firstname = '';

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $lastname = '';

    #[ORM\Column(options: ['default' => false])]
    public bool $admin = false;

    #[ORM\Column(options: ['default' => 1])]
    public int $status = self::STATUS_ACTIVE;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastLoginOn = null;

    #[ORM\Column(length: 5, nullable: true, options: ['default' => ''])]
    public ?string $language = '';

    #[ORM\Column(nullable: true)]
    public ?int $authSourceId = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $createdOn = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $updatedOn = null;

    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $mailNotification = '';

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $salt = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $mustChangePasswd = false;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $passwdChangedOn = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $twofaScheme = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $twofaTotpKey = null;

    #[ORM\Column(nullable: true)]
    public ?int $twofaTotpLastUsedAt = null;

    #[ORM\Column(nullable: true, options: ['default' => false])]
    public ?bool $twofaRequired = false;

    /** @var Collection<int, Member> */
    #[ORM\OneToMany(targetEntity: Member::class, mappedBy: 'principal')]
    public Collection $memberships;

    public function __construct()
    {
        $this->memberships = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdOn ??= $now;
        $this->updatedOn = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedOn = new \DateTimeImmutable();
    }

    abstract public function getName(?string $format = null): string;

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    public function isLocked(): bool
    {
        return self::STATUS_LOCKED === $this->status;
    }

    public function isRegistered(): bool
    {
        return self::STATUS_REGISTERED === $this->status;
    }

    public function isGroup(): bool
    {
        return $this instanceof Group;
    }

    public function __toString(): string
    {
        return $this->getName();
    }
}

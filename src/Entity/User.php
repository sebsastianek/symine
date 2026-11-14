<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\LegacyPasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: \App\Repository\UserRepository::class)]
class User extends Principal implements UserInterface, LegacyPasswordAuthenticatedUserInterface
{
    /** Same formats as Redmine's User::USER_FORMATS */
    public const array USER_FORMATS = [
        'firstname_lastname' => '{firstname} {lastname}',
        'firstname_lastinitial' => '{firstname} {lastinitial}',
        'firstinitial_lastname' => '{firstinitial}. {lastname}',
        'firstname' => '{firstname}',
        'lastname_firstname' => '{lastname} {firstname}',
        'lastnamefirstname' => '{lastname}{firstname}',
        'lastname_comma_firstname' => '{lastname}, {firstname}',
        'lastname' => '{lastname}',
        'username' => '{login}',
    ];

    public const array MAIL_NOTIFICATION_OPTIONS = ['all', 'selected', 'only_my_events', 'only_assigned', 'only_owner', 'none'];

    /** Format of getName() without argument: the "user_format" setting, applied by App\Service\Admin\UserNameFormatListener */
    public static string $defaultNameFormat = 'firstname_lastname';

    /** @var Collection<int, EmailAddress> */
    #[ORM\OneToMany(targetEntity: EmailAddress::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $emailAddresses;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class, mappedBy: 'users')]
    public Collection $groups;

    #[ORM\OneToOne(targetEntity: UserPreference::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    public ?UserPreference $preference = null;

    public function __construct()
    {
        parent::__construct();
        $this->emailAddresses = new ArrayCollection();
        $this->groups = new ArrayCollection();
        $this->mailNotification = 'only_my_events';
    }

    public function getName(?string $format = null): string
    {
        $format ??= self::$defaultNameFormat;
        $pattern = self::USER_FORMATS[$format] ?? self::USER_FORMATS['firstname_lastname'];

        return trim(strtr($pattern, [
            '{firstname}' => $this->firstname,
            '{lastname}' => $this->lastname,
            '{login}' => $this->login,
            '{firstinitial}' => mb_substr($this->firstname, 0, 1),
            '{lastinitial}' => mb_substr($this->lastname, 0, 1),
        ]));
    }

    public function isLogged(): bool
    {
        return true;
    }

    public function isAnonymous(): bool
    {
        return !$this->isLogged();
    }

    public function getMail(): ?string
    {
        foreach ($this->emailAddresses as $address) {
            if ($address->isDefault) {
                return $address->address;
            }
        }

        return null;
    }

    public function setMail(string $mail): void
    {
        foreach ($this->emailAddresses as $address) {
            if ($address->isDefault) {
                $address->address = $mail;

                return;
            }
        }
        $address = new EmailAddress();
        $address->user = $this;
        $address->address = $mail;
        $address->isDefault = true;
        $this->emailAddresses->add($address);
    }

    public function getPref(): UserPreference
    {
        if (null === $this->preference) {
            $this->preference = new UserPreference();
            $this->preference->user = $this;
        }

        return $this->preference;
    }

    /** @return list<int> */
    public function getGroupIds(): array
    {
        return array_values(array_map(static fn (Group $g) => (int) $g->id, $this->groups->toArray()));
    }

    public function isOrBelongsTo(?Principal $principal): bool
    {
        if (null === $principal) {
            return false;
        }
        if ($principal->id === $this->id) {
            return true;
        }

        return $principal instanceof Group && \in_array($principal->id, $this->getGroupIds(), true);
    }

    // --- Symfony security ---------------------------------------------------

    public function getUserIdentifier(): string
    {
        return '' !== $this->login ? $this->login : 'anonymous';
    }

    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        if ($this->admin) {
            $roles[] = 'ROLE_ADMIN';
        }

        return $roles;
    }

    public function getPassword(): ?string
    {
        return '' !== $this->hashedPassword ? $this->hashedPassword : null;
    }

    /** Only legacy (Redmine SHA1) hashes use the salt column. */
    public function getSalt(): ?string
    {
        return 40 === \strlen($this->hashedPassword) && ctype_xdigit($this->hashedPassword) ? $this->salt : null;
    }

    public function eraseCredentials(): void
    {
    }

    public function __serialize(): array
    {
        return ['id' => $this->id, 'login' => $this->login, 'hashedPassword' => $this->hashedPassword, 'salt' => $this->salt, 'status' => $this->status];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->login = $data['login'];
        $this->hashedPassword = $data['hashedPassword'];
        $this->status = $data['status'];
        $this->salt = $data['salt'] ?? null;
    }
}

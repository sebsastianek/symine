<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** A group of users. The group name is stored in the "lastname" column like in Redmine. */
#[ORM\Entity]
class Group extends Principal
{
    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'groups')]
    #[ORM\JoinTable(name: 'groups_users')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id')]
    public Collection $users;

    public function __construct()
    {
        parent::__construct();
        $this->users = new ArrayCollection();
    }

    public function getName(?string $format = null): string
    {
        return $this->lastname;
    }

    public function setName(string $name): void
    {
        $this->lastname = $name;
    }

    public function isBuiltin(): bool
    {
        return false;
    }
}

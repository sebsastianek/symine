<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\GroupsUserRepository;

/**
 * GroupsUser.
 * Table: groups_users
 */
#[ORM\Entity(repositoryClass: GroupsUserRepository::class)]
#[ORM\Table(name: 'groups_users')]
class GroupsUser
{
    /**
     * Property group (references User entity since groups are also users in Redmine)
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id', nullable: false)]
    private User $group;

    /**
     * Property user
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    /**
     * Getter for group
     */
    public function getGroup(): User
    {
        return $this->group;
    }

    /**
     * Setter for group
     */
    public function setGroup(User $group): static
    {
        $this->group = $group;
        return $this;
    }

    /**
     * Getter for user
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * Setter for user
     */
    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

}

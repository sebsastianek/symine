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
     * Property group
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Group::class, inversedBy: 'groupsUsers')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id', nullable: false)]
    private Group $group;

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
    public function getGroup(): Group
    {
        return $this->group;
    }

    /**
     * Setter for group
     */
    public function setGroup(Group $group): static
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

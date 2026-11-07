<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\MemberRepository::class)]
#[ORM\Table(name: 'members')]
#[ORM\Index(name: 'index_members_on_project_id', columns: ['project_id'])]
#[ORM\Index(name: 'index_members_on_user_id', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'index_members_on_user_id_and_project_id', columns: ['user_id', 'project_id'])]
class Member
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Principal::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    public Principal $principal;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false)]
    public Project $project;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $createdOn = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $mailNotification = false;

    /** @var Collection<int, MemberRole> */
    #[ORM\OneToMany(targetEntity: MemberRole::class, mappedBy: 'member', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $memberRoles;

    public function __construct()
    {
        $this->memberRoles = new ArrayCollection();
        $this->createdOn = new \DateTimeImmutable();
    }

    /** @return list<Role> */
    public function getRoles(): array
    {
        $roles = [];
        foreach ($this->memberRoles as $mr) {
            $roles[$mr->role->id] = $mr->role;
        }
        uasort($roles, static fn (Role $a, Role $b) => $a->position <=> $b->position);

        return array_values($roles);
    }

    public function hasRole(Role $role): bool
    {
        foreach ($this->memberRoles as $mr) {
            if ($mr->role->id === $role->id) {
                return true;
            }
        }

        return false;
    }

    public function addRole(Role $role, ?int $inheritedFrom = null): MemberRole
    {
        $mr = new MemberRole();
        $mr->member = $this;
        $mr->role = $role;
        $mr->inheritedFrom = $inheritedFrom;
        $this->memberRoles->add($mr);

        return $mr;
    }

    public function isDeletable(): bool
    {
        foreach ($this->memberRoles as $mr) {
            if (null === $mr->inheritedFrom) {
                return true;
            }
        }

        return false;
    }
}

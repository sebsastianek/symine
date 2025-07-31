<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\MemberRoleRepository;

/**
 * MemberRole.
 * Table: member_roles
 */
#[ORM\Entity(repositoryClass: MemberRoleRepository::class)]
#[ORM\Table(name: 'member_roles')]
class MemberRole
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property member
     */
    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(name: 'member_id', referencedColumnName: 'id', nullable: false)]
    private Member $member;

    /**
     * Property role
     */
    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false)]
    private Role $role;

    /**
     * Property inheritedFrom
     */
    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(name: 'inherited_from', referencedColumnName: 'id', nullable: true)]
    private ?Member $inheritedFrom = null;

    /**
     * Getter for id
     */
    public function getId(): int    {
        return $this->id;
    }

    /**
     * Setter for id
     */
    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Getter for member
     */
    public function getMember(): Member
    {
        return $this->member;
    }

    /**
     * Setter for member
     */
    public function setMember(Member $member): static
    {
        $this->member = $member;
        return $this;
    }

    /**
     * Getter for role
     */
    public function getRole(): Role
    {
        return $this->role;
    }

    /**
     * Setter for role
     */
    public function setRole(Role $role): static
    {
        $this->role = $role;
        return $this;
    }

    /**
     * Getter for inheritedFrom
     */
    public function getInheritedFrom(): ?Member
    {
        return $this->inheritedFrom;
    }

    /**
     * Setter for inheritedFrom
     */
    public function setInheritedFrom(?Member $inheritedFrom): static
    {
        $this->inheritedFrom = $inheritedFrom;
        return $this;
    }

}

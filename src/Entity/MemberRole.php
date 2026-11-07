<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'member_roles')]
#[ORM\Index(name: 'index_member_roles_on_member_id', columns: ['member_id'])]
#[ORM\Index(name: 'index_member_roles_on_role_id', columns: ['role_id'])]
#[ORM\Index(name: 'index_member_roles_on_inherited_from', columns: ['inherited_from'])]
class MemberRole
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Member::class, inversedBy: 'memberRoles')]
    #[ORM\JoinColumn(nullable: false)]
    public Member $member;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Role $role;

    /** id of the MemberRole (group membership or parent project) this one is inherited from */
    #[ORM\Column(nullable: true)]
    public ?int $inheritedFrom = null;
}

<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\RolesManagedRoleRepository;

/**
 * RolesManagedRole.
 * Table: roles_managed_roles
 */
#[ORM\Entity(repositoryClass: RolesManagedRoleRepository::class)]
#[ORM\Table(name: 'roles_managed_roles')]
class RolesManagedRole
{
    /**
     * Property role
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false)]
    private Role $role;

    /**
     * Property managedRole
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'managed_role_id', referencedColumnName: 'id', nullable: false)]
    private Role $managedRole;

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
     * Getter for managedRole
     */
    public function getManagedRole(): Role
    {
        return $this->managedRole;
    }

    /**
     * Setter for managedRole
     */
    public function setManagedRole(Role $managedRole): static
    {
        $this->managedRole = $managedRole;
        return $this;
    }

}

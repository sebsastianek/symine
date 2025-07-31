<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\CustomFieldsRoleRepository;

/**
 * CustomFieldsRole.
 * Table: custom_fields_roles
 */
#[ORM\Entity(repositoryClass: CustomFieldsRoleRepository::class)]
#[ORM\Table(name: 'custom_fields_roles')]
class CustomFieldsRole
{
    /**
     * Property customField
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: CustomField::class)]
    #[ORM\JoinColumn(name: 'custom_field_id', referencedColumnName: 'id', nullable: false)]
    private CustomField $customField;

    /**
     * Property role
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false)]
    private Role $role;

    /**
     * Getter for customField
     */
    public function getCustomField(): CustomField
    {
        return $this->customField;
    }

    /**
     * Setter for customField
     */
    public function setCustomField(CustomField $customField): static
    {
        $this->customField = $customField;
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

}

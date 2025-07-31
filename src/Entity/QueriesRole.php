<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\QueriesRoleRepository;

/**
 * QueriesRole.
 * Table: queries_roles
 */
#[ORM\Entity(repositoryClass: QueriesRoleRepository::class)]
#[ORM\Table(name: 'queries_roles')]
class QueriesRole
{
    /**
     * Property query
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Query::class)]
    #[ORM\JoinColumn(name: 'query_id', referencedColumnName: 'id', nullable: false)]
    private Query $query;

    /**
     * Property role
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false)]
    private Role $role;

    /**
     * Getter for query
     */
    public function getQuery(): Query
    {
        return $this->query;
    }

    /**
     * Setter for query
     */
    public function setQuery(Query $query): static
    {
        $this->query = $query;
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

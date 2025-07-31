<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\ChangesetParentRepository;

/**
 * ChangesetParent.
 * Table: changeset_parents
 */
#[ORM\Entity(repositoryClass: ChangesetParentRepository::class)]
#[ORM\Table(name: 'changeset_parents')]
class ChangesetParent
{
    /**
     * Property changeset
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Changeset::class)]
    #[ORM\JoinColumn(name: 'changeset_id', referencedColumnName: 'id', nullable: false)]
    private Changeset $changeset;

    /**
     * Property parent
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Changeset::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: false)]
    private Changeset $parent;

    /**
     * Getter for changeset
     */
    public function getChangeset(): Changeset
    {
        return $this->changeset;
    }

    /**
     * Setter for changeset
     */
    public function setChangeset(Changeset $changeset): static
    {
        $this->changeset = $changeset;
        return $this;
    }

    /**
     * Getter for parent
     */
    public function getParent(): Changeset
    {
        return $this->parent;
    }

    /**
     * Setter for parent
     */
    public function setParent(Changeset $parent): static
    {
        $this->parent = $parent;
        return $this;
    }

}

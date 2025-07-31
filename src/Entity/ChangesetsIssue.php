<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\ChangesetsIssueRepository;

/**
 * ChangesetsIssue.
 * Table: changesets_issues
 */
#[ORM\Entity(repositoryClass: ChangesetsIssueRepository::class)]
#[ORM\Table(name: 'changesets_issues')]
class ChangesetsIssue
{
    /**
     * Property changeset
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Changeset::class)]
    #[ORM\JoinColumn(name: 'changeset_id', referencedColumnName: 'id', nullable: false)]
    private Changeset $changeset;

    /**
     * Property issue
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Issue::class)]
    #[ORM\JoinColumn(name: 'issue_id', referencedColumnName: 'id', nullable: false)]
    private Issue $issue;

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
     * Getter for issue
     */
    public function getIssue(): Issue
    {
        return $this->issue;
    }

    /**
     * Setter for issue
     */
    public function setIssue(Issue $issue): static
    {
        $this->issue = $issue;
        return $this;
    }

}

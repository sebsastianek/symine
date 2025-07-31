<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\IssueRelationRepository;

/**
 * IssueRelation.
 * Table: issue_relations
 */
#[ORM\Entity(repositoryClass: IssueRelationRepository::class)]
#[ORM\Table(name: 'issue_relations')]
class IssueRelation
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property issueFrom
     */
    #[ORM\ManyToOne(targetEntity: Issue::class, inversedBy: 'relationsFrom')]
    #[ORM\JoinColumn(name: 'issue_from_id', referencedColumnName: 'id', nullable: false)]
    private Issue $issueFrom;

    /**
     * Property issueTo
     */
    #[ORM\ManyToOne(targetEntity: Issue::class, inversedBy: 'relationsTo')]
    #[ORM\JoinColumn(name: 'issue_to_id', referencedColumnName: 'id', nullable: false)]
    private Issue $issueTo;

    /**
     * Property relationType
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $relationType = '';

    /**
     * Property delay
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $delay = NULL;

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
     * Getter for issueFrom
     */
    public function getIssueFrom(): Issue
    {
        return $this->issueFrom;
    }

    /**
     * Setter for issueFrom
     */
    public function setIssueFrom(Issue $issueFrom): static
    {
        $this->issueFrom = $issueFrom;
        return $this;
    }

    /**
     * Getter for issueTo
     */
    public function getIssueTo(): Issue
    {
        return $this->issueTo;
    }

    /**
     * Setter for issueTo
     */
    public function setIssueTo(Issue $issueTo): static
    {
        $this->issueTo = $issueTo;
        return $this;
    }

    /**
     * Getter for relationType
     */
    public function getRelationType(): string    {
        return $this->relationType;
    }

    /**
     * Setter for relationType
     */
    public function setRelationType(string $relationType): static
    {
        $this->relationType = $relationType;
        return $this;
    }

    /**
     * Getter for delay
     */
    public function getDelay(): ?int    {
        return $this->delay;
    }

    /**
     * Setter for delay
     */
    public function setDelay(?int $delay): static
    {
        $this->delay = $delay;
        return $this;
    }

}

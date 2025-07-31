<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\JournalDetailRepository;

/**
 * JournalDetail.
 * Table: journal_details
 */
#[ORM\Entity(repositoryClass: JournalDetailRepository::class)]
#[ORM\Table(name: 'journal_details')]
class JournalDetail
{
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property journal
     */
    #[ORM\ManyToOne(targetEntity: Journal::class)]
    #[ORM\JoinColumn(name: 'journal_id', referencedColumnName: 'id', nullable: false)]
    private Journal $journal;

    /**
     * Property property
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => ''])]
    private string $property = '';

    /**
     * Property propKey
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => ''])]
    private string $propKey = '';

    /**
     * Property oldValue
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $oldValue = NULL;

    /**
     * Property value
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = NULL;

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
     * Getter for journal
     */
    public function getJournal(): Journal
    {
        return $this->journal;
    }

    /**
     * Setter for journal
     */
    public function setJournal(Journal $journal): static
    {
        $this->journal = $journal;
        return $this;
    }

    /**
     * Getter for property
     */
    public function getProperty(): string    {
        return $this->property;
    }

    /**
     * Setter for property
     */
    public function setProperty(string $property): static
    {
        $this->property = $property;
        return $this;
    }

    /**
     * Getter for propKey
     */
    public function getPropKey(): string    {
        return $this->propKey;
    }

    /**
     * Setter for propKey
     */
    public function setPropKey(string $propKey): static
    {
        $this->propKey = $propKey;
        return $this;
    }

    /**
     * Getter for oldValue
     */
    public function getOldValue(): ?string    {
        return $this->oldValue;
    }

    /**
     * Setter for oldValue
     */
    public function setOldValue(?string $oldValue): static
    {
        $this->oldValue = $oldValue;
        return $this;
    }

    /**
     * Getter for value
     */
    public function getValue(): ?string    {
        return $this->value;
    }

    /**
     * Setter for value
     */
    public function setValue(?string $value): static
    {
        $this->value = $value;
        return $this;
    }

}

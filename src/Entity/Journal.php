<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Issue history entry (notes and attribute changes). */
#[ORM\Entity(repositoryClass: \App\Repository\JournalRepository::class)]
#[ORM\Table(name: 'journals')]
#[ORM\Index(name: 'journals_journalized_id', columns: ['journalized_id', 'journalized_type'])]
#[ORM\Index(name: 'index_journals_on_user_id', columns: ['user_id'])]
#[ORM\Index(name: 'index_journals_on_journalized_id', columns: ['journalized_id'])]
#[ORM\Index(name: 'index_journals_on_created_on', columns: ['created_on'])]
class Journal
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Issue::class, inversedBy: 'journals')]
    #[ORM\JoinColumn(name: 'journalized_id', nullable: false)]
    public Issue $issue;

    #[ORM\Column(length: 30, options: ['default' => ''])]
    public string $journalizedType = 'Issue';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public User $user;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $notes = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdOn;

    #[ORM\Column(options: ['default' => false])]
    public bool $privateNotes = false;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $updatedOn = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    public ?User $updatedBy = null;

    /** @var Collection<int, JournalDetail> */
    #[ORM\OneToMany(targetEntity: JournalDetail::class, mappedBy: 'journal', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $details;

    /** Transient: position of the journal in the issue history (1-based) */
    public ?int $indice = null;

    public function __construct()
    {
        $this->details = new ArrayCollection();
        $this->createdOn = new \DateTimeImmutable();
        $this->updatedOn = $this->createdOn;
    }

    public function addDetail(string $property, string $propKey, mixed $oldValue, mixed $value): JournalDetail
    {
        $d = new JournalDetail();
        $d->journal = $this;
        $d->property = $property;
        $d->propKey = $propKey;
        $d->oldValue = null === $oldValue ? null : (string) $oldValue;
        $d->value = null === $value ? null : (string) $value;
        $this->details->add($d);

        return $d;
    }

    public function hasNotes(): bool
    {
        return null !== $this->notes && '' !== trim($this->notes);
    }

    public function isEmpty(): bool
    {
        return !$this->hasNotes() && 0 === $this->details->count();
    }
}

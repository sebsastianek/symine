<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\TrackerRepository::class)]
#[ORM\Table(name: 'trackers')]
class Tracker implements \Stringable
{
    public const array CORE_FIELDS_UNDISABLABLE = ['project_id', 'tracker_id', 'subject', 'is_private'];
    /** Order matters: bit index in fields_bits (same as Redmine) */
    public const array CORE_FIELDS = ['assigned_to_id', 'category_id', 'fixed_version_id', 'parent_issue_id',
        'start_date', 'due_date', 'estimated_hours', 'done_ratio', 'description', 'priority_id'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 30, options: ['default' => ''])]
    public string $name = '';

    #[ORM\Column(nullable: true)]
    public ?int $position = null;

    #[ORM\Column(options: ['default' => true])]
    public bool $isInRoadmap = true;

    #[ORM\Column(nullable: true, options: ['default' => 0])]
    public ?int $fieldsBits = 0;

    #[ORM\ManyToOne(targetEntity: IssueStatus::class)]
    public ?IssueStatus $defaultStatus = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $description = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $privateByDefault = false;

    /** @var Collection<int, Project> */
    #[ORM\ManyToMany(targetEntity: Project::class, mappedBy: 'trackers')]
    public Collection $projects;

    /** @var Collection<int, IssueCustomField> */
    #[ORM\ManyToMany(targetEntity: IssueCustomField::class, mappedBy: 'trackers')]
    public Collection $customFields;

    public function __construct()
    {
        $this->projects = new ArrayCollection();
        $this->customFields = new ArrayCollection();
    }

    /** @return list<string> */
    public function getDisabledCoreFields(): array
    {
        $out = [];
        foreach (self::CORE_FIELDS as $i => $field) {
            if ((($this->fieldsBits ?? 0) & (1 << $i)) !== 0) {
                $out[] = $field;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function getCoreFields(): array
    {
        return array_values(array_diff(self::CORE_FIELDS, $this->getDisabledCoreFields()));
    }

    /** @param list<string> $fields */
    public function setCoreFields(array $fields): void
    {
        $bits = 0;
        foreach (self::CORE_FIELDS as $i => $field) {
            if (!\in_array($field, $fields, true)) {
                $bits |= 1 << $i;
            }
        }
        $this->fieldsBits = $bits;
    }

    public function isCoreFieldEnabled(string $field): bool
    {
        return !\in_array($field, $this->getDisabledCoreFields(), true);
    }

    public function __toString(): string
    {
        return $this->name;
    }
}

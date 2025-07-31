<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\CustomFieldsTrackerRepository;

/**
 * CustomFieldsTracker.
 * Table: custom_fields_trackers
 */
#[ORM\Entity(repositoryClass: CustomFieldsTrackerRepository::class)]
#[ORM\Table(name: 'custom_fields_trackers')]
class CustomFieldsTracker
{
    /**
     * Property customField
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: CustomField::class)]
    #[ORM\JoinColumn(name: 'custom_field_id', referencedColumnName: 'id', nullable: false)]
    private CustomField $customField;

    /**
     * Property tracker
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Tracker::class)]
    #[ORM\JoinColumn(name: 'tracker_id', referencedColumnName: 'id', nullable: false)]
    private Tracker $tracker;

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
     * Getter for tracker
     */
    public function getTracker(): Tracker
    {
        return $this->tracker;
    }

    /**
     * Setter for tracker
     */
    public function setTracker(Tracker $tracker): static
    {
        $this->tracker = $tracker;
        return $this;
    }

}

<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class IssueCustomField extends CustomField
{
    /** @var Collection<int, Tracker> */
    #[ORM\ManyToMany(targetEntity: Tracker::class, inversedBy: 'customFields')]
    #[ORM\JoinTable(name: 'custom_fields_trackers')]
    #[ORM\JoinColumn(name: 'custom_field_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'tracker_id', referencedColumnName: 'id')]
    public Collection $trackers;

    /** @var Collection<int, Project> */
    #[ORM\ManyToMany(targetEntity: Project::class, mappedBy: 'issueCustomFields')]
    public Collection $projects;

    public function __construct()
    {
        parent::__construct();
        $this->trackers = new ArrayCollection();
        $this->projects = new ArrayCollection();
    }

    public static function typeName(): string
    {
        return 'IssueCustomField';
    }

    public static function customizedType(): string
    {
        return 'Issue';
    }
}

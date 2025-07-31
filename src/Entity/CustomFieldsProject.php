<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\CustomFieldsProjectRepository;

/**
 * CustomFieldsProject.
 * Table: custom_fields_projects
 */
#[ORM\Entity(repositoryClass: CustomFieldsProjectRepository::class)]
#[ORM\Table(name: 'custom_fields_projects')]
class CustomFieldsProject
{
    /**
     * Property customField
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: CustomField::class)]
    #[ORM\JoinColumn(name: 'custom_field_id', referencedColumnName: 'id', nullable: false)]
    private CustomField $customField;

    /**
     * Property project
     */
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false)]
    private Project $project;

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
     * Getter for project
     */
    public function getProject(): Project
    {
        return $this->project;
    }

    /**
     * Setter for project
     */
    public function setProject(Project $project): static
    {
        $this->project = $project;
        return $this;
    }

}

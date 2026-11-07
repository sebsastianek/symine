<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Redmine "workflows" table: WorkflowTransition (status transitions) and WorkflowPermission (field rules). */
#[ORM\Entity(repositoryClass: \App\Repository\WorkflowRuleRepository::class)]
#[ORM\Table(name: 'workflows')]
#[ORM\Index(name: 'wkfs_role_tracker_old_status', columns: ['role_id', 'tracker_id', 'old_status_id'])]
#[ORM\Index(name: 'index_workflows_on_old_status_id', columns: ['old_status_id'])]
#[ORM\Index(name: 'index_workflows_on_role_id', columns: ['role_id'])]
#[ORM\Index(name: 'index_workflows_on_new_status_id', columns: ['new_status_id'])]
#[ORM\Index(name: 'index_workflows_on_tracker_id', columns: ['tracker_id'])]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string', length: 30)]
#[ORM\DiscriminatorMap(['WorkflowTransition' => WorkflowTransition::class, 'WorkflowPermission' => WorkflowPermission::class])]
abstract class WorkflowRule
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $trackerId = 0;

    /** 0 = new issue */
    #[ORM\Column(options: ['default' => 0])]
    public int $oldStatusId = 0;

    #[ORM\Column(options: ['default' => 0])]
    public int $newStatusId = 0;

    #[ORM\Column(options: ['default' => 0])]
    public int $roleId = 0;

    #[ORM\Column(options: ['default' => false])]
    public bool $assignee = false;

    #[ORM\Column(options: ['default' => false])]
    public bool $author = false;

    #[ORM\Column(length: 30, nullable: true)]
    public ?string $fieldName = null;

    #[ORM\Column(length: 30, nullable: true)]
    public ?string $rule = null;
}

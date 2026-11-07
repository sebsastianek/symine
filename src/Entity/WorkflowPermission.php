<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** rule: readonly | required (field_name: core field name or custom field id) */
#[ORM\Entity]
class WorkflowPermission extends WorkflowRule
{
    public const array RULES = ['readonly', 'required'];
}

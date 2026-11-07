<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ProjectCustomField extends CustomField
{
    public static function typeName(): string
    {
        return 'ProjectCustomField';
    }

    public static function customizedType(): string
    {
        return 'Project';
    }
}

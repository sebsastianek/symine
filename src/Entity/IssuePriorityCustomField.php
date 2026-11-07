<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class IssuePriorityCustomField extends CustomField
{
    public static function typeName(): string
    {
        return 'IssuePriorityCustomField';
    }

    public static function customizedType(): string
    {
        return 'Enumeration';
    }
}

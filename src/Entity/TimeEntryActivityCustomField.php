<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class TimeEntryActivityCustomField extends CustomField
{
    public static function typeName(): string
    {
        return 'TimeEntryActivityCustomField';
    }

    public static function customizedType(): string
    {
        return 'Enumeration';
    }
}

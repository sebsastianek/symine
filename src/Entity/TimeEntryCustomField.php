<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class TimeEntryCustomField extends CustomField
{
    public static function typeName(): string
    {
        return 'TimeEntryCustomField';
    }

    public static function customizedType(): string
    {
        return 'TimeEntry';
    }
}

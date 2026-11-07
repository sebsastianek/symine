<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class TimeEntryActivity extends Enumeration
{
    public static function typeName(): string
    {
        return 'TimeEntryActivity';
    }
}

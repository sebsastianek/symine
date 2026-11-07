<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class TimeEntryQuery extends Query
{
    public static function typeName(): string
    {
        return 'TimeEntryQuery';
    }
}

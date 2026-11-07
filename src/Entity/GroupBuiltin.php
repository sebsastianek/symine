<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
abstract class GroupBuiltin extends Group
{
    public function isBuiltin(): bool
    {
        return true;
    }
}

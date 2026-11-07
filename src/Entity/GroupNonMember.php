<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class GroupNonMember extends GroupBuiltin
{
    public function getName(?string $format = null): string
    {
        return 'Non member users';
    }
}
